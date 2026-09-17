<?php

/**
 * @file plugins/generic/whatsAppContributor/WhatsAppContributorPlugin.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class WhatsAppContributorPlugin
 *
 * @ingroup plugins_generic_whatsAppContributor
 *
 * @brief Adds a "Phone / WhatsApp" field (E.164) to the contributor form, and
 *        optionally to the user registration form, where it fills the phone
 *        of the account. The submitter's phone is carried to their authorship,
 *        as the core does with the ORCID iD.
 *
 *        Stored in author_settings (setting_name "whatsapp") for contributors
 *        and in the user's own "phone" for accounts. Shown in editorial forms
 *        only.
 */

namespace APP\plugins\generic\whatsAppContributor;

use APP\core\Application;
use PKP\components\forms\FieldText;
use PKP\core\JSONMessage;
use DOMDocument;
use DOMElement;
use PKP\form\Form;
use PKP\form\validation\FormValidatorCustom;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\template\PKPTemplateManager;

class WhatsAppContributorPlugin extends GenericPlugin
{
    /** E.164: "+", a country code that does not start with 0, up to 15 digits in total. */
    public const E164_PATTERN = '/^\+[1-9]\d{1,14}$/';

    /** The same, as the browser wants it in the pattern attribute of the field. */
    public const E164_HTML_PATTERN = '\+[1-9][0-9 .()\\-]{1,20}';

    /**
     * The text fields of the registration page the field is modelled on, from
     * the last of the personal data backwards: the one that is found gives the
     * markup of the theme and the place where the field goes.
     */
    public const MODEL_FIELDS = ['affiliation', 'familyName', 'givenName'];

    /** Settings with their defaults. */
    public const SETTING_REQUIRED = 'whatsappRequired';
    public const SETTING_REGISTRATION = 'showOnRegistration';

    /**
     * Whether the field is offered when an author or co-author is recorded. It
     * is on where nothing was ever saved: that is what the plugin did before
     * the setting existed, and a journal that never opened the settings keeps
     * the field it already had.
     */
    public const SETTING_CONTRIBUTOR = 'showOnContributor';

    /**
     * Register the plugin and its hooks.
     *
     * The author schema is extended on every request, whether or not the plugin
     * is enabled in the context being served: the schema DAO drops properties
     * that are not in the schema when an author is saved, so extending it only
     * where the plugin is enabled would silently lose stored numbers. Every
     * other hook checks the context itself.
     *
     * @param string $category
     * @param string $path
     * @param null|int $mainContextId
     */
    public function register($category, $path, $mainContextId = null): bool
    {
        $success = parent::register($category, $path, $mainContextId);
        if (!$success || Application::isUnderMaintenance()) {
            return $success;
        }

        Hook::add('Schema::get::author', $this->addWhatsAppToSchema(...));
        Hook::add('Form::config::before', $this->addWhatsAppToForm(...));
        Hook::add('Author::validate', $this->explainInvalidNumber(...));
        Hook::add('Author::newAuthorFromUser', $this->copyPhoneToAuthor(...));

        Hook::add('registrationform::Constructor', $this->addRegistrationCheck(...));
        Hook::add('registrationform::readUserVars', $this->readRegistrationNumber(...));
        Hook::add('registrationform::display', $this->addRegistrationField(...));
        Hook::add('registrationform::execute', $this->saveRegistrationNumber(...));

        return $success;
    }

    /**
     * Name shown in the plugins list.
     */
    public function getDisplayName(): string
    {
        return __('plugins.generic.whatsAppContributor.displayName');
    }

    /**
     * Description shown in the plugins list.
     */
    public function getDescription(): string
    {
        return __('plugins.generic.whatsAppContributor.description');
    }

    /**
     * Add the settings action to the plugin entry in the plugins list.
     */
    public function getActions($request, $actionArgs): array
    {
        $actions = parent::getActions($request, $actionArgs);
        if (!$request->getContext() || !$this->getEnabled()) {
            return $actions;
        }

        $router = $request->getRouter();
        array_unshift($actions, new LinkAction(
            'settings',
            new AjaxModal(
                $router->url($request, null, null, 'manage', null, ['verb' => 'settings', 'plugin' => $this->getName(), 'category' => 'generic']),
                $this->getDisplayName()
            ),
            __('manager.plugins.settings'),
            null
        ));

        return $actions;
    }

    /**
     * Show and save the settings form.
     */
    public function manage($args, $request): JSONMessage
    {
        // The settings belong to a journal; there is nothing to configure site-wide.
        $context = $request->getContext();
        if ($request->getUserVar('verb') !== 'settings' || !$context) {
            return parent::manage($args, $request);
        }

        $form = new WhatsAppSettingsForm($this, (int) $context->getId());
        if ($request->getUserVar('save')) {
            $form->readInputData();
            if ($form->validate()) {
                $form->execute();
                return new JSONMessage(true);
            }
        } else {
            $form->initData();
        }

        return new JSONMessage(true, $form->fetch($request));
    }

    /**
     * A number as typed, normalized to E.164 when it can be: spaces, dots,
     * hyphens and parentheses are removed, and a leading international "00"
     * becomes "+". Returns null for an empty value.
     */
    public static function normalizeNumber($raw): ?string
    {
        $value = trim((string) $raw);
        if ($value === '') {
            return null;
        }
        $value = preg_replace('/[\s.\-()\/]+/u', '', $value) ?? $value;
        if (str_starts_with($value, '00')) {
            $value = '+' . substr($value, 2);
        }

        return $value;
    }

    /**
     * Whether a normalized number is a valid E.164 number.
     */
    public static function isValidNumber(?string $value): bool
    {
        return $value !== null && preg_match(self::E164_PATTERN, $value) === 1;
    }

    //
    // Contributors
    //

    /**
     * Hook: Schema::get::author
     *
     * @param array $args [&$schema]
     */
    public function addWhatsAppToSchema($hookName, $args): bool
    {
        $schema = &$args[0];

        $schema->properties->whatsapp = (object) [
            'type' => 'string',
            // In the summary: the contributor list of the workflow is built from
            // summaries, and the edit form reopens with what the list holds. Only
            // users with access to the submission read it.
            'apiSummary' => true,
            'multilingual' => false,
            'validation' => [
                'nullable',
                'regex:' . self::E164_PATTERN,
            ],
        ];

        return Hook::CONTINUE;
    }

    /**
     * Hook: Form::config::before (fired through Hook::run, so the form comes as
     * the second argument).
     */
    public function addWhatsAppToForm($hookName, $form): bool
    {
        if (!$form || ($form->id ?? null) !== 'contributor' || !$this->isEnabledInCurrentContext()) {
            return Hook::CONTINUE;
        }
        if (!$this->showsOnContributorForm()) {
            return Hook::CONTINUE;
        }

        $example = __('plugins.generic.whatsAppContributor.field.example');
        $form->addField(new FieldText('whatsapp', [
            'label' => __('plugins.generic.whatsAppContributor.field.label'),
            'description' => __('plugins.generic.whatsAppContributor.field.description', ['example' => $example]),
            'isRequired' => $this->isRequiredForCurrentContext(),
            'size' => 'normal',
        ]));

        return Hook::CONTINUE;
    }

    /**
     * Hook: Author::validate — replace the generic "invalid format" message of
     * the schema with one that shows the expected format.
     *
     * @param array $args [&$errors, $author, $props, ...]
     */
    public function explainInvalidNumber($hookName, $args): bool
    {
        $errors = &$args[0];
        if (!empty($errors['whatsapp'])) {
            $errors['whatsapp'] = [__('plugins.generic.whatsAppContributor.field.invalidFormat')];
        }

        return Hook::CONTINUE;
    }

    /**
     * Hook: Author::newAuthorFromUser — the submitter becomes an author of their
     * own submission; a valid phone on the account becomes the author's number,
     * as the core does with the ORCID iD.
     *
     * @param array $args [$author, $user]
     */
    public function copyPhoneToAuthor($hookName, $args): bool
    {
        [$author, $user] = $args;
        if (!$author || !$user || !$this->isEnabledInCurrentContext() || $author->getData('whatsapp')) {
            return Hook::CONTINUE;
        }

        $number = self::normalizeNumber($user->getPhone());
        if (self::isValidNumber($number)) {
            $author->setData('whatsapp', $number);
        }

        return Hook::CONTINUE;
    }

    //
    // User registration
    //

    /**
     * Whether the registration form asks for the number in the current journal.
     */
    public function isOnRegistrationForCurrentContext(): bool
    {
        $context = Application::get()->getRequest()->getContext();

        return $context && $this->getEnabled($context->getId()) && (bool) $this->getSetting($context->getId(), self::SETTING_REGISTRATION);
    }

    /**
     * Hook: registrationform::Constructor — validate the number, required when
     * the journal requires it from contributors.
     *
     * @param array $args [$form, &$template]
     */
    public function addRegistrationCheck($hookName, $args): bool
    {
        $form = $args[0];
        if (!$form instanceof Form || !$this->isOnRegistrationForCurrentContext()) {
            return Hook::CONTINUE;
        }

        $form->addCheck(new FormValidatorCustom(
            $form,
            'whatsapp',
            $this->isRequiredForCurrentContext() ? 'required' : 'optional',
            'plugins.generic.whatsAppContributor.field.invalidFormat',
            fn ($value) => self::isValidNumber(self::normalizeNumber($value))
        ));

        return Hook::CONTINUE;
    }

    /**
     * Hook: registrationform::readUserVars
     *
     * @param array $args [$form, &$vars]
     */
    public function readRegistrationNumber($hookName, $args): bool
    {
        if ($this->isOnRegistrationForCurrentContext()) {
            $vars = &$args[1];
            $vars[] = 'whatsapp';
        }

        return Hook::CONTINUE;
    }

    /**
     * Hook: registrationform::display — the registration template has no hook,
     * so the field is added to the rendered form by an output filter, inside the
     * "identity" fieldset, after its last field.
     *
     * @param array $args [$form, &$output]
     */
    public function addRegistrationField($hookName, $args): bool
    {
        $form = $args[0];
        if (!$form instanceof Form || !$this->isOnRegistrationForCurrentContext()) {
            return Hook::CONTINUE;
        }

        $required = $this->isRequiredForCurrentContext();
        $templateMgr = PKPTemplateManager::getManager(Application::get()->getRequest());
        // Named: Smarty calls every closure filter "closure", so an unnamed one would replace, or be
        // replaced by, the output filter of another plugin in the same request.
        $parts = self::registrationFieldParts($form, $required);
        $templateMgr->registerFilter('output', fn (string $output): string => self::insertRegistrationField($output, $parts), 'whatsAppContributorRegistrationField');

        return Hook::CONTINUE;
    }

    /**
     * The pieces of the registration field, which are then dressed with the
     * markup of the theme.
     *
     * @return array{name: string, label: string, description: string, example: string, value: string, required: bool, error: ?string}
     */
    public static function registrationFieldParts(Form $form, bool $required): array
    {
        $errors = $form->getErrorsArray();

        return [
            'name' => 'whatsapp',
            'label' => __('plugins.generic.whatsAppContributor.field.label'),
            'example' => $example = __('plugins.generic.whatsAppContributor.field.example'),
            'description' => __('plugins.generic.whatsAppContributor.field.description', ['example' => $example]),
            'value' => (string) $form->getData('whatsapp'),
            'required' => $required,
            'error' => $errors['whatsapp'] ?? null,
        ];
    }

    /**
     * The markup of the field when the page gives nothing to model it on: the
     * shape the pages of the core use.
     */
    public static function renderRegistrationField(array $parts): string
    {
        $e = fn ($text) => htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
        $marker = $parts['required']
            ? ' <span class="required" aria-hidden="true">*</span><span class="pkp_screen_reader">' . $e(__('common.required')) . '</span>'
            : '';

        return '<div class="whatsapp whatsAppContributor"><label><span class="label">' . $e($parts['label']) . $marker . '</span>'
            . '<input type="tel" inputmode="tel" name="whatsapp" id="whatsapp" value="' . $e($parts['value']) . '" maxlength="32" autocomplete="tel"'
            . ' placeholder="' . $e($parts['example']) . '" pattern="' . $e(self::E164_HTML_PATTERN) . '" title="' . $e($parts['description']) . '"'
            . ' aria-describedby="whatsappDescription"' . ($parts['required'] ? ' required aria-required="true"' : '') . '></label>'
            . '<div class="description" id="whatsappDescription">' . $e($parts['description']) . '</div>'
            . ($parts['error'] ? '<span class="error">' . $e($parts['error']) . '</span>' : '')
            . '</div>';
    }

    /**
     * Put the field on the registration page, once.
     *
     * The page belongs to the theme, so nothing of the core is taken for
     * granted. The form is found by where it posts to, which no theme changes;
     * the field is then built from the markup of a field the theme itself
     * wrote — the same wrapper, the same classes, the same shape of label — and
     * put right after it, which keeps it with the personal data instead of
     * after the password and the privacy notice. Where there is nothing to
     * model it on, the markup of the core is used and the field goes before the
     * control that sends the form.
     */
    public static function insertRegistrationField(string $output, array $parts, ?string $fallback = null): string
    {
        if (preg_match('/<input\b[^>]*\bname="whatsapp"/', $output)) {
            return $output;
        }
        if (!preg_match('~<form\b[^>]*\baction="[^"]*/user/register[^"]*"[^>]*>~i', $output, $match, PREG_OFFSET_CAPTURE)) {
            return $output;
        }
        $formStart = $match[0][1];
        $formEnd = strpos($output, '</form>', $formStart);
        if ($formEnd === false) {
            return $output;
        }

        // Dressed like the field the theme wrote, and standing beside it.
        foreach (self::MODEL_FIELDS as $modelName) {
            if (!preg_match('/<input\b[^>]*\bname="' . $modelName . '"/i', substr($output, $formStart, $formEnd - $formStart), $found, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            $block = self::fieldBlock($output, $formStart + $found[0][1], $formEnd);
            if (!$block) {
                continue;
            }
            $dressed = self::dressLikeTheme(substr($output, $block[0], $block[1] - $block[0]), $parts, $modelName);
            if ($dressed !== null) {
                return substr_replace($output, $dressed, $block[1], 0);
            }
        }

        $fallback ??= self::renderRegistrationField($parts);

        // The page of the core: the field joins the personal data, at the end of
        // the fields of the identity block.
        $identity = strpos($output, '<fieldset class="identity"', $formStart);
        if ($identity !== false && $identity < $formEnd) {
            $end = strpos($output, '</fieldset>', $identity);
            $fieldsEnd = $end === false ? false : strrpos(substr($output, 0, $end), '</div>');
            if ($fieldsEnd !== false && $fieldsEnd > $identity) {
                return substr_replace($output, $fallback, $fieldsEnd, 0);
            }
        }

        // Anything else: with the other fields, just before the control that
        // sends the form — never after it.
        if (preg_match_all('~<(?:button|input)\b[^>]*\btype="submit"~i', substr($output, $formStart, $formEnd - $formStart), $submits, PREG_OFFSET_CAPTURE)) {
            $last = end($submits[0]);

            return substr_replace($output, $fallback, $formStart + $last[1], 0);
        }

        return substr_replace($output, $fallback, $formEnd, 0);
    }

    /**
     * The block of markup that holds one field: from the opening tag of the
     * element that encloses it to its matching close.
     *
     * @return ?array{0: int, 1: int} where the block starts and ends
     */
    public static function fieldBlock(string $html, int $inputAt, int $limit): ?array
    {
        $openers = [];
        foreach (['div', 'li', 'p'] as $tag) {
            $at = strripos(substr($html, 0, $inputAt), '<' . $tag);
            if ($at !== false) {
                $openers[$at] = $tag;
            }
        }
        if (!$openers) {
            return null;
        }
        $start = max(array_keys($openers));
        $tag = $openers[$start];

        // Its matching close, counting the ones opened in between.
        $depth = 0;
        $at = $start;
        while ($at < $limit) {
            $open = stripos($html, '<' . $tag, $at + 1);
            $close = stripos($html, '</' . $tag, $at + 1);
            if ($close === false || $close > $limit) {
                return null;
            }
            if ($open !== false && $open < $close) {
                $depth++;
                $at = $open;
                continue;
            }
            if ($depth === 0) {
                $end = strpos($html, '>', $close);

                return $end === false ? null : [$start, $end + 1];
            }
            $depth--;
            $at = $close;
        }

        return null;
    }

    /**
     * The block of a field the theme wrote, adapted to this one: its wrapper and
     * its classes are kept, the label takes our text, the input takes our
     * attributes and the description is put where the theme puts one.
     */
    public static function dressLikeTheme(string $model, array $parts, string $modelName = ''): ?string
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML('<?xml encoding="UTF-8">' . $model, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $input = $loaded ? $document->getElementsByTagName('input')->item(0) : null;
        if (!$input || !$document->documentElement) {
            return null;
        }

        foreach (['pattern', 'minlength', 'aria-describedby', 'aria-required', 'required', 'title', 'placeholder'] as $attribute) {
            $input->removeAttribute($attribute);
        }
        $input->setAttribute('type', 'tel');
        $input->setAttribute('inputmode', 'tel');
        $input->setAttribute('name', $parts['name']);
        $input->setAttribute('id', $parts['name']);
        $input->setAttribute('value', (string) $parts['value']);
        $input->setAttribute('maxlength', '32');
        $input->setAttribute('autocomplete', 'tel');
        $input->setAttribute('placeholder', $parts['example']);
        $input->setAttribute('pattern', self::E164_HTML_PATTERN);
        $input->setAttribute('title', $parts['description']);
        $input->setAttribute('aria-describedby', $parts['name'] . 'Description');
        if ($parts['required']) {
            $input->setAttribute('required', 'required');
            $input->setAttribute('aria-required', 'true');
        }

        // The wrapper keeps the classes that give it its layout, but a class
        // that names the field it was copied from would lie about this one.
        $wrapper = $document->documentElement;
        $classes = trim($wrapper->getAttribute('class'));
        if ($classes !== '' && $modelName !== '') {
            $names = [$modelName, strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $modelName))];
            $classes = implode(' ', array_map(
                fn (string $class) => in_array(strtolower($class), $names, true) ? $parts['name'] : $class,
                preg_split('/\s+/', $classes) ?: []
            ));
        }
        $wrapper->setAttribute('class', trim($classes . ' whatsAppContributor'));

        $label = $document->getElementsByTagName('label')->item(0);
        if ($label) {
            if ($label->hasAttribute('for')) {
                $label->setAttribute('for', $parts['name']);
            }
            self::replaceLabelText($document, $label, $parts['label']);
            if (!$parts['required']) {
                foreach (iterator_to_array($label->getElementsByTagName('span')) as $span) {
                    $class = strtolower($span->getAttribute('class'));
                    if (str_contains($class, 'required') || str_contains($class, 'screen') || str_contains($class, 'hidden')) {
                        $span->parentNode->removeChild($span);
                    }
                }
            }
        }

        // The description: where the theme has one, or right after the input.
        $description = null;
        foreach ($document->getElementsByTagName('*') as $element) {
            if (str_contains(strtolower($element->getAttribute('class')), 'description')) {
                $description = $element;
                break;
            }
        }
        if (!$description) {
            $description = $document->createElement('small');
            $description->setAttribute('class', 'description');
            $input->parentNode->insertBefore($description, $input->nextSibling);
        }
        while ($description->firstChild) {
            $description->removeChild($description->firstChild);
        }
        $description->setAttribute('id', $parts['name'] . 'Description');
        $description->appendChild($document->createTextNode($parts['description']));

        if (!empty($parts['error'])) {
            $error = $document->createElement('span');
            $error->setAttribute('class', 'error');
            $error->appendChild($document->createTextNode($parts['error']));
            $wrapper->appendChild($error);
        }

        $html = $document->saveHTML($document->documentElement);

        return $html === false ? null : $html;
    }

    /**
     * The text of a label, wherever the theme keeps it: directly inside the
     * label or inside the span the pages of the core use.
     */
    private static function replaceLabelText(DOMDocument $document, DOMElement $label, string $text): void
    {
        foreach ($label->childNodes as $node) {
            if ($node->nodeType === XML_TEXT_NODE && trim($node->nodeValue) !== '') {
                $node->nodeValue = $text;

                return;
            }
        }
        foreach ($label->getElementsByTagName('*') as $element) {
            $class = strtolower($element->getAttribute('class'));
            if (str_contains($class, 'required') || str_contains($class, 'screen') || str_contains($class, 'hidden')) {
                continue;
            }
            foreach ($element->childNodes as $node) {
                if ($node->nodeType === XML_TEXT_NODE && trim($node->nodeValue) !== '') {
                    $node->nodeValue = $text;

                    return;
                }
            }
        }
        $label->insertBefore($document->createTextNode($text), $label->firstChild);
    }


    /**
     * Hook: registrationform::execute — store the number as the phone of the
     * new account, before the core adds it.
     *
     * @param array $args [$form, ...]
     */
    public function saveRegistrationNumber($hookName, $args): bool
    {
        $form = $args[0];
        if (!$form instanceof Form || !isset($form->user) || !$this->isOnRegistrationForCurrentContext()) {
            return Hook::CONTINUE;
        }

        $number = self::normalizeNumber($form->getData('whatsapp'));
        if (self::isValidNumber($number)) {
            $form->user->setPhone($number);
        }

        return Hook::CONTINUE;
    }

    //
    // Context
    //

    /**
     * Whether the plugin is enabled in the journal of the request.
     */
    public function isEnabledInCurrentContext(): bool
    {
        $context = Application::get()->getRequest()->getContext();

        return $context && (bool) $this->getEnabled($context->getId());
    }

    /**
     * Whether the journal of the request requires the number.
     */
    /**
     * Whether the journal asks for the number when a contributor is recorded.
     * A journal that never saved the setting keeps the field, which is how the
     * plugin behaved before the setting existed.
     */
    public function showsOnContributorForm(): bool
    {
        $context = Application::get()->getRequest()->getContext();
        if (!$context) {
            return false;
        }
        $value = $this->getSetting($context->getId(), self::SETTING_CONTRIBUTOR);

        return $value === null || $value === '' ? true : (bool) $value;
    }

    public function isRequiredForCurrentContext(): bool
    {
        $context = Application::get()->getRequest()->getContext();

        return $context && (bool) $this->getSetting($context->getId(), self::SETTING_REQUIRED);
    }
}

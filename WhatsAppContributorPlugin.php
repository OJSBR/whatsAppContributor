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

    /** Settings with their defaults. */
    public const SETTING_REQUIRED = 'whatsappRequired';
    public const SETTING_REGISTRATION = 'showOnRegistration';

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

        Hook::add('Schema::get::author', [$this, 'addWhatsAppToSchema']);
        Hook::add('Form::config::before', [$this, 'addWhatsAppToForm']);
        Hook::add('Author::validate', [$this, 'explainInvalidNumber']);
        Hook::add('Author::newAuthorFromUser', [$this, 'copyPhoneToAuthor']);

        Hook::add('registrationform::Constructor', [$this, 'addRegistrationCheck']);
        Hook::add('registrationform::readUserVars', [$this, 'readRegistrationNumber']);
        Hook::add('registrationform::display', [$this, 'addRegistrationField']);
        Hook::add('registrationform::execute', [$this, 'saveRegistrationNumber']);

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

        $form->addField(new FieldText('whatsapp', [
            'label' => __('plugins.generic.whatsAppContributor.field.label'),
            'description' => __('plugins.generic.whatsAppContributor.field.description'),
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
        $templateMgr->registerFilter('output', fn (string $output): string => self::insertRegistrationField($output, self::renderRegistrationField($form, $required)), 'whatsAppContributorRegistrationField');

        return Hook::CONTINUE;
    }

    /**
     * The markup of the registration field, in the style of the other fields.
     */
    public static function renderRegistrationField(Form $form, bool $required): string
    {
        $e = fn ($text) => htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
        $errors = $form->getErrorsArray();
        $marker = $required
            ? ' <span class="required" aria-hidden="true">*</span><span class="pkp_screen_reader">' . $e(__('common.required')) . '</span>'
            : '';

        return '<div class="whatsapp whatsAppContributor"><label><span class="label">' . $e(__('plugins.generic.whatsAppContributor.field.label')) . $marker . '</span>'
            . '<input type="tel" name="whatsapp" id="whatsAppContributor" value="' . $e($form->getData('whatsapp')) . '" maxlength="32" autocomplete="tel"'
            . ' aria-describedby="whatsAppContributorDescription"' . ($required ? ' required aria-required="true"' : '') . '></label>'
            . '<div class="description" id="whatsAppContributorDescription">' . $e(__('plugins.generic.whatsAppContributor.field.description')) . '</div>'
            . (isset($errors['whatsapp']) ? '<span class="error">' . $e($errors['whatsapp']) . '</span>' : '')
            . '</div>';
    }

    /**
     * Put the field at the end of the fields of fieldset.identity in
     * form#register, once. Anything else is returned unchanged.
     */
    public static function insertRegistrationField(string $output, string $field): string
    {
        $form = strpos($output, 'id="register"');
        if ($form === false || preg_match('/<input\b[^>]*\bname="whatsapp"/', $output)) {
            return $output;
        }
        $identity = strpos($output, '<fieldset class="identity"', $form);
        $end = $identity === false ? false : strpos($output, '</fieldset>', $identity);
        if ($end === false) {
            return $output;
        }
        $fieldsEnd = strrpos(substr($output, 0, $end), '</div>');

        return $fieldsEnd === false || $fieldsEnd < $identity ? $output : substr_replace($output, $field, $fieldsEnd, 0);
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
    public function isRequiredForCurrentContext(): bool
    {
        $context = Application::get()->getRequest()->getContext();

        return $context && (bool) $this->getSetting($context->getId(), self::SETTING_REQUIRED);
    }
}

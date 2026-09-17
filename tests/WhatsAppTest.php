<?php

/**
 * @file plugins/generic/whatsAppContributor/tests/WhatsAppTest.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class WhatsAppTest
 *
 * @brief Number normalization, the schema property, and the registration field.
 */

namespace APP\plugins\generic\whatsAppContributor\tests;

use APP\plugins\generic\whatsAppContributor\WhatsAppContributorPlugin;
use APP\plugins\generic\whatsAppContributor\WhatsAppSettingsForm;
use PHPUnit\Framework\Attributes\CoversClass;
use PKP\form\Form;
use PKP\tests\PKPTestCase;

#[CoversClass(WhatsAppContributorPlugin::class)]
#[CoversClass(WhatsAppSettingsForm::class)]
class WhatsAppTest extends PKPTestCase
{
    /** The pieces of the field, as the plugin hands them over. */
    private static function parts(bool $required = false): array
    {
        return [
            'name' => 'whatsapp',
            'label' => 'Telefone / WhatsApp',
            'description' => 'Formato internacional. Exemplo: +55 11 99999-9999.',
            'example' => '+55 11 99999-9999',
            'value' => '',
            'required' => $required,
            'error' => null,
        ];
    }

    public function testNumbersAreNormalizedToE164(): void
    {
        $this->assertSame('+5511999999999', WhatsAppContributorPlugin::normalizeNumber(' +55 (11) 99999-9999 '));
        $this->assertSame('+5511999999999', WhatsAppContributorPlugin::normalizeNumber('0055 11 99999.9999'));
        $this->assertSame(null, WhatsAppContributorPlugin::normalizeNumber('   '));
        $this->assertSame('11999999999', WhatsAppContributorPlugin::normalizeNumber('11 99999-9999'));
    }

    public function testOnlyE164NumbersAreValid(): void
    {
        $this->assertTrue(WhatsAppContributorPlugin::isValidNumber('+5511999999999'));
        $this->assertTrue(WhatsAppContributorPlugin::isValidNumber('+14155552671'));
        $this->assertFalse(WhatsAppContributorPlugin::isValidNumber('11999999999'), 'The country code is required.');
        $this->assertFalse(WhatsAppContributorPlugin::isValidNumber('+0511999999999'), 'A country code never starts with 0.');
        $this->assertFalse(WhatsAppContributorPlugin::isValidNumber('+55119999999991234'), 'More than 15 digits.');
        $this->assertFalse(WhatsAppContributorPlugin::isValidNumber(null));
    }

    public function testTheNumberIsInTheSummaryTheContributorListReopensFrom(): void
    {
        $schema = (object) ['properties' => (object) []];
        (new WhatsAppContributorPlugin())->addWhatsAppToSchema('Schema::get::author', [&$schema]);

        // Without it the edit form reopens blank and the next save erases the number.
        $this->assertTrue($schema->properties->whatsapp->apiSummary);
        $this->assertSame(['nullable', 'regex:' . WhatsAppContributorPlugin::E164_PATTERN], $schema->properties->whatsapp->validation);
    }

    public function testTheRegistrationFieldGoesAfterTheLastIdentityField(): void
    {
        $page = '<form id="register" action="https://x/index.php/j/user/register"><fieldset class="identity"><legend>Profile</legend><div class="fields">'
            . '<div class="given_name"><input name="givenName"></div><div class="country"><select name="country"></select></div>'
            . '</div></fieldset><fieldset class="login"></fieldset></form>';
        $output = WhatsAppContributorPlugin::insertRegistrationField($page, self::parts(), '<div class="whatsAppContributor"></div>');

        // Dressed like the field it was modelled on and standing beside it,
        // which on the page of the core is inside the identity block.
        $this->assertMatchesRegularExpression('~name="givenName"></div><div class="whatsapp whatsAppContributor"><input name="whatsapp"~', $output);
        $this->assertLessThan(strpos($output, '<fieldset class="login">'), strpos($output, 'name="whatsapp"'));
        $withField = str_replace('<div class="whatsAppContributor"></div>', '<input type="tel" name="whatsapp">', $output);
        $this->assertSame($withField, WhatsAppContributorPlugin::insertRegistrationField($withField, self::parts(), 'x'), 'The field is added once.');
        $this->assertSame('<div class="pkp_block"></div>', WhatsAppContributorPlugin::insertRegistrationField('<div class="pkp_block"></div>', self::parts(), '<b>x</b>'), 'Other output is left alone.');
    }

    public function testTheRegistrationFieldGoesIntoAFormWrittenByATheme(): void
    {
        // A theme may write its own registration form: no id of the core, no
        // fieldset of the core. What it cannot change is where the form posts
        // to, so the field still finds its way in — at the end of that form.
        $page = '<div class="page"><form class="form-register" method="post" action="https://x/index.php/j/pt_BR/user/register">'
            . '<fieldset class="form-register"><div class="form-group"><input name="givenName"></div></fieldset>'
            . '<button type="submit">Cadastrar</button></form></div>';

        $output = WhatsAppContributorPlugin::insertRegistrationField($page, self::parts(), '<div class="whatsAppContributor"></div>');

        // With the other fields, just before the control that sends the form.
        // With the markup of the theme, beside the field it copied.
        $this->assertStringContainsString('<div class="form-group whatsAppContributor"><input name="whatsapp"', $output);
        $this->assertSame(1, substr_count($output, 'name="whatsapp"'), 'the field goes in once');
        $this->assertLessThan(strpos($output, '<button type="submit">'), strpos($output, 'name="whatsapp"'));

        // Where there is nothing to model it on, the markup of the core is used
        // and the field goes before the control that sends the form.
        $noModel = '<form class="form-register" action="/index.php/j/user/register">'
            . '<input type="password" name="password"><button type="submit">Cadastrar</button></form>';
        $this->assertStringContainsString('<div class="whatsAppContributor"></div><button type="submit">',
            WhatsAppContributorPlugin::insertRegistrationField($noModel, self::parts(), '<div class="whatsAppContributor"></div>'));

        // And with no control either, at the end of the form.
        $bare = '<form class="form-register" action="/index.php/j/user/register"><input type="password" name="password"></form>';
        $this->assertStringContainsString('<div class="whatsAppContributor"></div></form>',
            WhatsAppContributorPlugin::insertRegistrationField($bare, self::parts(), '<div class="whatsAppContributor"></div>'));

        // A form that posts somewhere else is not the registration form.
        $login = '<form class="form-login" method="post" action="https://x/index.php/j/pt_BR/login/signIn"></form>';
        $this->assertSame($login, WhatsAppContributorPlugin::insertRegistrationField($login, self::parts(), '<b>x</b>'));
    }

    public function testTheContributorFormIsAskedForOnlyWhereTheJournalWantsIt(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/WhatsAppContributorPlugin.php');

        // A setting of its own, and the form only gets the field where it is on.
        $this->assertSame('showOnContributor', WhatsAppContributorPlugin::SETTING_CONTRIBUTOR);
        $this->assertStringContainsString('if (!$this->showsOnContributorForm()) {', $source);
        // A journal that never saved it keeps the field, which is what the
        // plugin did before the setting existed.
        $this->assertStringContainsString("return \$value === null || \$value === '' ? true : (bool) \$value;", $source);
        // And the settings screen saves it beside the other two.
        $settings = (string) file_get_contents(dirname(__DIR__) . '/WhatsAppSettingsForm.php');
        $this->assertSame(3, substr_count($settings, 'WhatsAppContributorPlugin::SETTING_CONTRIBUTOR'));
    }

    public function testTheRegistrationFieldEscapesTheValueAndMarksRequired(): void
    {
        $this->withRouter();
        $form = new class () extends Form {
            public function __construct()
            {
                $this->_data = [];
                $this->_errors = [];
            }
        };
        $form->setData('whatsapp', '"><script>x</script>');

        $optional = WhatsAppContributorPlugin::renderRegistrationField(WhatsAppContributorPlugin::registrationFieldParts($form, false));
        $required = WhatsAppContributorPlugin::renderRegistrationField(WhatsAppContributorPlugin::registrationFieldParts($form, true));

        $this->assertStringNotContainsString('<script>', $optional);
        $this->assertStringContainsString('name="whatsapp"', $optional);
        $this->assertStringNotContainsString('required aria-required="true"', $optional);
        $this->assertStringContainsString('required aria-required="true"', $required);

        // Easier to fill in: the keyboard of a phone, an example in the field
        // itself and the format checked by the browser before it is sent.
        $this->assertStringContainsString('inputmode="tel"', $optional);
        $this->assertStringContainsString('placeholder="', $optional);
        $this->assertStringContainsString('pattern="' . htmlspecialchars(WhatsAppContributorPlugin::E164_HTML_PATTERN, ENT_QUOTES), $optional);
    }

    public function testTheFieldIsDressedWithTheMarkupOfTheTheme(): void
    {
        // A field the theme wrote, with the classes of the theme.
        $model = '<div class="form-group"><label for="affiliation">Instituição<span class="required">*</span></label>'
            . '<input class="form-control" type="text" name="affiliation" id="affiliation" value="x" required></div>';

        $dressed = WhatsAppContributorPlugin::dressLikeTheme($model, self::parts(), 'affiliation');

        // The wrapper, the classes and the shape of the label are the theme's.
        $this->assertStringContainsString('class="form-group whatsAppContributor"', $dressed);
        $this->assertStringContainsString('class="form-control"', $dressed);
        $this->assertStringContainsString('<label for="whatsapp">', $dressed);
        // The text, the name and the value are ours.
        $this->assertStringContainsString('Telefone / WhatsApp', $dressed);
        $this->assertStringContainsString('name="whatsapp"', $dressed);
        $this->assertStringNotContainsString('Instituição', $dressed);
        $this->assertStringNotContainsString('value="x"', $dressed);
        // Nothing of the model that does not apply: the field is not required
        // here, so the mark of the theme for a required field is gone.
        $this->assertStringNotContainsString('<span class="required">', $dressed);
        $this->assertStringNotContainsString(' required', $dressed);
        // And the description is there, with the example.
        $this->assertStringContainsString('+55 11 99999-9999', $dressed);
    }

    public function testTheFieldStandsBesideTheFieldItWasModelledOn(): void
    {
        $page = '<form class="form-register" action="/index.php/j/pt_BR/user/register">'
            . '<div class="form-group"><label for="affiliation">Instituição</label><input class="form-control" type="text" name="affiliation" id="affiliation"></div>'
            . '<div class="form-group"><label for="password">Senha</label><input type="password" name="password"></div>'
            . '<button type="submit">Cadastrar</button></form>';

        $output = WhatsAppContributorPlugin::insertRegistrationField($page, self::parts());

        // Right after the field it copied, and well before the password.
        $this->assertLessThan(strpos($output, 'name="password"'), strpos($output, 'name="whatsapp"'));
        $this->assertGreaterThan(strpos($output, 'name="affiliation"'), strpos($output, 'name="whatsapp"'));
        $this->assertSame(1, substr_count($output, 'name="whatsapp"'));
    }

    /** CLI has no router; the translations ask the request for its context. */
    protected function withRouter(): void
    {
        $request = \APP\core\Application::get()->getRequest();
        if (!$request->getRouter()) {
            $router = new \APP\core\PageRouter();
            $router->setApplication(\APP\core\Application::get());
            $request->setRouter($router);
        }
    }

    public function testTheSiteLevelHasNoSettingsToOpen(): void
    {
        $request = new class () {
            public function getContext()
            {
                return null;
            }

            public function getUserVar($name)
            {
                return $name === 'verb' ? 'settings' : null;
            }

            public function getRouter()
            {
                throw new \RuntimeException('The site level must not build a settings URL.');
            }
        };
        $plugin = new class () extends WhatsAppContributorPlugin {
            public function getEnabled($contextId = null)
            {
                return true;
            }
        };

        $this->assertSame([], array_filter($plugin->getActions($request, []), fn ($action) => $action->getId() === 'settings'));
        $this->expectExceptionMessage('Unhandled management action!');
        $plugin->manage([], $request);
    }

    public function testTheOutputFilterIsNamedSoOtherPluginsDoNotReplaceIt(): void
    {
        // Smarty names every closure filter "closure": an unnamed one and another plugin's replace each other.
        $source = (string) file_get_contents(dirname(__DIR__) . '/WhatsAppContributorPlugin.php');
        $filters = substr_count($source, "registerFilter('output'");
        $this->assertGreaterThan(0, $filters);
        $this->assertSame($filters, preg_match_all("/, 'whatsAppContributor\\w+'\\);/", $source));
    }
}

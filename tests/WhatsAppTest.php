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
        $output = WhatsAppContributorPlugin::insertRegistrationField($page, '<div class="whatsAppContributor"></div>');

        $this->assertStringContainsString('<select name="country"></select></div><div class="whatsAppContributor"></div></div></fieldset><fieldset class="login">', $output);
        $withField = str_replace('<div class="whatsAppContributor"></div>', '<input type="tel" name="whatsapp">', $output);
        $this->assertSame($withField, WhatsAppContributorPlugin::insertRegistrationField($withField, 'x'), 'The field is added once.');
        $this->assertSame('<div class="pkp_block"></div>', WhatsAppContributorPlugin::insertRegistrationField('<div class="pkp_block"></div>', '<b>x</b>'), 'Other output is left alone.');
    }

    public function testTheRegistrationFieldGoesIntoAFormWrittenByATheme(): void
    {
        // A theme may write its own registration form: no id of the core, no
        // fieldset of the core. What it cannot change is where the form posts
        // to, so the field still finds its way in — at the end of that form.
        $page = '<div class="page"><form class="form-register" method="post" action="https://x/index.php/j/pt_BR/user/register">'
            . '<fieldset class="form-register"><div class="form-group"><input name="givenName"></div></fieldset>'
            . '<button type="submit">Cadastrar</button></form></div>';

        $output = WhatsAppContributorPlugin::insertRegistrationField($page, '<div class="whatsAppContributor"></div>');

        // With the other fields, just before the control that sends the form.
        $this->assertStringContainsString('<div class="whatsAppContributor"></div><button type="submit">Cadastrar</button></form>', $output);
        $this->assertSame(1, substr_count($output, 'whatsAppContributor'), 'the field goes in once');

        // And where the theme's form has no button, the end of the form is used.
        $noButton = '<form class="form-register" action="/index.php/j/user/register"><input name="givenName"></form>';
        $this->assertStringContainsString('<input name="givenName"><div class="whatsAppContributor"></div></form>',
            WhatsAppContributorPlugin::insertRegistrationField($noButton, '<div class="whatsAppContributor"></div>'));

        // A form that posts somewhere else is not the registration form.
        $login = '<form class="form-login" method="post" action="https://x/index.php/j/pt_BR/login/signIn"></form>';
        $this->assertSame($login, WhatsAppContributorPlugin::insertRegistrationField($login, '<b>x</b>'));
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

        $optional = WhatsAppContributorPlugin::renderRegistrationField($form, false);
        $required = WhatsAppContributorPlugin::renderRegistrationField($form, true);

        $this->assertStringNotContainsString('<script>', $optional);
        $this->assertStringContainsString('name="whatsapp"', $optional);
        $this->assertStringNotContainsString('required aria-required="true"', $optional);
        $this->assertStringContainsString('required aria-required="true"', $required);
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

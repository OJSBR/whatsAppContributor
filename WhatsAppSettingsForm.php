<?php

/**
 * @file plugins/generic/whatsAppContributor/WhatsAppSettingsForm.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class WhatsAppSettingsForm
 *
 * @ingroup plugins_generic_whatsAppContributor
 *
 * @brief Per-journal settings: whether the number is required, and whether the
 *        registration form asks for it.
 */

namespace APP\plugins\generic\whatsAppContributor;

use APP\template\TemplateManager;
use PKP\form\Form;
use PKP\form\validation\FormValidatorCSRF;
use PKP\form\validation\FormValidatorPost;

class WhatsAppSettingsForm extends Form
{
    /** @var int Journal id */
    public $contextId;

    /** @var WhatsAppContributorPlugin */
    public $plugin;

    public function __construct($plugin, $contextId)
    {
        $this->contextId = $contextId;
        $this->plugin = $plugin;

        parent::__construct($plugin->getTemplateResource('settings.tpl'));

        $this->addCheck(new FormValidatorPost($this));
        $this->addCheck(new FormValidatorCSRF($this));
    }

    /**
     * Load the current settings of the journal.
     */
    public function initData()
    {
        foreach ([WhatsAppContributorPlugin::SETTING_REQUIRED, WhatsAppContributorPlugin::SETTING_REGISTRATION, WhatsAppContributorPlugin::SETTING_CONTRIBUTOR] as $name) {
            $this->setData($name, (bool) $this->plugin->getSetting($this->contextId, $name));
        }
        parent::initData();
    }

    /**
     * Read the submitted settings.
     */
    public function readInputData()
    {
        $this->readUserVars([WhatsAppContributorPlugin::SETTING_REQUIRED, WhatsAppContributorPlugin::SETTING_REGISTRATION, WhatsAppContributorPlugin::SETTING_CONTRIBUTOR]);
        parent::readInputData();
    }

    /**
     * Render the form.
     *
     * @param null|mixed $template
     */
    public function fetch($request, $template = null, $display = false)
    {
        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->assign('pluginName', $this->plugin->getName());
        return parent::fetch($request, $template, $display);
    }

    /**
     * Save the settings of the journal.
     */
    public function execute(...$functionArgs)
    {
        foreach ([WhatsAppContributorPlugin::SETTING_REQUIRED, WhatsAppContributorPlugin::SETTING_REGISTRATION, WhatsAppContributorPlugin::SETTING_CONTRIBUTOR] as $name) {
            $this->plugin->updateSetting($this->contextId, $name, (bool) $this->getData($name), 'bool');
        }
        return parent::execute(...$functionArgs);
    }
}

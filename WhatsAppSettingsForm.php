<?php
/**
 * @file plugins/generic/whatsAppContributor/WhatsAppSettingsForm.php
 *
 * @class WhatsAppSettingsForm
 *
 * @ingroup plugins_generic_whatsAppContributor
 *
 * @brief Formulario de configuracao do plugin por revista (OJS 3.5).
 */

namespace APP\plugins\generic\whatsAppContributor;

use APP\template\TemplateManager;
use PKP\form\Form;
use PKP\form\validation\FormValidatorPost;
use PKP\form\validation\FormValidatorCSRF;

class WhatsAppSettingsForm extends Form
{
    /** @var int ID do contexto (revista) */
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
     * @copydoc Form::initData()
     */
    public function initData()
    {
        $this->setData(
            'whatsappRequired',
            (bool) $this->plugin->getSetting($this->contextId, 'whatsappRequired')
        );
        parent::initData();
    }

    /**
     * @copydoc Form::readInputData()
     */
    public function readInputData()
    {
        $this->readUserVars(['whatsappRequired']);
        parent::readInputData();
    }

    /**
     * @copydoc Form::fetch()
     */
    public function fetch($request, $template = null, $display = false)
    {
        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->assign('pluginName', $this->plugin->getName());
        return parent::fetch($request, $template, $display);
    }

    /**
     * @copydoc Form::execute()
     */
    public function execute(...$functionArgs)
    {
        $this->plugin->updateSetting(
            $this->contextId,
            'whatsappRequired',
            (bool) $this->getData('whatsappRequired'),
            'bool'
        );
        return parent::execute(...$functionArgs);
    }
}

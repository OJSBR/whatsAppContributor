<?php

/**
 * @file plugins/generic/whatsAppContributor/WhatsAppContributorPlugin.php
 *
 * @class WhatsAppContributorPlugin
 *
 * @ingroup plugins_generic_whatsAppContributor
 *
 * @brief Adiciona um campo "Telefone / WhatsApp" (formato E.164) ao
 *        formulário de cadastro de contribuidor (autor) do artigo
 *        no OJS 3.4. Opcional ou obrigatório, configurável por revista.
 *
 *        Persiste em author_settings com setting_name = "whatsapp".
 *        Validação: E.164 (ex.: +5511999999999).
 *        Visibilidade: somente formulário editorial.
 */

namespace APP\plugins\generic\whatsAppContributor;

use APP\core\Application;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\core\JSONMessage;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\components\forms\FieldText;
use PKP\config\Config;

class WhatsAppContributorPlugin extends GenericPlugin
{
    /**
     * @copydoc Plugin::register()
     */
    public function register($category, $path, $mainContextId = null)
    {
        $success = parent::register($category, $path, $mainContextId);
        if (!Config::getVar('general', 'installed') || defined('RUNNING_UPGRADE')) {
            return $success;
        }
        if ($success && $this->getEnabled($mainContextId)) {
            // 1. Schema do autor: registra a propriedade `whatsapp`.
            //    Persiste em author_settings (setting_name = "whatsapp")
            //    quando o contribuidor é salvo via API.
            Hook::add('Schema::get::author', [$this, 'addWhatsAppToSchema']);

            // 2. Form de contribuidor (id = 'contributor'): injeta o campo.
            Hook::add('Form::config::before', [$this, 'addWhatsAppToForm']);
        }
        return $success;
    }

    /**
     * @copydoc Plugin::getDisplayName()
     */
    public function getDisplayName()
    {
        return __('plugins.generic.whatsAppContributor.displayName');
    }

    /**
     * Nome estável do plugin no registry e nas URLs do gerenciador.
     * Com namespace, o getName() padrão retorna o FQCN em lower, que
     * não bate com o que vai pela URL. Forçamos o nome curto da classe.
     *
     * @copydoc Plugin::getName()
     */
    public function getName()
    {
        return 'whatsappcontributorplugin';
    }

    /**
     * @copydoc Plugin::getDescription()
     */
    public function getDescription()
    {
        return __('plugins.generic.whatsAppContributor.description');
    }

    /**
     * Adiciona botão "Settings" na linha do plugin (configuração por revista).
     *
     * @copydoc Plugin::getActions()
     */
    public function getActions($request, $verb)
    {
        $actions = parent::getActions($request, $verb);
        if (!$this->getEnabled()) {
            return $actions;
        }
        $router = $request->getRouter();
        $linkAction = new LinkAction(
            'settings',
            new AjaxModal(
                $router->url(
                    $request,
                    null,
                    null,
                    'manage',
                    null,
                    [
                        'verb' => 'settings',
                        'plugin' => $this->getName(),
                        'category' => 'generic',
                    ]
                ),
                $this->getDisplayName()
            ),
            __('manager.plugins.settings'),
            null
        );
        array_unshift($actions, $linkAction);
        return $actions;
    }

    /**
     * @copydoc Plugin::manage()
     */
    public function manage($args, $request)
    {
        if ($request->getUserVar('verb') !== 'settings') {
            return parent::manage($args, $request);
        }

        $context = $request->getContext();
        if (!$context) {
            return new JSONMessage(false);
        }

        $form = new WhatsAppSettingsForm($this, $context->getId());

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
     * Hook: Schema::get::author
     *
     * Adiciona a propriedade `whatsapp` ao schema do autor.
     *
     * @param string $hookName
     * @param array  $args     [&$schema]
     *
     * @return bool
     */
    public function addWhatsAppToSchema($hookName, $args)
    {
        $schema = &$args[0];

        // - nullable: schema permite vazio. Obrigatoriedade real é
        //   aplicada pelo FieldText (isRequired) no formulário.
        // - regex E.164: começa com +, primeiro dígito 1-9, total 2-15 dígitos.
        $schema->properties->whatsapp = (object) [
            'type' => 'string',
            'apiSummary' => true,
            'multilingual' => false,
            'validation' => [
                'nullable',
                'regex:/^\+[1-9]\d{1,14}$/',
            ],
        ];

        return Hook::CONTINUE;
    }

    /**
     * Hook: Form::config::before
     *
     * Adiciona o campo `whatsapp` ao formulário de contribuidor.
     *
     * @param string $hookName
     * @param \PKP\components\forms\FormComponent $form
     *
     * @return bool
     */
    public function addWhatsAppToForm($hookName, $form)
    {
        if (!$form || $form->id !== 'contributor') {
            return Hook::CONTINUE;
        }

        $request = Application::get()->getRequest();
        $context = $request->getContext();
        if (!$context) {
            return Hook::CONTINUE;
        }

        $required = $this->isRequiredForCurrentContext();

        $form->addField(new FieldText('whatsapp', [
            'label' => __('plugins.generic.whatsAppContributor.field.label'),
            'description' => __('plugins.generic.whatsAppContributor.field.description'),
            'isRequired' => $required,
            'size' => 'normal',
        ]));

        return Hook::CONTINUE;
    }

    /**
     * Lê a flag de obrigatoriedade do contexto atual.
     *
     * @return bool
     */
    public function isRequiredForCurrentContext()
    {
        $request = Application::get()->getRequest();
        $context = $request->getContext();
        if (!$context) {
            return false;
        }
        return (bool) $this->getSetting($context->getId(), 'whatsappRequired');
    }
}

// Compatibilidade legacy do OJS 3.4: o registry procura a classe pelo
// nome curto sem namespace. Quando PKP_STRICT_MODE não está ativo,
// criamos um alias para que `WhatsAppContributorPlugin` (sem namespace)
// resolva para a classe namespaced.
if (!PKP_STRICT_MODE) {
    class_alias(
        '\APP\plugins\generic\whatsAppContributor\WhatsAppContributorPlugin',
        '\WhatsAppContributorPlugin'
    );
}

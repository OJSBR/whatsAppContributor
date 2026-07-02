<?php
/**
 * @file plugins/generic/whatsAppContributor/WhatsAppContributorPlugin.php
 *
 * @class WhatsAppContributorPlugin
 *
 * @ingroup plugins_generic_whatsAppContributor
 *
 * @brief Adiciona um campo "Telefone / WhatsApp" (formato E.164) ao
 *        formulario de cadastro de contribuidor (autor) do artigo
 *        no OJS 3.5. Opcional ou obrigatorio, configuravel por revista.
 *
 *        Persiste em author_settings com setting_name = "whatsapp".
 *        Validacao: E.164 (ex.: +5511999999999).
 *        Visibilidade: somente formulario editorial.
 */

namespace APP\plugins\generic\whatsAppContributor;

use APP\core\Application;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\core\JSONMessage;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\components\forms\FieldText;

class WhatsAppContributorPlugin extends GenericPlugin
{
    /**
     * @copydoc Plugin::register()
     *
     * Padrao 3.5 (PKP issue #11793): SEMPRE registrar os hooks; o check de
     * getEnabled() vai dentro de cada callback. Isso garante que o schema do
     * autor seja estendido em todo request (display, save, API) e que o save
     * via SchemaDAO nao descarte silenciosamente o campo whatsapp.
     */
    public function register($category, $path, $mainContextId = null)
    {
        $success = parent::register($category, $path, $mainContextId);

        if (Application::isUnderMaintenance()) {
            return $success;
        }

        if (!$success) {
            return $success;
        }

        Hook::add('Schema::get::author', $this->addWhatsAppToSchema(...));
        Hook::add('Form::config::before', $this->addWhatsAppToForm(...));

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
     * @copydoc Plugin::getDescription()
     */
    public function getDescription()
    {
        return __('plugins.generic.whatsAppContributor.description');
    }

    /**
     * Botao "Settings" na linha do plugin.
     *
     * @copydoc Plugin::getActions()
     */
    public function getActions($request, $verb): array
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
                    request: $request,
                    op: 'manage',
                    params: [
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
     * Adiciona a propriedade whatsapp ao schema do autor. SEMPRE registrado;
     * gating por contexto seria tarde demais — o SchemaDAO usa o schema na
     * hora do save e descarta props ausentes.
     *
     * @param string $hookName
     * @param array  $args     [&$schema]
     *
     * @return bool
     */
    public function addWhatsAppToSchema($hookName, $args)
    {
        $schema = &$args[0];

        $schema->properties->whatsapp = (object) [
            'type' => 'string',
            'apiSummary' => true,
            'multilingual' => false,
            'validation' => [
                'nullable',
                'regex:/^\\+[1-9]\\d{1,14}$/',
            ],
        ];
        return Hook::CONTINUE;
    }

    /**
     * Hook: Form::config::before
     *
     * Adiciona o campo whatsapp ao formulario de contribuidor.
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
        if (!$this->isEnabledInCurrentContext()) {
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
     * O plugin esta habilitado no contexto atual?
     */
    public function isEnabledInCurrentContext(): bool
    {
        $request = Application::get()->getRequest();
        $context = $request->getContext();
        $contextId = $context
            ? $context->getId()
            : \PKP\core\PKPApplication::SITE_CONTEXT_ID;
        return (bool) $this->getEnabled($contextId);
    }

    /**
     * Le a flag de obrigatoriedade do contexto atual.
     */
    public function isRequiredForCurrentContext(): bool
    {
        $request = Application::get()->getRequest();
        $context = $request->getContext();
        if (!$context) {
            return false;
        }
        return (bool) $this->getSetting($context->getId(), 'whatsappRequired');
    }
}

{**
 * plugins/generic/whatsAppContributor/templates/settings.tpl
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Per-journal settings of the WhatsApp Contributor plugin.
 *}
<script>
	$(function() {ldelim}
		$('#whatsAppContributorSettings').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
	{rdelim});
</script>

<form class="pkp_form" id="whatsAppContributorSettings"
	method="post"
	action="{url router=PKP\core\PKPApplication::ROUTE_COMPONENT op="manage" category="generic" plugin=$pluginName verb="settings" save=true}">

	{csrf}

	{include file="controllers/notification/inPlaceNotification.tpl" notificationId="whatsAppContributorSettingsNotification"}

	<div id="description">{translate key="plugins.generic.whatsAppContributor.settings.description"}</div>

	{fbvFormArea id="whatsAppContributorSettingsFormArea"}
		{fbvFormSection list=true}
			{fbvElement type="checkbox"
				id="whatsappRequired"
				value="1"
				checked=$whatsappRequired
				label="plugins.generic.whatsAppContributor.settings.required.label"}
			{fbvElement type="checkbox"
				id="showOnContributor"
				value="1"
				checked=$showOnContributor
				label="plugins.generic.whatsAppContributor.settings.showOnContributor.label"}
			{fbvElement type="checkbox"
				id="showOnRegistration"
				value="1"
				checked=$showOnRegistration
				label="plugins.generic.whatsAppContributor.settings.showOnRegistration.label"}
		{/fbvFormSection}
	{/fbvFormArea}

	{fbvFormButtons submitText="common.save"}
</form>

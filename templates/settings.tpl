{**
 * plugins/generic/whatsAppContributor/templates/settings.tpl
 *}
<script>
	$(function() {ldelim}
		$('#whatsAppContributorSettings').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
	{rdelim});
</script>

<form class="pkp_form" id="whatsAppContributorSettings"
	method="POST"
	action="{url router=$smarty.const.ROUTE_COMPONENT op="manage" category="generic" plugin=$pluginName verb="settings" save=true}">

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
		{/fbvFormSection}
	{/fbvFormArea}

	{fbvFormButtons submitText="common.save"}
</form>

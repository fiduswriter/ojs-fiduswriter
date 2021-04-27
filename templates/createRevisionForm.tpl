<div id="fidusWriterSettings">
	<script type="text/javascript">
		$(function () {ldelim}
			// Attach the form handler.
			$('#fidusWriterCreateRevisionForm').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
			{rdelim});
	</script>
	<form
		id="fidusWriterCreateRevisionForm"
		class="pkp_form"
		method="post"
		action="{url router=$smarty.const.ROUTE_COMPONENT op="showCreateFidusRevisionForm" category="generic" plugin=$pluginName save=true}"
	>
		{csrf}
		{include file="common/formErrors.tpl"}
		{fbvElement type="hidden" id="reviewRoundStatus" value=$reviewRoundStatus}
		{fbvElement type="hidden" id="reviewRoundId" value=$reviewRoundId}
		{fbvElement type="hidden" id="oldVersion" value=$oldVersion}
		{fbvElement type="hidden" id="newVersion" value=$newVersion}
		{fbvElement type="hidden" id="apiKey" value=$apiKey}

		{if
			REVIEW_ROUND_STATUS_REVIEWS_COMPLETED === $reviewRoundStatus ||
			REVIEW_ROUND_STATUS_REVISIONS_REQUESTED === $reviewRoundStatus ||
			REVIEW_ROUND_STATUS_ACCEPTED === $reviewRoundStatus ||
			REVIEW_ROUND_STATUS_DECLINED === $reviewRoundStatus
		}
			<p>
				{'plugins.generic.fidusWriter.createRevisionAllowed'|translate}
			</p>
			{fbvFormButtons id="fidusWriterSettingsFormSubmit" submitText="common.create" hideCancel=false}
		{else}
			<p style="color: red; font-weight: 700;">
				{'plugins.generic.fidusWriter.createRevisionDenied'|translate}
			</p>
			{fbvFormButtons id="fidusWriterSettingsFormSubmit" submitText="common.ok" hideCancel=true}
		{/if}
	</form>
</div>

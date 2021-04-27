<?php
import('classes.handler.Handler');

class FidusWriterRevisionHandler extends Handler {
	function initialize($request, $args = null)
	{
		parent::initialize($request);
		AppLocale::requireComponents(
			LOCALE_COMPONENT_PKP_GRID
		);
	}

	function showCreateFidusRevisionForm($args, $request)
	{
		$plugin = PluginRegistry::getPlugin('generic', 'fiduswriterplugin');
		$pluginPath = $plugin->getPluginPath();
		$formTemplate = $plugin->getTemplateResource('createRevisionForm.tpl');

		require_once($pluginPath . '/FidusWriterCreateRevisionForm.inc.php');
		$form = new FidusWriterCreateRevisionForm($formTemplate);

		if ($request->getUserVar('save')) {
			$form->readInputData();
			if ($form->validate()) {
				$form->execute($plugin);
				return DAO::getDataChangedEvent();
			}
		} else {
			$reviewRoundDao = DAORegistry::getDAO('ReviewRoundDAO');
			$reviewRound = $reviewRoundDao->getById($args['review_round_id']);

			$form->setData('reviewRoundStatus', $reviewRound->getStatus());
			$form->setData('reviewRoundId', $args['review_round_id']);
			$form->setData('oldVersion', $args['old_version']);
			$form->setData('newVersion', $args['new_version']);
			$form->setData('apiKey', $args['key']);
			$form->initData();

			return new JSONMessage(true, $form->fetch($request));
		}

		return null;
	}
}

<?php
/**
 * Copyright (c) 2015-2017 Afshin Sadehghi
 * Copyright (c) 2017 Firas Kassawat
 * Copyright (c) 2016-2018 Johannes Wilm
 * License: GNU GPL v2. See LICENSE.md for details.
 */

import('lib.pkp.classes.plugins.GenericPlugin');

class FidusWriterPlugin extends GenericPlugin
{
	/**
	 * @param $category
	 * @param $path
	 * @return bool
	 */

	function register($category, $path, $mainContextId = NULL)
	{
		if (parent::register($category, $path, $mainContextId)) {
			if ($this->getEnabled()) {
				$this->import('helpers.FidusWriterTemplateHelper');
				$templateHelper = new FidusWriterTemplateHelper();
				$this->import('helpers.FidusWriterReviewHelper');
				$reviewHelper = new FidusWriterReviewHelper();
				$this->import('helpers.FidusWriterRoleHelper');
				$roleHelper = new FidusWriterRoleHelper();
				$this->import('helpers.FidusWriterRevisionHelper');
				$revisionHelper = new FidusWriterRevisionHelper();
				$this->import('helpers.FidusWriterSchemaHelper');
				$schemaHelper = new FidusWriterSchemaHelper();

				// Hooks
				HookRegistry::register('articledao::getAdditionalFieldNames', [$this, 'callbackAdditionalFieldNames']);
				HookRegistry::register('LoadComponentHandler', [$this, 'callbackLoadHandler']);
				HookRegistry::register('TemplateManager::fetch', [$templateHelper, 'assignFidusWriterTemplate']);
				HookRegistry::register('reviewassignmentdao::_updateobject', [$reviewHelper, 'notifyReviewAssignment']);
				HookRegistry::register('reviewassignmentdao::_deletebyid', [$reviewHelper, 'notifyReviewUnassignment']);
				HookRegistry::register('reviewrounddao::_insertobject', [$reviewHelper, 'createNewSubmissionRevision']);
				HookRegistry::register('stageassignmentdao::_insertobject', [$roleHelper, 'assignRole']);
				HookRegistry::register('stageassignmentdao::_deletebyall', [$roleHelper, 'unassignRole']);
				HookRegistry::register('EditorAction::recordDecision', [$revisionHelper, 'createRevision']);
				HookRegistry::register('Schema::get::submission', [$schemaHelper, 'hookSubmissionSchema']);

				// Register DAO for Fiduswriter Revisions
				$this->import('dao.FidusWriterReviewRoundRevisionDAO');
				$reviewRoundRevisionDao = new FidusWriterReviewRoundRevisionDAO();
				DAORegistry::registerDAO('FidusWriterReviewRoundRevisionDAO', $reviewRoundRevisionDao);

				// Register Gateway Plugin
				$this->import('FidusWriterGatewayPlugin');
				PluginRegistry::register('gateways', new FidusWriterGatewayPlugin($this), $this->getPluginPath());
			}

			return true;
		}

		return false;
	}

	// BEGIN STANDARD PLUGIN FUNCTIONS
	/**
	 * @copydoc Plugin::getInstallMigration()
	 */
	function getInstallMigration()
	{
		$this->import('FidusWriterMigration');
		return new FidusWriterMigration();
	}

	/**
	 * Get the name of the settings file to be installed on new context
	 * creation.
	 * @return string
	 */
	function getContextSpecificPluginSettingsFile()
	{
		return $this->getPluginPath() . '/settings.xml';
	}

	/**
	 * Override the builtin to get the correct template path.
	 * @return string
	 */
	function getTemplatePath($inCore = false)
	{
		return parent::getTemplatePath($inCore) . '/';
	}

	/**
	 * Get the display name for this plugin.
	 *
	 * @return string
	 */
	function getDisplayName()
	{
		return __('plugins.generic.fidusWriter.displayName');
	}

	/**
	 * Get a description of this plugin.
	 *
	 * @return string
	 */
	function getDescription()
	{
		return __('plugins.generic.fidusWriter.description');
	}

	/**
	 * @see PKPPlugin::getInstallEmailTemplatesFile()
	 */
	function getInstallEmailTemplatesFile() {
		return ($this->getPluginPath() . '/emailTemplates.xml');
	}

	/**
	 * @copydoc Plugin::getActions()
	 */
	function getActions($request, $verb)
	{
		$router = $request->getRouter();
		import('lib.pkp.classes.linkAction.request.AjaxModal');
		return array_merge(
			$this->getEnabled() ? array(
				new LinkAction(
					'settings',
					new AjaxModal(
						$router->url($request, null, null, 'manage', null, array('verb' => 'settings', 'plugin' => $this->getName(), 'category' => 'generic')),
						$this->getDisplayName()
					),
					__('manager.plugins.settings'),
					null
				),
			) : array(),
			parent::getActions($request, $verb)
		);
	}

	/**
	 * @copydoc Plugin::manage()
	 */
	function manage($args, $request)
	{
		switch ($request->getUserVar('verb')) {
			case 'settings':
				AppLocale::requireComponents(LOCALE_COMPONENT_APP_COMMON, LOCALE_COMPONENT_PKP_MANAGER);
				$templateMgr = TemplateManager::getManager($request);
				$templateMgr->register_function('plugin_url', [$this, 'smartyPluginUrl']);

				$this->import('form.FidusWriterSettingsForm');
				$form = new FidusWriterSettingsForm($this);

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
		return parent::manage($args, $request);
	}

	/**
	 * @see Plugin::isSitePlugin()
	 */
	function isSitePlugin()
	{
		return true;
	}

	// END STANDARD PLUGIN FUNCTIONS

	function getApiKey()
	{
		return $this->getSetting(CONTEXT_ID_NONE, 'apiKey');
	}

	/**
	 * Add fieldnames to link submissions to revisions in Fidus Writer
	 * instances.
	 * @see DAO::getAdditionalFieldNames()
	 */
	function callbackAdditionalFieldNames($hookName, $args)
	{
		$returner =& $args[1];
		$returner[] = 'fidusUrl';
		$returner[] = 'fidusId';
	}

	/**
	 * If the loaded component is for creating the revision in Fiduswriter, set the custom callback
	 * @param $hookName
	 * @param $args
	 * @return bool
	 */
	function callbackLoadHandler($hookName, $args)
	{
		$revisionHandlerKeys = [
			'grid.files.review.SelectableReviewRevisionsGridHandler',
			'grid.files.review.WorkflowReviewRevisionsGridHandler'
		];

		if (
			isset($args[0], $args[1]) &&
			in_array($args[0], $revisionHandlerKeys) &&
			'showCreateFidusRevisionForm' === $args[1]
		) {
			$args[0] = "plugins.generic.fidusWriter.FidusWriterRevisionHandler";
			import($args[0]);
			return true;
		}

		return false;
	}
}

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
			/* Note: it looks counterintuitive that only the first listener checks
			whether the plugin is enabled, but the way OJS is set up, if one
			moves the other listeners inside the check, they stop working.

			Alec Smecher: This note doesn't seem right to me -- maybe test again?
			*/
			if ($this->getEnabled()) {
				HookRegistry::register('PluginRegistry::loadCategory', array($this, 'callbackLoadCategory'));
				// Register DAO for Fiduswriter Revisions
				$this->import('FidusWriterReviewRoundRevisionDAO');
				$reviewRoundRevisionDao = new FidusWriterReviewRoundRevisionDAO();
				DAORegistry::registerDAO('FidusWriterReviewRoundRevisionDAO', $reviewRoundRevisionDao);
			}
			HookRegistry::register('reviewassignmentdao::_updateobject', array($this, 'callbackUpdateReviewAssignment'));
			HookRegistry::register('reviewassignmentdao::_deletebyid', array($this, 'callbackRemoveReviewer'));
			HookRegistry::register('reviewrounddao::_insertobject', array($this, 'callbackNewReviewRound'));
			HookRegistry::register('stageassignmentdao::_insertobject', array($this, 'callbackStageAssignment'));
			HookRegistry::register('stageassignmentdao::_deletebyall', array($this, 'callbackStageAssignment'));
			HookRegistry::register('EditorAction::recordDecision', array($this, 'callbackRecordDecision'));
			HookRegistry::register('TemplateManager::fetch', array($this, 'templateFetchCallback'));
			// Add fields fidusId and fidusUrl to submissions
			HookRegistry::register('articledao::getAdditionalFieldNames', array($this, 'callbackAdditionalFieldNames'));
			HookRegistry::register('LoadComponentHandler', array($this, 'callbackLoadHandler'));
			return true;
		}
		return false;
	}

	/**
	 * Get the filename of the ADODB schema for this plugin.
	 */
	function getInstallSchemaFile()
	{
		return $this->getPluginPath() . '/' . 'schema.xml';
	}

	// BEGIN STANDARD PLUGIN FUNCTIONS

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
	 * @see PKPPlugin::getInstallEmailTemplateDataFile()
	 */
	function getInstallEmailTemplateDataFile() {
		return ($this->getPluginPath() . '/locale/{$installedLocale}/emailTemplates.xml');
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
				$templateMgr->register_function('plugin_url', array($this, 'smartyPluginUrl'));

				$this->import('FidusWriterSettingsForm');
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
				break;
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

	function getGatewayPluginUrl()
	{
		$request =& Registry::get('request');
		return $request->getBaseUrl() . '/index.php/index/gateway/plugin/FidusWriterGatewayPlugin';
	}

	function getApiKey()
	{
		return $this->getSetting(CONTEXT_ID_NONE, 'apiKey');
	}

	/**
	 * Retrieve a submission setting from the DB. We use this to get fidusUrl and
	 * fidusId.
	 * @param $hookName
	 * @param $args
	 * @return bool
	 */
	function getSubmissionSetting($submissionId, $settingName)
	{
		$submissionDao = Application::getSubmissionDAO();
		$submission = $submissionDao->getById($submissionId);
		return $submission->getData($settingName);
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

	/**
	 * We override the template for the submission file grid in case of a Fidus
	 * based submission. If the submission is connected to a Fidus Writer instance,
	 * we instead show a login link to get to the fidus writer instance (via the
	 * Fidus Writer Gateway plugin).
	 * @param $hookName
	 * @param $args
	 * @return bool
	 */
	public function templateFetchCallback($hookName, $args)
	{
		/**
		 * @var TemplateManager $templateManager
		 */
		$templateManager = $args[0];
		$templateName = $args[1];
		$completedReviewRoundStatus = [
			REVIEW_ROUND_STATUS_REVIEWS_COMPLETED,
			REVIEW_ROUND_STATUS_REVISIONS_REQUESTED,
			REVIEW_ROUND_STATUS_ACCEPTED,
			REVIEW_ROUND_STATUS_DECLINED
		];

		if ($templateName == 'controllers/tab/workflow/production.tpl') {
			$submissionId = intval($_GET['submissionId']);
			$fidusId = $this->getSubmissionSetting($submissionId, 'fidusId');

			if ($fidusId != false) {
				$stageId = isset($_GET['stageId']) ? intval($_GET['stageId']) : 0;

				$revisionUrl = $this->getGatewayPluginUrl() . '/documentReview?';
				$revisionUrl .= "submissionId=" . $submissionId;
				$revisionUrl .= "&stageId=" . $stageId;
				$revisionUrl .= "&version=5.0.0";
				$templateManager->assign('revisionUrl', $revisionUrl);

				$result =& $args[4];
				$result = $templateManager->fetch(
					$this->getTemplateResource('production.tpl'),
					$args[2],
					$args[3],
					null
				);

				return true;
			}
		} elseif ($templateName == 'controllers/tab/authorDashboard/editorial.tpl') {
			$submissionId = intval($_GET['submissionId']);
			$fidusId = $this->getSubmissionSetting($submissionId, 'fidusId');

			if ($fidusId != false) {
				// This submission is linked to a Fidus Writer instance, so present
				$stageId = isset($_GET['stageId']) ? intval($_GET['stageId']) : 0;

				$revisionUrl = $this->getGatewayPluginUrl() . '/documentReview?';
				$revisionUrl .= "submissionId=" . $submissionId;
				$revisionUrl .= "&stageId=" . $stageId;
				$revisionUrl .= "&version=4.0.0";
				$templateManager->assign('revisionUrl', $revisionUrl);

				$result =& $args[4];
				$result = $templateManager->fetch(
					$this->getTemplateResource('authorDashboardEditorial.tpl'),
					$args[2],
					$args[3],
					null
				);

				return true;
			}
		} elseif ($templateName == 'controllers/grid/grid.tpl') {
			$grid = $templateManager->get_template_vars('grid');
			$title = $grid->getTitle();

			if (
				$title === 'submission.submit.submissionFiles' ||
				$title === 'reviewer.submission.reviewFiles' ||
				$title === 'editor.submission.revisions' ||
				$title === 'submission.finalDraft'
			) {
				// Not sure if there is another way to find this information,
				// but the submissionId is part of the URL of this page.
				$submissionId = intval($_GET['submissionId']);
				$fidusId = $this->getSubmissionSetting($submissionId, 'fidusId');

				if ($fidusId != false) {
					// This submission is linked to a Fidus Writer instance, so present
					// link rather the file overview.
					// If the submission file section is requested, we override the
					// entire grid with a link to the file in Fidus Writer. This way
					// there are no surprises of users accidentally trying to add
					// more files or similar.
					$stageId = isset($_GET['stageId']) ? intval($_GET['stageId']) : 0;
					$round = 0;

					if ($title === 'submission.finalDraft') {
						// "Draft Files" grid of copyediting tab
						// Show link to revision
						$revisionUrl = $this->getGatewayPluginUrl() . '/documentReview?';
						$revisionUrl .= "submissionId=" . $submissionId;
						$revisionUrl .= "&stageId=" . $stageId;
						$revisionUrl .= "&version=4.0.0";
						$templateManager->assign('revisionUrl', $revisionUrl);
						$result =& $args[4];
						$result = $templateManager->fetch(
							$this->getTemplateResource('revisions.tpl'),
							$args[2],
							$args[3],
							null
						);

						return true;
					} else {
						/**
						 * @var ReviewRound $reviewRound
						 */
						if (isset($_GET['reviewRoundId'])) {
							$reviewRoundId = $_GET['reviewRoundId'];
							$reviewRoundDao = DAORegistry::getDAO('ReviewRoundDAO');
							$reviewRound = $reviewRoundDao->getById($reviewRoundId);
							$round = $reviewRound->getRound();
						}

						if ($title === 'editor.submission.revisions') {
							// Revisions
							if (isset($reviewRound)) {
								$status = $reviewRound->getStatus();
								$reviewRoundRevisionDao = DAORegistry::getDAO('FidusWriterReviewRoundRevisionDAO');
								$reviewRoundRevision = $reviewRoundRevisionDao->getRoundRevision($reviewRound->getId());
								if (
									$reviewRoundRevision instanceof DataObject &&
									!empty($reviewRoundRevision->getData('revision_url'))
								) {
									// Show link to revision
									$revisionUrl = $reviewRoundRevision->getData('revision_url');
									$templateManager->assign('revisionUrl', $revisionUrl);
								} else if (in_array($status, $completedReviewRoundStatus)) {
									// If review is completed, allow editor to create revision
									import('lib.pkp.classes.linkAction.request.AjaxModal');
									$request = Registry::get('request');
									$action = new LinkAction(
										'createFidusRevision',
										new AjaxModal(
											$request->getRouter()->url($request, null, null, 'showCreateFidusRevisionForm', null, [
												'review_round_id' => $reviewRound->getId(),
												'old_version' => $this->stageToVersion($stageId, $round, 'Reviewer'),
												'new_version' => $this->stageToVersion($stageId, $round, 'Author'),
												'key' => $this->getApiKey(),
											]),
											__('plugins.generic.fidusWriter.createRevision'),
											'modal_add_item'
										),
										__('plugins.generic.fidusWriter.createRevision'),
										'add_item'
									);
									$templateManager->assign('createRevisionAction', $action);
								}
							}

							$result =& $args[4];
							$result = $templateManager->fetch(
								$this->getTemplateResource('revisions.tpl'),
								$args[2],
								$args[3],
								null
							);

							return true;
						} else {
							// Submissions
							$versionString = $this->stageToVersion($stageId, $round, 'Reviewer');
							$openInFidusUrl = $this->getGatewayPluginUrl() . '/documentReview?';
							$openInFidusUrl .= http_build_query([
								'submissionId' => $submissionId,
								'stageId' => $stageId,
								'version' => $versionString
							]);

							$templateManager->assign('openInFidusUrl', $openInFidusUrl);
							$result =& $args[4];
							$result = $templateManager->fetch(
								$this->getTemplateResource('submissionFiles.tpl'),
								$args[2],
								$args[3],
								null
							);

							return true;
						}
					}
				}
			}
		}

		return false;
	}

	/**
	 * Sends information about a registered reviewer for a specific submission
	 * to Fidus Writer, if the submission is of a document in Fidus Writer.
	 * @param $hookName
	 * @param $args
	 * @return bool
	 */
	function callbackUpdateReviewAssignment($hookName, $args)
	{
		$row =& $args[1];
		$submissionId = $row[0];
		$fidusId = $this->getSubmissionSetting($submissionId, 'fidusId');
		if ($fidusId === false) {
			// The submission is not connected with Fidus Writer, so we don't handle it.
			return false;
		}
		$stageId = $row[2];
		$round = $row[4];
		$versionString = $this->stageToVersion($stageId, $round, 'Reviewer');
		$reviewerId = $row[1];
		$reviewer = $this->getUser($reviewerId);
		$recommendation = $row[6]; // None if not yet accepted review. 0 if accepted review.
		$declined = $row[7]; // 1 if declined, otherwise 0
		$reviewMethod = $row[3];

		$fidusUrl = $this->getSubmissionSetting($submissionId, 'fidusUrl');

		$url = false;
		$dataArray = [
			'user_id' => $reviewerId,
			'key' => $this->getApiKey()
		];

		if ($declined === REVIEW_ASSIGNMENT_STATUS_DECLINED) {
			// declined
			// Then send the email address of reviewer to Fidus Writer.
			$url = $fidusUrl . '/api/ojs/remove_reviewer/' . $fidusId . '/' . $versionString . '/';
		} elseif ($recommendation === '0') { // Not sure what variable name this '0' corresponds to.
			// reviewer accepted review
			if ($reviewMethod === SUBMISSION_REVIEW_METHOD_OPEN) {
				$dataArray['access_rights'] = 'comment';
			} else {
				$dataArray['access_rights'] = 'review';
			}
			$url = $fidusUrl . '/api/ojs/accept_reviewer/' . $fidusId . '/' . $versionString . '/';
		} elseif (is_null($recommendation)) {
			// newly registered reviewer
			$dataArray['email'] = $reviewer->getEmail();
			$dataArray['username'] = $reviewer->getUserName();
			$url = $fidusUrl . '/api/ojs/add_reviewer/' . $fidusId . '/' . $versionString . '/';
		}

		if ($url) {
			$this->sendPostRequest($url, $dataArray);
		}
		return false;
	}

	/**
	 * Sends information to Fidus Writer that a given reviewer has been removed
	 * from a submission so that Fidus Writer also removes the access the reviewer
	 * has had to the document in question.
	 * @param $hookName
	 * @param $args
	 * @return bool
	 */
	function callbackRemoveReviewer($hookName, $args)
	{
		$reviewAssignmentId =& $args[1];
		$reviewAssignment = $this->getReviewAssignmentByReviewId($reviewAssignmentId);
		$submissionId = $reviewAssignment->getSubmissionId();
		$round = $reviewAssignment->getRound();
		$stageId = $reviewAssignment->getStageId();
		$versionString = $this->stageToVersion($stageId, $round, 'Reviewer');

		$fidusId = $this->getSubmissionSetting($submissionId, 'fidusId');

		if ($fidusId === false) {
			// The article was not connected with Fidus Writer, so we send no
			// notification.
			return false;
		}

		$dataArray = [
			'user_id' => $reviewAssignment->getReviewerId(),
			'key' => $this->getApiKey()
		];
		// Then send the email address of reviewer to Fidus Writer.
		$fidusUrl = $this->getSubmissionSetting($submissionId, 'fidusUrl');
		$url = $fidusUrl . '/api/ojs/remove_reviewer/' . $fidusId . '/' . $versionString . '/';
		$this->sendPostRequest($url, $dataArray);
		return false;
	}


	/**
	 * Creates new SubmissionRevision in Fidus Writer
	 * @param $hookname
	 * @param $args
	 */
	function callbackNewReviewRound($hookname, $args)
	{
		$row =& $args[1];
		$submissionId = intval($row[0]);
		$stageId = intval($row[1]);
		$round = intval($row[2]);

		$fidusId = $this->getSubmissionSetting($submissionId, 'fidusId');
		if ($fidusId == false) {
			// Not connected to Fidus Writer
			return false;
		}

		if ($round == 1) {
			$oldVersionString = "1.0.0";
			// TODO: What happens if there is a stage 2?
		} else {
			$oldRound = $round - 1;
			$reviewRoundDao = DAORegistry::getDAO('ReviewRoundDAO');
			$oldReviewRound = $reviewRoundDao->getReviewRound($submissionId, $stageId, $oldRound);
			// We need to copy a file from the previous revision round. If the author has
			// submitted something for the round, we use that version.
			// Otherwise, we use the Reviewer's version.
			$this->import('FidusWriterReviewRoundRevisionDAO');
			$reviewRoundRevisionDao = new FidusWriterReviewRoundRevisionDAO();
			$reviewRoundRevision = $reviewRoundRevisionDao->getRoundRevision($oldReviewRound->getId());
			if (
				$reviewRoundRevision instanceof DataObject &&
				!empty($reviewRoundRevision->getData('revision_url'))
			) {
				$oldRevisionType = 'Author';
			} else {
				$oldRevisionType = 'Reviewer';
			}
			$oldVersionString = $this->stageToVersion($stageId, $oldRound, $oldRevisionType);
		}

		$newVersionString = $this->stageToVersion($stageId, $round, 'Reviewer');
		$assignedUsers = $this->getAssignedUserIds($submissionId, $stageId);

		$dataArray = [
			'old_version' => $oldVersionString,
			'new_version' => $newVersionString,
			'granted_users' => implode(',', $assignedUsers),
			'key' => $this->getApiKey(), //shared key between OJS and Editor software
		];

		$fidusUrl = $this->getSubmissionSetting($submissionId, 'fidusUrl');
		$url = $fidusUrl . '/api/ojs/create_copy/' . $fidusId . '/';
		$this->sendPostRequest($url, $dataArray);

		return false;
	}

	function callbackStageAssignment($hookname, $args)
	{
		if (
			"stageassignmentdao::_deletebyall" === $hookname ||
			"stageassignmentdao::_insertobject" === $hookname
		) {
			$submissionId = $args[1][0];
			$userGroupId = $args[1][1];
			$userId = $args[1][2];
			/**
			 * @var UserGroupDAO $userGroupDao
			 */
			$userGroupDao = DAORegistry::getDAO('UserGroupDAO');
			$userGroup = $userGroupDao->getById($userGroupId);
			$role = $userGroup->getRoleId();

			if (in_array($role, [ROLE_ID_MANAGER, ROLE_ID_SUB_EDITOR, ROLE_ID_SITE_ADMIN, ROLE_ID_ASSISTANT])) {
				$roleType = "editor";
			} elseif (in_array($role, [ROLE_ID_AUTHOR])) {
				$roleType = "author";
			} else {
				return false;
			}

			$data = [
				'key' => $this->getApiKey(),
				'user_id' => $userId,
				'role' => $role,
				'stage_ids' => ''
			];

			$userGroupStages = $userGroupDao->getAssignedStagesByUserGroupId($userGroup->getContextId(), $userGroup->getId());
			if (!empty($userGroupStages)) {
				$data['stage_ids'] = implode(',', array_keys($userGroupStages));
			}

			$fidusId = $this->getSubmissionSetting($submissionId, 'fidusId');
			$fidusUrl = $this->getSubmissionSetting($submissionId, 'fidusUrl');

			if ("stageassignmentdao::_insertobject" === $hookname) {
				$user = $this->getUser($userId);
				$fidusUrl .= "/api/ojs/add_{$roleType}/{$fidusId}/";
				$data['email'] = $user->getEmail();
				$data['username'] = $user->getUserName();
			} else {
				$fidusUrl .= "/api/ojs/remove_{$roleType}/{$fidusId}/";
			}

			$this->sendPostRequest(
				$fidusUrl,
				$data
			);
		}

		return false;
	}

	// Create Revision files for Copyediting and Production steps
	function callbackRecordDecision($hookname, $args)
	{
		if ('EditorAction::recordDecision' === $hookname) {
			/**
			 * @var Article $submission
			 */
			$submission = $args[0];
			$fidusId = $submission->getData('fidusId');

			if ($fidusId) {
				// submission is connected to Fidus Writer
				$decision = $args[1]['decision'];
				$stageId = intval($submission->getData('stageId'));

				if (4 > $stageId && SUBMISSION_EDITOR_DECISION_ACCEPT == $decision) {
					// Submission sent to copyediting from a previous step
					$oldRound = 0;
					$oldRevisionType = "Reviewer";

					if (3 == $stageId) {
						// If the submission is in the review step, check for the review revision file
						$reviewRoundDao = DAORegistry::getDAO('ReviewRoundDAO');
						$reviewRound = $reviewRoundDao->getLastReviewRoundBySubmissionId($submission->getId(), $stageId);

						if ($reviewRound instanceof ReviewRound) {
							$oldRound = $reviewRound->getRound();

							// Check if review revision exists
							$this->import('FidusWriterReviewRoundRevisionDAO');
							$reviewRoundRevisionDao = new FidusWriterReviewRoundRevisionDAO();
							$reviewRoundRevision = $reviewRoundRevisionDao->getRoundRevision($reviewRound->getId());
							if (
								$reviewRoundRevision instanceof DataObject &&
								!empty($reviewRoundRevision->getData('revision_url'))
							) {
								$oldRevisionType = "Author";
							}
						}
					}

					$oldVersionString = $this->stageToVersion($stageId, $oldRound, $oldRevisionType);
					$newVersionString = $this->stageToVersion(4);
					// Assigned users
					$assiendUsers = $this->getAssignedUserIds($submission->getId(), 4);

					$dataArray = [
						'old_version' => $oldVersionString,
						'new_version' => $newVersionString,
						'granted_users' => implode(',', $assiendUsers),
						'key' => $this->getApiKey(), //shared key between OJS and Editor software
					];

					$fidusUrl = $submission->getData('fidusUrl');
					$url = $fidusUrl . '/api/ojs/create_copy/' . $fidusId . '/';
					$this->sendPostRequest($url, $dataArray);
				} elseif (4 == $stageId && SUBMISSION_EDITOR_DECISION_SEND_TO_PRODUCTION == $decision) {
					// Submission proceeded to production from copyediting
					$oldVersionString = $this->stageToVersion(REVIEW_ROUND_STATUS_ACCEPTED);
					$newVersionString = $this->stageToVersion(5);
					// Assigned users
					$assiendUsers = $this->getAssignedUserIds($submission->getId(), 5);

					$dataArray = [
						'old_version' => $oldVersionString,
						'new_version' => $newVersionString,
						'granted_users' => implode(',', $assiendUsers),
						'key' => $this->getApiKey(), //shared key between OJS and Editor software
					];

					$fidusUrl = $submission->getData('fidusUrl');
					$url = $fidusUrl . '/api/ojs/create_copy/' . $fidusId . '/';
					$this->sendPostRequest($url, $dataArray);
				}
			}
		}

		return false;
	}

	/**
	 * @param $hookName string
	 * @param $args array
	 * @return bool
	 **/
	function callbackLoadCategory($hookName, $args)
	{
		$category =& $args[0];
		$plugins =& $args[1];
		switch ($category) {
			case 'gateways':
				$this->import('FidusWriterGatewayPlugin');
				$gatewayPlugin = new FidusWriterGatewayPlugin($this->getName());
				$plugins[$gatewayPlugin->getSeq()][$gatewayPlugin->getPluginPath()] = $gatewayPlugin;
				break;
		}
		return false;
	}

	function getUser($userId)
	{
		$userDao = DAORegistry::getDAO('UserDAO');
		return $userDao->getById($userId);
	}

	/**
	 * @param $submissionId
	 * @param $stageId
	 * @return int[]
	 */
	function getAssignedUserIds($submissionId, $stageId)
	{
		/**
		 * @var StageAssignmentDAO $stageAssignmentDao
		 */
		$stageAssignmentDao = DAORegistry::getDAO('StageAssignmentDAO');
		$stageAssignments = $stageAssignmentDao->getBySubmissionAndStageId($submissionId, $stageId);

		$userIds = [];

		if (!$stageAssignments->wasEmpty) {
			foreach ($stageAssignments->records as $assignment) {
				$userIds[] = intval($assignment['user_id']);
			}
		}

		return $userIds;
	}


	/**
	 * @param $userId
	 * @return string
	 */
	function getUserEmail($userId)
	{
		/** @var UserDAO $userDao */
		$userDao = DAORegistry::getDAO('UserDAO');
		return $userDao->getUserEmail($userId);
	}

	/**
	 * @param $userId
	 * @return string
	 */
	function getUserName($userId)
	{
		/** @var UserDAO $userDao */
		$userDao = DAORegistry::getDAO('UserDAO');
		/** @var ReviewAssignment $reviewAssignment */
		$user = $userDao->getById($userId);
		/** @var User $user */
		return $user->getUsername($userId);
	}


	/**
	 * @param $reviewId
	 * @return $reviewAssignment
	 */
	function getReviewAssignmentByReviewId($reviewId)
	{
		/** @var ReviewAssignmentDAO $RADao */
		$RADao = DAORegistry::getDAO('ReviewAssignmentDAO');
		$reviewAssignmentArray = $RADao->getById($reviewId);
		// TODO: Find out if there are any problems here if this assignment
		// contains more than one reviewer.
		if (is_array($reviewAssignmentArray)) {
			$reviewAssignment = $reviewAssignmentArray[0];
		} else {
			$reviewAssignment = $reviewAssignmentArray;
		}
		return $reviewAssignment;
	}


	/**
	 * This function converts from the kind of versioning information of a document
	 * as it's stored to the versioning information as it's stored on the FW side.
	 * The main difference is this:
	 * On OJS, a stageId is used to determine whether the document is in
	 * submission (1), internal review (2), external review (3), copyediting (4),
	 * production (5) stage.
	 * Within the review stage, one has to know the round.
	 * Each round allows for the upload of files, first of the reviewer, then of
	 * the author. So we choose to match two version numbers as used in FW to each
	 * round, the first one using $revisionType 'Reviewer' (reviewer), the second 'Author'
	 * (author).
	 * In FW, we have a version string, similar to a software version number with
	 * three parts divided by dots, such as: 1.0.0 or 3.1.5 . These numbers are:
	 * - The first number represents the stage ID, so it is 1-5.
	 * - The second number represents the round if there is one. Otherwise it is 0.
	 * - The third number is 0 for the 'Reviewer' version within a round, and 5 for the
	 * 'Author' version.
	 * @param $stageId
	 * @param $reviewRound
	 * @param $revisionType
	 * @return int
	 */
	function stageToVersion($stageId, $round = 0, $revisionType = 'Reviewer')
	{
		switch ($stageId) {
			case 0:
			case 1:
				// submission
				return '1.0.0';
				break;
			case 2:
				// internal review
				// TODO: does this also operate with review rounds? Couldn't
				// find it how to do internal reviews.
				if ($revisionType == 'Reviewer') {
					return '2.' . $round . '.0';
				} else {
					return '2.' . $round . '.5';
				}
				break;
			case 3:
				if ($revisionType == 'Reviewer') {
					return '3.' . $round . '.0';
				} else {
					return '3.' . $round . '.5';
				}
				break;
			case 4:
				return '4.0.0';
				break;
			case 5:
				return '5.0.0';
				break;
		}
	}

	/**
	 * @param $requestType
	 * @param $url
	 * @param $dataArray
	 * @return string
	 */
	function sendRequest($requestType, $url, $dataArray)
	{
		$curl = curl_init();
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);

		if ("POST" === $requestType) {
			curl_setopt($curl, CURLOPT_URL, $url);
			curl_setopt($curl, CURLOPT_POST, true);
			curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($dataArray));
		} else {
			$query = parse_url($url, PHP_URL_QUERY);
			$url .= $query ? '&' : '?';
			$url .= http_build_query($dataArray);
			curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'GET');
			curl_setopt($curl, CURLOPT_URL, $url);
		}

		$result = curl_exec($curl);
		curl_close($curl);

		/* Handle error */
		if (!$result) {
			echo $result;
		}

		return $result;
	}


	/**
	 * @param $url
	 * @param $dataArray
	 * @return string
	 */
	function sendPostRequest($url, $dataArray)
	{
		$result = $this->sendRequest('POST', $url, $dataArray);
		return $result;
	}

}

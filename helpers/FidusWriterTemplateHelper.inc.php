<?php

class FidusWriterTemplateHelper
{
	const TPL_WORKFLOW_PRODUCTION = 'controllers/tab/workflow/production.tpl';
	const TPL_AUTHOR_DASHBOARD_EDITORIAL = 'controllers/tab/authorDashboard/editorial.tpl';
	const TPL_GRID = 'controllers/grid/grid.tpl';

	/**
	 * Hook TemplateManager::fetch.
	 * We override the template for the submission file grid in case of a Fidus
	 * based submission. If the submission is connected to a Fidus Writer instance,
	 * we instead show a login link to get to the fidus writer instance (via the
	 * Fidus Writer Gateway plugin).
	 *
	 * @param $hookName
	 * @param $args
	 * @return bool
	 */
	public function assignFidusWriterTemplate($hookName, $args)
	{
		/**
		 * @var TemplateManager $templateManager
		 */
		$templateManager = $args[0];
		$templateName = $args[1];

		if (in_array($templateName, [self::TPL_WORKFLOW_PRODUCTION, self::TPL_AUTHOR_DASHBOARD_EDITORIAL, self::TPL_GRID])) {
			if (isset($_GET['submissionId'])) {
				// Not sure if there is another way to find this information,
				// but the submissionId is part of the URL of this page.
				$submissionId = intval($_GET['submissionId']);
				$fidusId = FidusWriterPluginHelper::getSubmissionSetting($submissionId, 'fidusId');

				if ($fidusId) {
					$stageId = isset($_GET['stageId']) ? intval($_GET['stageId']) : 0;

					switch ($templateName) {
						case self::TPL_WORKFLOW_PRODUCTION:
							$revisionUrl = $this->getRevisionUrl($submissionId, $stageId, '5.0.0');
							$templateManager->assign('revisionUrl', $revisionUrl);
							$templateResource = 'production.tpl';

							break;

						case self::TPL_AUTHOR_DASHBOARD_EDITORIAL:
							$revisionUrl = $this->getRevisionUrl($submissionId, $stageId, '4.0.0');
							$templateManager->assign('revisionUrl', $revisionUrl);
							$templateResource = 'authorDashboardEditorial.tpl';

							break;

						case self::TPL_GRID:
							$grid = $templateManager->getTemplateVars('grid');
							$title = $grid->getTitle();
							$gridTitles = [
								'submission.submit.submissionFiles',
								'reviewer.submission.reviewFiles',
								'editor.submission.revisions',
								'submission.finalDraft'
							];

							if (in_array($title, $gridTitles)) {
								// This submission is linked to a Fidus Writer instance, so present
								// link rather the file overview.
								// If the submission file section is requested, we override the
								// entire grid with a link to the file in Fidus Writer. This way
								// there are no surprises of users accidentally trying to add
								// more files or similar.
								$round = 0;

								if ($title === 'submission.finalDraft') {
									// "Draft Files" grid of copyediting tab
									// Show link to revision
									$revisionUrl = $this->getRevisionUrl($submissionId, $stageId, '4.0.0');
									$templateManager->assign('revisionUrl', $revisionUrl);
									$templateResource = 'revisions.tpl';
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
											$sessionManager = SessionManager::getManager();
											$userSession = $sessionManager->getUserSession();
											$user = $userSession->getUser();
											$assignedAsEditor = FidusWriterPluginHelper::isUserAssignedAsEditor($submissionId, $stageId, $user->getId());
											$assignments = $this->getRevisionGridTemplateAssignments($reviewRound, $stageId, $assignedAsEditor);
											if ($assignments) {
												foreach ($assignments as $key => $value) {
													$templateManager->assign($key, $value);
												}
											}
										}

										$templateResource = 'revisions.tpl';
									} else {
										// Submissions
										$versionString = FidusWriterPluginHelper::stageToVersion($stageId, $round, 'Reviewer');
										$revisionUrl = $this->getRevisionUrl($submissionId, $stageId, $versionString);
										$templateManager->assign('openInFidusUrl', $revisionUrl);
										$templateResource = 'submissionFiles.tpl';
									}
								}
							}

							break;
					}

					if (!empty($templateResource)) {
						$plugin = FidusWriterPluginHelper::getFidusWriterPlugin();
						$result =& $args[4];
						$result = $templateManager->fetch(
							$plugin->getTemplateResource($templateResource),
							$args[2],
							$args[3],
							null
						);

						return true;
					}
				}
			}
		}

		return false;
	}

	protected function getRevisionUrl($submissionId, $stageId, $version)
	{
		$fidusId = FidusWriterPluginHelper::getSubmissionSetting($submissionId, 'fidusId');
		$fidusUrl = FidusWriterPluginHelper::getSubmissionSetting($submissionId, 'fidusUrl');
		$checkRevisionUrl = $fidusUrl . '/api/ojs/check_revision/' . $fidusId . '/' . $version . '/';

		$sessionManager = SessionManager::getManager();
		$userSession = $sessionManager->getUserSession();
		$user = $userSession->getUser();

		$submissionDao = DAORegistry::getDAO('SubmissionDAO');
		$submission = $submissionDao->getById($submissionId);
		$journalId = $submission->getContextId();

		$existsRevision = FidusWriterPluginHelper::sendRequest('GET', $checkRevisionUrl, [
			'user_id' => $user->getId(),
			'is_editor' => FidusWriterPluginHelper::isEditor($user->getId(), $journalId),
			'key' => FidusWriterPluginHelper::getFidusWriterPlugin()->getApiKey()
		]);

		if (intval($existsRevision)) {
			$revisionUrl = FidusWriterPluginHelper::getGatewayPluginUrl() . '/documentReview?';
			$revisionUrl .= http_build_query([
				'submissionId' => $submissionId,
				'stageId' => $stageId,
				'version' => $version,
			]);

			return $revisionUrl;
		}

		return null;
	}

	protected function getRevisionGridTemplateAssignments($reviewRound, $stageId, $assignedAsEditor)
	{
		$status = $reviewRound->getStatus();
		$reviewRoundRevisionDao = DAORegistry::getDAO('FidusWriterReviewRoundRevisionDAO');
		$reviewRoundRevision = $reviewRoundRevisionDao->getRoundRevision($reviewRound->getId());
		if (
			$reviewRoundRevision instanceof DataObject &&
			!empty($reviewRoundRevision->getData('revision_url'))
		) {
			// Show link to revision, if exists
			$revisionUrl = $reviewRoundRevision->getData('revision_url');
			return ['revisionUrl' => $revisionUrl];
		} else if ($assignedAsEditor) {
			$completedReviewRoundStatus = [
				REVIEW_ROUND_STATUS_REVIEWS_COMPLETED,
				REVIEW_ROUND_STATUS_REVISIONS_REQUESTED,
				REVIEW_ROUND_STATUS_ACCEPTED,
				REVIEW_ROUND_STATUS_DECLINED
			];

			if (in_array($status, $completedReviewRoundStatus)) {
				// If review is completed, allow editor to create revision
				import('lib.pkp.classes.linkAction.request.AjaxModal');
				$request = Registry::get('request');
				$round = $reviewRound->getRound();
				$action = new LinkAction(
					'createFidusRevision',
					new AjaxModal(
						$request->getRouter()->url($request, null, null, 'showCreateFidusRevisionForm', null, [
							'review_round_id' => $reviewRound->getId(),
							'old_version' => FidusWriterPluginHelper::stageToVersion($stageId, $round, 'Reviewer'),
							'new_version' => FidusWriterPluginHelper::stageToVersion($stageId, $round, 'Author'),
							'key' => FidusWriterPluginHelper::getFidusWriterPlugin()->getApiKey(),
						]),
						__('plugins.generic.fidusWriter.createRevision'),
						'modal_add_item'
					),
					__('plugins.generic.fidusWriter.createRevision'),
					'add_item'
				);

				return ['createRevisionAction' => $action];
			}
		}

		return null;
	}
}

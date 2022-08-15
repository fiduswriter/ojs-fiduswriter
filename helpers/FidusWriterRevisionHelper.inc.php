<?php
class FidusWriterRevisionHelper {
	/**
	 * Hook EditorAction::recordDecision
	 * Create revisions for copyediting and production steps,
	 * when editor took decision to step forward
	 *
	 * @param $hookname
	 * @param $args
	 * @return false
	 */
	public function createRevision($hookname, $args)
	{
		/**
		 * @var Submission $submission
		 */
		$submission = $args[0];
		$fidusId = $submission->getData('fidusId');

		if ($fidusId) {
			// submission is connected to Fidus Writer
			$decision = $args[1]['decision'];
			$stageId = intval($submission->getData('stageId'));
			$plugin = FidusWriterPluginHelper::getFidusWriterPlugin();

			if (3 === $stageId && SUBMISSION_EDITOR_DECISION_PENDING_REVISIONS == $decision) {
				// Request Revisions
				$reviewRoundDao = DAORegistry::getDAO('ReviewRoundDAO');
				$reviewRound = $reviewRoundDao->getLastReviewRoundBySubmissionId($submission->getId(), $stageId);

				if ($reviewRound instanceof ReviewRound) {
					// Check if review revision for rework (3.x.5) already exists
					$plugin->import('dao.FidusWriterReviewRoundRevisionDAO');
					$reviewRoundRevisionDao = new FidusWriterReviewRoundRevisionDAO();
					$reviewRoundRevision = $reviewRoundRevisionDao->getRoundRevision($reviewRound->getId());
					if (
						!$reviewRoundRevision instanceof DataObject ||
						empty($reviewRoundRevision->getData('revision_url'))
					) {
						// no revision for rework found, so create rework revision in FW
						$oldVersionString = FidusWriterPluginHelper::stageToVersion($stageId, $reviewRound->getRound(), 'Reviewer');
						$newVersionString = FidusWriterPluginHelper::stageToVersion($stageId, $reviewRound->getRound(), 'Author');
						// Assigned users
						$assiendUsers = FidusWriterPluginHelper::getAssignedUserIds($submission->getId(), $reviewRound->getStageId());

						// Insert reviewRoundRevision
						/**
						 * @var FidusWriterReviewRoundRevisionDAO $reviewRoundRevisionDao
						 * @var DataObject $reviewRoundRevision
						 */
						$reviewRoundRevisionDao = DAORegistry::getDAO('FidusWriterReviewRoundRevisionDAO');
						// Get URL to revision in FidusWriter
						$revisionUrl = FidusWriterPluginHelper::getGatewayPluginUrl() . '/documentReview?submissionId=' . $submission->getId() . '&stageId=' . $reviewRound->getStageId() . '&version=' . $newVersionString;
						$reviewRoundRevision = new DataObject();
						$reviewRoundRevision->setData('review_round', $reviewRound->getId());
						$reviewRoundRevision->setData('revision_url', $revisionUrl);
						$reviewRoundRevisionDao->save($reviewRoundRevision);
					}
				}
			} elseif (4 > $stageId && SUBMISSION_EDITOR_DECISION_ACCEPT == $decision) {
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
						$plugin->import('dao.FidusWriterReviewRoundRevisionDAO');
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

				$oldVersionString = FidusWriterPluginHelper::stageToVersion($stageId, $oldRound, $oldRevisionType);
				$newVersionString = FidusWriterPluginHelper::stageToVersion(4);
				// Assigned users
				$assiendUsers = FidusWriterPluginHelper::getAssignedUserIds($submission->getId(), 4);
			} elseif (4 === $stageId && SUBMISSION_EDITOR_DECISION_SEND_TO_PRODUCTION == $decision) {
				// Submission proceeded to production from copyediting
				$oldVersionString = FidusWriterPluginHelper::stageToVersion(REVIEW_ROUND_STATUS_ACCEPTED);
				$newVersionString = FidusWriterPluginHelper::stageToVersion(5);
				// Assigned users
				$assiendUsers = FidusWriterPluginHelper::getAssignedUserIds($submission->getId(), 5);
			}

			if (isset($oldVersionString, $newVersionString, $assiendUsers)) {
				$dataArray = [
					'old_version' => $oldVersionString,
					'new_version' => $newVersionString,
					'granted_users' => implode(',', $assiendUsers),
					'key' => $plugin->getApiKey(), //shared key between OJS and Editor software
				];

				$fidusUrl = $submission->getData('fidusUrl');
				$fidusUrl .= '/api/ojs/create_copy/' . $fidusId . '/';
				FidusWriterPluginHelper::sendPostRequest($fidusUrl, $dataArray);
			}
		}

		return false;
	}
}

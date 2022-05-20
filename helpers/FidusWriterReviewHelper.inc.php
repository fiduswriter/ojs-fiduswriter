<?php

class FidusWriterReviewHelper
{
	/**
	 * Hook reviewassignmentdao::_updateobject
	 * Sends information about a registered reviewer for a specific submission
	 * to Fidus Writer, if the submission is of a document in Fidus Writer.
	 *
	 * @param $hookName
	 * @param $args
	 * @return false
	 */
	public function notifyReviewAssignment($hookName, $args)
	{
		$row =& $args[1];
		$submissionId = $row[0];
		$fidusId = FidusWriterPluginHelper::getSubmissionSetting($submissionId, 'fidusId');

		if ($fidusId) {
			$stageId = $row[2];
			$round = $row[4];
			$versionString = FidusWriterPluginHelper::stageToVersion($stageId, $round, 'Reviewer');
			$reviewerId = $row[1];
			$reviewer = FidusWriterPluginHelper::getUser($reviewerId);
			$recommendation = $row[6]; // None if not yet accepted review. 0 if accepted review.
			$declined = $row[7]; // 1 if declined, otherwise 0
			$reviewMethod = $row[3];

			$fidusUrl = FidusWriterPluginHelper::getSubmissionSetting($submissionId, 'fidusUrl');

			$url = false;
			$plugin = FidusWriterPluginHelper::getFidusWriterPlugin();
			$dataArray = [
				'user_id' => $reviewerId,
				'key' => $plugin->getApiKey()
			];

			if ($declined === REVIEW_ASSIGNMENT_STATUS_DECLINED) {
				// declined
				// Then send the email address of reviewer to Fidus Writer.
				$url = $fidusUrl . '/api/ojs/remove_reviewer/' . $fidusId . '/' . $versionString . '/';
			} elseif ($recommendation === 0) { // Not sure what variable name this '0' corresponds to.
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
				FidusWriterPluginHelper::sendPostRequest($url, $dataArray);
			}
		}

		return false;
	}

	/**
	 * Hook reviewassignmentdao::_deletebyid
	 * Sends information to Fidus Writer that a given reviewer has been removed
	 * from a submission so that Fidus Writer also removes the access the reviewer
	 * has had to the document in question.
	 *
	 * @param $hookName
	 * @param $args
	 * @return false
	 */
	public function notifyReviewUnassignment($hookName, $args)
	{
		$reviewAssignmentId =& $args[1];

		/** @var ReviewAssignmentDAO $RADao */
		$RADao = DAORegistry::getDAO('ReviewAssignmentDAO');
		$reviewAssignmentArray = $RADao->getById($reviewAssignmentId);
		// TODO: Find out if there are any problems here if this assignment contains more than one reviewer.
		$reviewAssignment = is_array($reviewAssignmentArray) ? $reviewAssignmentArray[0] : $reviewAssignmentArray;

		$submissionId = $reviewAssignment->getSubmissionId();
		$fidusId = FidusWriterPluginHelper::getSubmissionSetting($submissionId, 'fidusId');

		if ($fidusId) {
			$round = $reviewAssignment->getRound();
			$stageId = $reviewAssignment->getStageId();
			$versionString = FidusWriterPluginHelper::stageToVersion($stageId, $round, 'Reviewer');

			$plugin = FidusWriterPluginHelper::getFidusWriterPlugin();
			$dataArray = [
				'user_id' => $reviewAssignment->getReviewerId(),
				'key' => $plugin->getApiKey()
			];

			// Then send the email address of reviewer to Fidus Writer.
			$url = FidusWriterPluginHelper::getSubmissionSetting($submissionId, 'fidusUrl');
			$url .= '/api/ojs/remove_reviewer/' . $fidusId . '/' . $versionString . '/';
			FidusWriterPluginHelper::sendPostRequest($url, $dataArray);
		}

		return false;
	}

	/**
	 * Hook reviewrounddao::_insertobject
	 * Creates new SubmissionRevision in Fidus Writer
	 *
	 * @param $hookname
	 * @param $args
	 * @return false
	 */
	public function createNewSubmissionRevision($hookname, $args)
	{
		$row =& $args[1];
		$submissionId = intval($row[0]);
		$stageId = intval($row[1]);
		$round = intval($row[2]);
		$fidusId = FidusWriterPluginHelper::getSubmissionSetting($submissionId, 'fidusId');

		if ($fidusId) {
			$plugin = FidusWriterPluginHelper::getFidusWriterPlugin();

			if ($round === 1) {
				$oldVersionString = "1.0.0";
				// TODO: What happens if there is a stage 2?
			} else {
				$oldRound = $round - 1;
				/** @var ReviewRoundDAO $reviewRoundDao */
				$reviewRoundDao = DAORegistry::getDAO('ReviewRoundDAO');
				$oldReviewRound = $reviewRoundDao->getReviewRound($submissionId, $stageId, $oldRound);
				// We need to copy a file from the previous revision round. If the author has
				// submitted something for the round, we use that version.
				// Otherwise, we use the Reviewer's version.
				$plugin->import('dao.FidusWriterReviewRoundRevisionDAO');
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

				$oldVersionString = FidusWriterPluginHelper::stageToVersion($stageId, $oldRound, $oldRevisionType);
			}

			$newVersionString = FidusWriterPluginHelper::stageToVersion($stageId, $round, 'Reviewer');
			$assignedUsers = FidusWriterPluginHelper::getAssignedUserIds($submissionId, $stageId);

			$dataArray = [
				'old_version' => $oldVersionString,
				'new_version' => $newVersionString,
				'granted_users' => implode(',', $assignedUsers),
				'key' => $plugin->getApiKey(), //shared key between OJS and Editor software
			];

			$url = FidusWriterPluginHelper::getSubmissionSetting($submissionId, 'fidusUrl');
			$url .= '/api/ojs/create_copy/' . $fidusId . '/';
			FidusWriterPluginHelper::sendPostRequest($url, $dataArray);
		}

		return false;
	}
}

<?php

/**
* Copyright (c) 2015-2017 Afshin Sadehghi
* Copyright (c) 2017 Firas Kassawat
* Copyright (c) 2016-2018 Johannes Wilm
* Copyright (c) 2014-2017 Simon Fraser University
* Copyright (c) 2000-2017 John Willinsky
* License: GNU GPL v2. See LICENSE.md for details.
*
* Form for journal managers to modify FidusWriter plugin settings
*/


import('lib.pkp.classes.form.Form');

class FidusWriterCreateRevisionForm extends Form {
	/**
	* Constructor
	* @param $plugin object
	* @param $journalId int
	*/
	function __construct($template) {
		parent::__construct($template);
		$this->addCheck(new FormValidatorPost($this));
		$this->addCheck(new FormValidatorCSRF($this));
	}

	function readInputData() {
		$this->readUserVars(array('reviewRoundStatus', 'reviewRoundId', 'oldVersion', 'newVersion', 'apiKey'));
	}

	/**
	* Save settings.
	*/
	function execute(...$functionArgs) {
		import('lib.pkp.classes.submission.reviewRound.ReviewRound');
		$completedReviewRoundStatus = [
			REVIEW_ROUND_STATUS_REVIEWS_COMPLETED,
			REVIEW_ROUND_STATUS_REVISIONS_REQUESTED,
			REVIEW_ROUND_STATUS_ACCEPTED,
			REVIEW_ROUND_STATUS_DECLINED
		];

		$reviewRoundStatus = $this->getData('reviewRoundStatus');
		$reviewRoundId = $this->getData('reviewRoundId');
		$oldVersion = $this->getData('oldVersion');
		$newVersion = $this->getData('newVersion');
		$apiKey = $this->getData('apiKey');

		if (!in_array(intval($reviewRoundStatus), $completedReviewRoundStatus)) {
			// Review Round is not completed yet. Don't allow to create
			return;
		}

		/**
		 * @var ReviewRound $reviewRound
		 */
		$reviewRoundDao = DAORegistry::getDAO('ReviewRoundDAO');
		$reviewRound = $reviewRoundDao->getById($reviewRoundId);
		$submissionId = $reviewRound->getSubmissionId();
		$assignedUsers = FidusWriterPluginHelper::getAssignedUserIds($submissionId, $reviewRound->getStageId());

		// Create revision in FidusWriter
		$dataArray = [
			'old_version' => $oldVersion,
			'new_version' => $newVersion,
			'granted_users' => implode(',', $assignedUsers),
			'key' => $apiKey
		];
		$fidusId = FidusWriterPluginHelper::getSubmissionSetting($submissionId, 'fidusId');
		$fidusUrl = FidusWriterPluginHelper::getSubmissionSetting($submissionId, 'fidusUrl');
		$fidusUrl .= '/api/ojs/create_copy/' . $fidusId . '/';
		FidusWriterPluginHelper::sendPostRequest($fidusUrl, $dataArray);

		// Get URL to revision in FidusWriter
		$revisionUrl = FidusWriterPluginHelper::getGatewayPluginUrl() . '/documentReview?submissionId=' . $submissionId . '&stageId=' . $reviewRound->getStageId() . '&version=' . $newVersion;

		/**
		 * @var FidusWriterReviewRoundRevisionDAO $reviewRoundRevisionDao
		 * @var DataObject $reviewRoundRevision
		 */
		$reviewRoundRevisionDao = DAORegistry::getDAO('FidusWriterReviewRoundRevisionDAO');
		$reviewRoundRevision = $reviewRoundRevisionDao->getRoundRevision($reviewRoundId);

		if (empty($reviewRoundRevision)) {
			$reviewRoundRevision = new DataObject();
			$reviewRoundRevision->setData('review_round', $reviewRoundId);
		}

		$reviewRoundRevision->setData('revision_url', $revisionUrl);
		$reviewRoundRevisionDao->save($reviewRoundRevision);
	}
}

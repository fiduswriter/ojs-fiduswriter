<?php
FidusWriterPluginHelper::getFidusWriterPlugin()->import('gateways.FidusWriterRequestHandler');

class FidusWriterSubmissionHandler extends FidusWriterRequestHandler {
	private $inputDefault = ['options' => ['default' => '']];

	/**
	 * @return array
	 */
	public function post_test()
	{
		return [
			"message" => "GET response",
			"version" => $this->apiVersion
		];
	}

	/**
	 * @return array
	 */
	public function post_authorSubmit()
	{
		$submissionId = filter_input(INPUT_POST, 'submission_id', FILTER_VALIDATE_INT);
		return $submissionId ? $this->handle_submisson_update($submissionId) : $this->handle_new_submission();
	}

	/**
	 * @return array|false
	 */
	public function post_reviewerSubmit()
	{
		$submissionId = filter_input(INPUT_POST, "submission_id", FILTER_VALIDATE_INT);
		$versionString = filter_input(INPUT_POST, "version");
		$reviewerId = filter_input(INPUT_POST, "user_id", FILTER_VALIDATE_INT);

		// Validate req params
		if (!$submissionId || empty($versionString) || !$reviewerId) {
			return false;
		}

		/** @var SubmissionDAO $submissionDao */
		$submissionDao = DAORegistry::getDAO('SubmissionDAO');
		$submission = $submissionDao->getById($submissionId);

		if (!$submission) {
			return false;
		}

		$contextId = $submission->getData('contextId');
		$versionInfo = FidusWriterPluginHelper::versionToStage($versionString);
		$stageId = $versionInfo['stageId'];
		$round = $versionInfo['round'];

		/** @var ReviewRoundDAO $reviewRoundDao */
		$reviewRoundDao = DAORegistry::getDAO('ReviewRoundDAO');
		$reviewRound = $reviewRoundDao->getReviewRound($submissionId, $stageId, $round);

		/** @var ReviewAssignmentDAO $reviewAssignmentDao */
		$reviewAssignmentDao = DAORegistry::getDAO('ReviewAssignmentDAO');
		$reviewAssignment = $reviewAssignmentDao->getReviewAssignment(
			$reviewRound->getId(),
			$reviewerId
		);

		// save comment for editor
		$editorMessageCommentText = filter_input(INPUT_POST, "editor_message");
		$this->saveComment($editorMessageCommentText, true, $reviewAssignment);

		// save comment for editor and author
		$editorAndAuthorMessageCommentText = filter_input(INPUT_POST, "editor_author_message");
		$this->saveComment($editorAndAuthorMessageCommentText, false, $reviewAssignment);

		/** @var ReviewerSubmissionDAO $reviewerSubmissionDao */
		$reviewerSubmissionDao = DAORegistry::getDAO('ReviewerSubmissionDAO');
		$reviewerSubmission = $reviewerSubmissionDao->getReviewerSubmission($reviewAssignment->getId());

		// Set review step to last step
		$nextStep = 4;
		if ($reviewerSubmission->getStep() < $nextStep) {
			$reviewerSubmission->setStep($nextStep);
		}

		// Save the reviewer submission.
		$reviewerSubmissionDao->updateReviewerSubmission($reviewerSubmission);

		// Mark the review assignment as completed.
		$reviewAssignment->setDateCompleted(Core::getCurrentDate());
		$reviewAssignment->stampModified();

		// Set the recommendation
		$recommendation = filter_input(INPUT_POST, 'recommendation', FILTER_VALIDATE_INT);
		$reviewAssignment->setRecommendation($recommendation);
		$reviewAssignmentDao->updateObject($reviewAssignment);

		// Send notifications to everyone who should be informed.
		/** @var StageAssignmentDAO $stageAssignmentDao */
		$stageAssignmentDao = DAORegistry::getDAO('StageAssignmentDAO');
		$stageAssignments = $stageAssignmentDao->getBySubmissionAndStageId($submissionId, $stageId);
		/** @var UserGroupDAO $userGroupDao */
		$userGroupDao = DAORegistry::getDAO('UserGroupDAO');
		$receivedList = []; // Avoid sending twice to the same user.
		$notificationMgr = new NotificationManager();

		/** @var UserDAO $userDao */
		$userDao = DAORegistry::getDAO('UserDAO');
		$user = $userDao->getById($reviewerId);

		FidusWriterPluginHelper::getFidusWriterPlugin()->import('FidusWriterNotificationRequest');
		$mockRequest = FidusWriterNotificationRequest::create($this->request, $user);

		while ($stageAssignment = $stageAssignments->next()) {
			$userId = $stageAssignment->getUserId();
			$userGroup = $userGroupDao->getById(
				$stageAssignment->getUserGroupId(),
				$contextId
			);

			// Only send notifications about reviewer comment notification to managers and editors
			// and only send to users who have not received a notification already.
			if (
				!in_array($userGroup->getRoleId(), [ROLE_ID_MANAGER, ROLE_ID_SUB_EDITOR]) ||
				in_array($userId, $receivedList)
			) {
				continue;
			}

			$notificationMgr->createNotification(
				$mockRequest,
				$userId,
				NOTIFICATION_TYPE_REVIEWER_COMMENT,
				$contextId,
				ASSOC_TYPE_REVIEW_ASSIGNMENT,
				$reviewAssignment->getId()
			);

			$receivedList[] = $userId;
		}

		// Remove the review task
		/** @var NotificationDAO $notificationDao */
		$notificationDao = DAORegistry::getDAO('NotificationDAO');
		$notificationDao->deleteByAssoc(
			ASSOC_TYPE_REVIEW_ASSIGNMENT,
			$reviewAssignment->getId(),
			$reviewAssignment->getReviewerId(),
			NOTIFICATION_TYPE_REVIEW_ASSIGNMENT
		);

		return ["version" => $this->apiVersion];
	}

	/**
	 * @return array|false
	 */
	public function post_copyeditDraftSubmit()
	{
		$submissionId = filter_input(INPUT_POST, 'submission_id', FILTER_VALIDATE_INT);
		$userId = filter_input(INPUT_POST, 'ojs_uid', FILTER_VALIDATE_INT);

		if (!$submissionId || !$userId) {
			return false;
		}

		/** @var SubmissionDAO $submissionDao */
		$submissionDao = DAORegistry::getDAO('SubmissionDAO');
		/** @var Submission $submission */
		$submission = $submissionDao->getById($submissionId);
		/** @var UserDAO $userDao */
		$userDao = DAORegistry::getDAO('UserDAO');
		$user = $userDao->getById($userId);

		if (empty($submission) || empty($user)) {
			return false;
		}

		// notify about draft file update
		import('lib.pkp.classes.mail.SubmissionMailTemplate');
		import('lib.pkp.classes.log.SubmissionEmailLogEntry');
		$mail = new SubmissionMailTemplate($submission, 'FIDUSWRITER_COPYEDIT_AUTHOR_COMPLETE');
		$mail->setEventType(SUBMISSION_EMAIL_COPYEDIT_NOTIFY_AUTHOR_COMPLETE);

		// Get editors assigned to the submission, consider also the recommendOnly editors
		$userDao = DAORegistry::getDAO('UserDAO');
		$stageAssignmentDao = DAORegistry::getDAO('StageAssignmentDAO');
		$editorsStageAssignments = $stageAssignmentDao->getEditorsAssignedToStage($submission->getId(), $submission->getStageId());
		foreach ($editorsStageAssignments as $editorsStageAssignment) {
			$editorId = $editorsStageAssignment->getUserId();
			$editor = $userDao->getById($editorId);
			$mail->addRecipient($editor->getEmail(), $editor->getFullName());
		}

		// Assign author and submission data
		$submissionLocale = $submission->getData('locale');
		$pub = $submission->getCurrentPublication();
		$submissionTitle = $pub ? $pub->getLocalizedData('title', $submissionLocale) : '';
		$contextDao = Application::getContextDAO();
		$context = $contextDao->getById($submission->getJournalId());
		AppLocale::requireComponents(LOCALE_COMPONENT_PKP_USER);
		$mail->assignParams(array(
			'editorialContactName' => PKPLocale::translate('user.role.editors'),
			'submissionTitle' => $submissionTitle,
			'contextName' => $context->getName($submissionLocale),
			'authorName' => $user->getFullName()
		));

		$mail->send();

		return ["version" => $this->apiVersion];
	}

	/**
	 * @return array|false
	 */
	protected function handle_new_submission()
	{
		$email = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
		$firstName = filter_input(INPUT_POST, 'first_name');
		$lastName = filter_input(INPUT_POST, 'last_name', FILTER_DEFAULT, $this->inputDefault);
		$title = filter_input(INPUT_POST, 'title', FILTER_DEFAULT, $this->inputDefault);
		$abstract = filter_input(INPUT_POST, 'abstract', FILTER_DEFAULT, $this->inputDefault);
		$journalId = filter_input(INPUT_POST, 'journal_id', FILTER_VALIDATE_INT);
		$fidusId = filter_input(INPUT_POST, 'fidus_id', FILTER_VALIDATE_INT);
		$fidusUrl = filter_input(INPUT_POST, 'fidus_url', FILTER_VALIDATE_URL);
		$affiliation = filter_input(INPUT_POST, 'affiliation', FILTER_DEFAULT, $this->inputDefault);
		$country = filter_input(INPUT_POST, 'country', FILTER_DEFAULT, $this->inputDefault);
		$authorUrl = filter_input(INPUT_POST, 'author_url', FILTER_DEFAULT, $this->inputDefault);
		$biography = filter_input(INPUT_POST, 'biography', FILTER_DEFAULT, $this->inputDefault);

		// validate required fields
		if (
			!$email ||
			empty($firstName) ||
			empty($title) ||
			!$journalId ||
			!$fidusId ||
			!$fidusUrl
		) {
			return false;
		}

		/**
		 * Create a user for the author
		 */
		$user = $this->getOrCreateUser($email, $firstName, $lastName, $journalId);

		/**
		 * Create a submission
		 * @var Submission $submission
		 */
		// Submit to the default section ('Articles' or whatever section found at first).
		// TODO: Extend the api to select which section to submit to.
		// https://pkp.sfu.ca/ojs/docs/userguide/2.3.3/journalManagementJournalSections.html
		$sectionDao = Application::getSectionDAO();
		$sections = $sectionDao->getByJournalId($journalId);
		$sectionId = $sections->next()->getId();
		$submissionDao = DAORegistry::getDAO('SubmissionDAO');
		$submission = $submissionDao->newDataObject();
		$submission->setData('contextId', $journalId);
		$submission->setData('locale', $this->locale);
		$submission->setData('title', $title, $this->locale);
		// Set fidus writer related fields.
		$submission->setData("fidusUrl", $fidusUrl);
		$submission->setData("fidusId", $fidusId);
		$submission->stampLastActivity();
		$submission->stampModified();
		$submission->setData('dateSubmitted', Core::getCurrentDate());
		// Flag for fully submitted submission
		$submission->setData('submissionProgress', 0);
		$submission->setStageId(WORKFLOW_STAGE_ID_SUBMISSION);
		$submission->setData('status', STATUS_QUEUED);
		$submission->setData("sectionId", $sectionId);
		// Insert the submission
		$submissionDao->insertObject($submission);

		/**
		 * Create a publication
		 * @var PublicationDAO $publicationDao
		 * @var Publication $publication
		 */
		$publicationDao = DAORegistry::getDAO('PublicationDAO');
		$publication = $publicationDao->newDataObject();
		$publication->setData('submissionId', $submission->getId());
		$publication->setData('title', $title, $this->locale);
		$publication->setData('status', STATUS_QUEUED);
		$publication->setData('version', 1);
		$publication->setData('abstract', $abstract, $this->locale);
		// Insert the publication
		$publicationId = $publicationDao->insertObject($publication);

		// set publication of the submission
		$submission = Services::get('submission')->edit($submission, ['currentPublicationId' => $publicationId], $this->request);

		/**
		 * Set user to initial author
		 * @var AuthorDAO $authorDao
		 */
		$authorDao = DAORegistry::getDAO('AuthorDAO');
		$author = $authorDao->newDataObject();
		$firstName = $user->getGivenName($this->locale);
		$lastName = $user->getFamilyName($this->locale);
		$email = $user->getEmail();
		$author->setGivenName($firstName, $this->locale);
		$author->setFamilyName($lastName, $this->locale);
		$author->setAffiliation($affiliation, $this->locale);
		$author->setCountry($country);
		$author->setEmail($email);
		$author->setUrl($authorUrl);
		$author->setBiography($biography, $this->locale);
		$author->setPrimaryContact(true);
		$author->setIncludeInBrowse(true);
		$author->setData('publicationId', $publication->getId());
		$author->setSubmissionId($submission->getId());
		// Get the user group to display the submitter as
		$authorUserGroupId = $this->get_author_group_id($journalId);
		$author->setUserGroupId($authorUserGroupId);
		// Insert the author
		$authorDao->insertObject($author);

		// Set primary contact of the publication
		$publication = Services::get('publication')->edit($publication, ['primaryContactId' => $author->getId()], $this->request);

		// Assign the user author to the stage
		/* @var $stageAssignmentDao StageAssignmentDAO */
		$stageAssignmentDao = DAORegistry::getDAO('StageAssignmentDAO');
		$stageAssignmentDao->build($submission->getId(), $authorUserGroupId, $user->getId());

		/**
		 * Set journal managers as the editor of the submission in FidusWriter
		 */
		$userGroupDao = DAORegistry::getDAO('UserGroupDAO');
		// Get managers
		$journalManagers = FidusWriterPluginHelper::getJournalManagers($journalId);
		if (!empty($journalManagers)) {
			$addEditorUrl =  $fidusUrl . "/api/ojs/add_editor/{$fidusId}/";
			$managerData = [
				'key' => FidusWriterPluginHelper::getFidusWriterPlugin()->getApiKey(),
				'role' => ROLE_ID_MANAGER,
				'stage_ids' => '1,3,4,5'
			];

			foreach ($journalManagers as $journalManager) {
				$managerData['user_id'] = $journalManager->getId();
				$managerData['email'] = $journalManager->getEmail();
				$managerData['username'] = $journalManager->getUserName();
				FidusWriterPluginHelper::sendPostRequest($addEditorUrl, $managerData);
			}
		}

		/**
		 * Send notifications
		 */
		// Create a fake request object as the real request does not contain the required data.
		// $request is required in the following code which comes from different parts of OJS.
		$application = PKPApplication::get();
		FidusWriterPluginHelper::getFidusWriterPlugin()->import('FidusWriterNotificationRequest');
		$request = FidusWriterNotificationRequest::create($this->request, $user, $journalId);

		// The following has been adapted from PKPSubmissionSubmitStep4Form
		// Assign the default stage participants.
		//$userGroupDao = DAORegistry::getDAO('UserGroupDAO');
		$notifyUsers = array();
		// Manager and assistant roles -- for each assigned to this stage in setup.
		// If there is only one user for the group, automatically assign the user to the stage.
		$stageAssignmentDao = DAORegistry::getDAO('StageAssignmentDAO'); /* @var $stageAssignmentDao StageAssignmentDAO */
		$submissionStageGroups = $userGroupDao->getUserGroupsByStage($submission->getContextId(), WORKFLOW_STAGE_ID_SUBMISSION);
		while ($userGroup = $submissionStageGroups->next()) {
			// Only handle manager and assistant roles
			if (!in_array($userGroup->getRoleId(), array(ROLE_ID_MANAGER, ROLE_ID_ASSISTANT))) continue;

			$managers = $userGroupDao->getUsersById($userGroup->getId(), $submission->getContextId());
			if($managers->getCount() == 1) {
				$manager = $managers->next();
				$stageAssignmentDao->build($submission->getId(), $userGroup->getId(), $manager->getId(), $userGroup->getRecommendOnly());
				$notifyUsers[] = $manager->getId();
			}
		}

		// Author roles
		// Assign only the submitter in whatever ROLE_ID_AUTHOR capacity they were assigned previously
		$submitterAssignments = $stageAssignmentDao->getBySubmissionAndStageId(
			$submission->getId(),
			null,
			null,
			$user->getId()
		);
		while ($assignment = $submitterAssignments->next()) {
			$userGroup = $userGroupDao->getById($assignment->getUserGroupId());
			if ($userGroup->getRoleId() == ROLE_ID_AUTHOR) {
				$stageAssignmentDao->build($submission->getId(), $userGroup->getId(), $assignment->getUserId());
				// Only assign them once, since otherwise we'll one assignment for each previous stage.
				// And as long as they are assigned once, they will get access to their submission.
				break;
			}
		}

		$notificationManager = new NotificationManager();

		// Assign sub editors for sections
		$subEditorsDao = DAORegistry::getDAO('SubEditorsDAO'); /* @var $subEditorsDao SubEditorsDAO */
		$subEditors = $subEditorsDao->getBySubmissionGroupId(
			$submission->getSectionId(),
			ASSOC_TYPE_SECTION,
			$submission->getData('contextId')
		);
		foreach ($subEditors as $subEditor) {
			$userGroups = $userGroupDao->getByUserId($subEditor->getId(), $submission->getContextId());
			while ($userGroup = $userGroups->next()) {
				if ($userGroup->getRoleId() != ROLE_ID_SUB_EDITOR) continue;
				$stageAssignmentDao->build($submission->getId(), $userGroup->getId(), $subEditor->getId(), $userGroup->getRecommendOnly());
				// If we assign a stage assignment in the Submission stage to a sub editor, make note.
				if ($userGroupDao->userGroupAssignedToStage($userGroup->getId(), WORKFLOW_STAGE_ID_SUBMISSION)) {
					$notifyUsers[] = $subEditor->getId();
				}
			}
		}

		// Assign sub editors for categories
		$categoryDao = DAORegistry::getDAO('CategoryDAO'); /* @var $categoryDao CategoryDAO */
		$subEditorsDao = DAORegistry::getDAO('SubEditorsDAO'); /* @var $subEditorsDao SubEditorsDAO */
		$categories = $categoryDao->getByPublicationId($publication->getId());
		while ($category = $categories->next()) {
			$subEditors = $subEditorsDao->getBySubmissionGroupId($category->getId(), ASSOC_TYPE_CATEGORY, $submission->getContextId());
			foreach ($subEditors as $subEditor) {
				$userGroups = $userGroupDao->getByUserId($subEditor->getId(), $submission->getContextId());
				while ($userGroup = $userGroups->next()) {
					if ($userGroup->getRoleId() != ROLE_ID_SUB_EDITOR) continue;
					$stageAssignmentDao->build($submission->getId(), $userGroup->getId(), $subEditor->getId(), $userGroup->getRecommendOnly());
					// If we assign a stage assignment in the Submission stage to a sub editor, make note.
					if ($userGroupDao->userGroupAssignedToStage($userGroup->getId(), WORKFLOW_STAGE_ID_SUBMISSION)) {
						$notifyUsers[] = $subEditor->getId();
					}
				}
			}
		}

		// Update assignment notifications
		import('classes.workflow.EditorDecisionActionsManager');
		$notificationManager->updateNotification(
			$request,
			(new EditorDecisionActionsManager())->getStageNotifications(),
			null,
			ASSOC_TYPE_SUBMISSION,
			$submission->getId()
		);

		// Send a notification to associated users if an editor needs assigning
		if (empty($notifyUsers)) {
			/* @var $roleDao RoleDAO */
			$roleDao = DAORegistry::getDAO('RoleDAO');

			// Get the managers.
			$managers = $roleDao->getUsersByRoleId(ROLE_ID_MANAGER, $submission->getContextId());
			$managersArray = $managers->toAssociativeArray();

			$allUserIds = array_keys($managersArray);
			foreach ($allUserIds as $userId) {
				// Add TASK notification indicating that a submission is unassigned
				$notificationManager->createNotification(
					$request,
					$userId,
					NOTIFICATION_TYPE_EDITOR_ASSIGNMENT_REQUIRED,
					$submission->getData('contextId'),
					ASSOC_TYPE_SUBMISSION,
					$submission->getId(),
					NOTIFICATION_LEVEL_TASK
				);
			}
		} else foreach ($notifyUsers as $userId) {
			$notificationManager->createNotification(
				$request, $userId, NOTIFICATION_TYPE_SUBMISSION_SUBMITTED,
				$submission->getData('contextId'), ASSOC_TYPE_SUBMISSION, $submission->getId()
			);
		}

		$notificationManager->updateNotification(
			$request,
			array(NOTIFICATION_TYPE_APPROVE_SUBMISSION),
			null,
			ASSOC_TYPE_SUBMISSION,
			$submission->getId()
		);
		// End adaption from PKPSubmissionSubmitStep4Form

		/**
		 * Send author notification email
		 */
		$context = $request->getContext();
		$router = $request->getRouter();
		import('classes.mail.ArticleMailTemplate');
		$mail = new ArticleMailTemplate($submission, 'SUBMISSION_ACK', $this->locale, $context, false);

		if ($mail->isEnabled()) {
			$site = $this->request->getSite();
			$contactEmail = $site->getLocalizedContactEmail();
			$contactName = $site->getLocalizedContactName();
			// submission ack emails should be from the contact.
			$mail->setFrom($contactEmail, $contactName);
			$mail->addRecipient($user->getEmail(), $user->getFullName());
		}

		$mail->bccAssignedSubEditors($submission->getId(), WORKFLOW_STAGE_ID_SUBMISSION);
		$mail->assignParams(array(
			'authorName' => $user->getFullName(),
			'authorUsername' => $user->getUsername(),
			'editorialContactSignature' => $context->getData('contactName'),
			'submissionUrl' => $router->url($request, null, 'authorDashboard', 'submission', $submission->getId()),
		));

		if (!$mail->send($request)) {
			import('classes.notification.NotificationManager');
			$notificationMgr = new NotificationManager();
			$notificationMgr->createTrivialNotification($request->getUser()->getId(), NOTIFICATION_TYPE_ERROR, array('contents' => __('email.compose.error')));
		}

		return [
			"submission_id" => $submission->getId(),
			"user_id" => $user->getId(),
			"version" => $this->apiVersion,
		];
	}

	/**
	 * @param $submissionId
	 * @return array|false
	 */
	protected function handle_submisson_update($submissionId)
	{
		/**
		 * @var SubmissionDAO $submissionDao
		 * @var Submission $submission
		 */
		$submissionDao = DAORegistry::getDAO('SubmissionDAO');
		$submission = $submissionDao->getById($submissionId);

		if (!$submission) {
			return false;
		}

		$versionString = filter_input(INPUT_POST, "version");
		$versionInfo = FidusWriterPluginHelper::versionToStage($versionString);

		// Given that this is a resubmission, we need to set the status of
		// the stage to REVIEW_ROUND_STATUS_RESUBMIT_FOR_REVIEW_SUBMITTED.
		$stageId = $versionInfo['stageId'];
		$round = $versionInfo['round'];
		/** @var ReviewRoundDAO $reviewRoundDao */
		$reviewRoundDao = DAORegistry::getDAO('ReviewRoundDAO');
		$reviewRound = $reviewRoundDao->getReviewRound($submissionId, $stageId, $round);
		$reviewRound->setStatus(REVIEW_ROUND_STATUS_RESUBMIT_FOR_REVIEW_SUBMITTED);
		$reviewRoundDao->updateObject($reviewRound);

		// Send notification
		import('lib.pkp.classes.mail.SubmissionMailTemplate');
		import('lib.pkp.classes.log.SubmissionEmailLogEntry');
		$mail = new SubmissionMailTemplate($submission, 'REVISED_VERSION_NOTIFY');
		$mail->setEventType(SUBMISSION_EMAIL_AUTHOR_NOTIFY_REVISED_VERSION);

		// Get editors assigned to the submission, consider also the recommendOnly editors
		/** @var UserDAO $userDao */
		$userDao = DAORegistry::getDAO('UserDAO');
		/** @var StageAssignmentDAO $stageAssignmentDao */
		$stageAssignmentDao = DAORegistry::getDAO('StageAssignmentDAO');
		$editorsStageAssignments = $stageAssignmentDao->getEditorsAssignedToStage($submission->getId(), $submission->getStageId());

		foreach ($editorsStageAssignments as $editorsStageAssignment) {
			$editorId = $editorsStageAssignment->getUserId();
			$editor = $userDao->getById($editorId);
			$mail->addRecipient($editor->getEmail(), $editor->getFullName());
		}

		// Assign author and submission data
		$primaryAuthor = $submission->getPrimaryAuthor();
		$router = $this->request->getRouter();
		$dispatcher = $router->getDispatcher();

		/** @var JournalDAO $contextDao */
		$contextDao = DAORegistry::getDAO('JournalDAO');
		$context = $contextDao->getById($submission->getData('contextId'));
		$submissionUrl = $dispatcher->url(
			$this->request,
			ROUTE_PAGE,
			$context->getPath(),
			'workflow',
			'index',
			[$submission->getId(), $submission->getStageId()]
		);
		$authorFullName = $primaryAuthor->getFullName();
		$mail->assignParams(array(
			'authorName' => $authorFullName,
			'editorialContactSignature' => '',
			'submissionUrl' => $submissionUrl,
		));

		$mail->send();

		return [
			"version" => $this->apiVersion,
		];
	}

	/**
	 * @param $journalId
	 * @return false|int
	 */
	protected function get_author_group_id($journalId)
	{
		$userGroupDao = DAORegistry::getDAO('UserGroupDAO');
		$authorUserGroup = $userGroupDao->getDefaultByRoleId($journalId, ROLE_ID_AUTHOR);
		if ($authorUserGroup) {
			return $authorUserGroup->getId();
		}

		return null;
	}

	/**
	 * Returns a user with the given $emailAddress or creates and returns a new
	 * user if this is not the case.
	 *
	 * @param $emailAddress
	 * @param $firstName
	 * @param $lastName
	 * @return User
	 */
	protected function getOrCreateUser($emailAddress, $firstName, $lastName, $journalId)
	{
		/** @var UserDAO $userDao */
		$userDao = DAORegistry::getDAO('UserDAO');
		if ($userDao->userExistsByEmail($emailAddress)) {
			// User already has account.
			// TODO: check if enrolled as author in journal
			$user = $userDao->getUserByEmail($emailAddress);
		} else {
			// User does not have an account. Create one and enroll as author.
			$username = Validation::suggestUsername($firstName, $lastName);
			$password = Validation::generatePassword();

			$user = $userDao->newDataObject();
			$user->setUsername($username);
			$user->setPassword(Validation::encryptCredentials($username, $password));
			$user->setGivenName($firstName, $this->locale);
			$user->setFamilyName($lastName, $this->locale);
			$user->setEmail($emailAddress);
			$user->setDateRegistered(Core::getCurrentDate());

			// this is to be added for authentication plugin in future, so that we will list it in auth_source table
			$authDao = DAORegistry::getDAO('AuthSourceDAO');
			$defaultAuth = $authDao->getDefaultPlugin();
			$user->setAuthId($defaultAuth->authId);
			$userDao->insertObject($user);

			// Send notification to the Fiduswriter user about the new user account and reset password
			$hash = Validation::generatePasswordResetHash($user->getId());
			import('lib.pkp.classes.mail.MailTemplate');
			$mail = new MailTemplate('PASSWORD_RESET_CONFIRM');
			$site = $this->request->getSite();
			$mail->setFrom($site->getLocalizedContactEmail(), $site->getLocalizedContactName());
			$mail->assignParams(array(
				'url' => $this->request->url(null, 'login', 'resetPassword', $user->getUsername(), array('confirm' => $hash)),
				'siteTitle' => $site->getLocalizedTitle()
			));
			$mail->addRecipient($user->getEmail(), $user->getFullName());
			$mail->send();
		}

		// Enroll user if needed
		$authorUserGroupId = $this->get_author_group_id($journalId);
		if ($authorUserGroupId) {
			/* @var $userGroupDao UserGroupDAO */
			$userGroupDao = DAORegistry::getDAO('UserGroupDAO');
			if (!$userGroupDao->userInGroup($user->getId(), $authorUserGroupId)) {
				$userGroupDao->assignUserToGroup($user->getId(), $authorUserGroupId);
			}
		}

		return $user;
	}

	/**
	 * @param $commentText
	 * @param $hidden
	 * @param $reviewAssignment
	 * @return bool
	 */
	protected function saveComment($commentText, $hidden, $reviewAssignment)
	{
		if (strlen($commentText) === 0) {
			return false;
		}
		// Create a comment with the review.
		$submissionCommentDao = DAORegistry::getDAO('SubmissionCommentDAO');
		$comment = $submissionCommentDao->newDataObject();
		$comment->setCommentType(COMMENT_TYPE_PEER_REVIEW);
		$comment->setRoleId(ROLE_ID_REVIEWER);
		$comment->setAssocId($reviewAssignment->getId());
		$comment->setSubmissionId($reviewAssignment->getSubmissionId());
		$comment->setAuthorId($reviewAssignment->getReviewerId());
		$comment->setComments($commentText);
		$comment->setCommentTitle('');
		$viewable = true;
		if ($hidden === true) {
			$viewable = false;
		}
		$comment->setViewable($viewable);
		$comment->setDatePosted(Core::getCurrentDate());
		// Persist.
		$submissionCommentDao->insertObject($comment);
		return true;
	}
}

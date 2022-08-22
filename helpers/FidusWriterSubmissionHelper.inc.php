<?php
class FidusWriterSubmissionHelper {
	/**
	 * @param $user User
	 * @param $title
	 * @param $abstract
	 * @param $journalId
	 * @param $fidusUrl
	 * @param $fidusId
	 * @return Submission
	 */
	public function submitSubmission($user, $affiliation, $country, $authorUrl, $biography, $title, $abstract, $journalId, $fidusUrl, $fidusId)
	{
		$request = Application::get()->getRequest();
		$locale = AppLocale::getLocale();

		// Sections are different parts of a journal, we only allow submission
		// to the default section ('Articles').
		// TODO: Extend the api to select which section to submit to.
		// https://pkp.sfu.ca/ojs/docs/userguide/2.3.3/journalManagementJournalSections.html
		$sectionDao = Application::getSectionDAO();
		$section = $sectionDao->getByTitle("Articles", $journalId, $locale);
		if ($section !== NULL) {
			$sectionId = $section->getId();
		} else {
			$sectionId = 1;
		}

		// Create a submission
		$submissionDao = DAORegistry::getDAO('SubmissionDAO');
		$submission = $submissionDao->newDataObject();
		$submission->setData('contextId', $journalId);
		$submission->setData('locale', $locale);
		$submission->setData('title', $title, $locale);
		// Set fidus writer related fields.
		$submission->setData("fidusUrl", $fidusUrl);
		$submission->setData("fidusId", $fidusId);

		$submission->stampLastActivity();
		$submission->stampModified();
		$submission->setData('dateSubmitted', Core::getCurrentDate());
		$submission->setData('submissionProgress', 1);
		$submission->setStageId(WORKFLOW_STAGE_ID_SUBMISSION);
		$submission->setData('status', STATUS_QUEUED);
		$submission->setData("sectionId", $sectionId);
		// Insert the submission
		$submissionDao->insertObject($submission);

		// Create a publication
		$publicationDao = DAORegistry::getDAO('PublicationDAO');
		$publication = $publicationDao->newDataObject();
		$publication->setData('submissionId', $submission->getId());
		$publication->setData('status', STATUS_QUEUED);
		$publication->setData('version', 1);
		$publication->setData('abstract', $abstract, $locale);
		// Insert the publication
		$publicationDao->insertObject($publication);

		// set publication
		$submission = Services::get('submission')->edit($submission, ['currentPublicationId' => $publication->getId()], $request);

		// Set user to initial author
		/**
		 * @var $authorDao AuthorDAO
		 */
		$authorDao = DAORegistry::getDAO('AuthorDAO');
		$author = $authorDao->newDataObject();
		$firstName = $user->getGivenName($locale);
		$lastName = $user->getFamilyName($locale);
		$emailAddress = $user->getEmail();
		$author->setGivenName($firstName, $locale);
		$author->setFamilyName($lastName, $locale);
		$author->setAffiliation($affiliation, $locale);
		$author->setCountry($country);
		$author->setEmail($emailAddress);
		$author->setUrl($authorUrl);
		$author->setBiography($biography, $locale);
		$author->setPrimaryContact(true);
		$author->setIncludeInBrowse(true);
		$author->setData('publicationId', $publication->getId());
		$author->setSubmissionId($submission->getId());
		// Get the user group to display the submitter as
		$authorUserGroup = $this->getAuthorUserGroupId($journalId);
		if ($authorUserGroup) {
			$author->setUserGroupId($authorUserGroup);
		}
		// Insert the author
		$authorDao->insertObject($author);

		// Set primary contact
		$publication = Services::get('publication')->edit($publication, ['primaryContactId' => $author->getId()], $request);

		$this->notifyAboutNewSubmission($journalId, $submission, $user, $author, $publication);

		return $submission;
	}

	public function updateSubmission()
	{

	}

	/**
	 * @param $journalId
	 * @return mixed
	 */
	protected function getAuthorUserGroupId($journalId)
	{
		$userGroupDao = DAORegistry::getDAO('UserGroupDAO');
		/** /classes/security/UserGroup  */
		$authorUserGroup = $userGroupDao->getDefaultByRoleId($journalId, ROLE_ID_AUTHOR);
		if ($authorUserGroup === false) {
			return false;
		}
		return $authorUserGroup->getId();
	}

	/**
	 * @param $journalId
	 * @param $submission Submission
	 * @param $user
	 * @param $emailAddress
	 * @param $firstName
	 * @param $lastName
	 * @return void
	 */
	protected function notifyAboutNewSubmission($journalId, $submission, $user, $primaryAuthor, $publication)
	{
		// Create a fake request object as the real request does not contain the required data.
		// $request is required in the following code which comes from different parts of OJS.
		$application = PKPApplication::get();
		FidusWriterPluginHelper::getFidusWriterPlugin()->import('NotificationRequest');
		$request = NotificationRequest::create($application->getRequest(), $user, $journalId);

		// The following has been adapted from PKPSubmissionSubmitStep4Form
		// Assign the default stage participants.
		$userGroupDao = DAORegistry::getDAO('UserGroupDAO');
		$notifyUsers = array();
		// Manager and assistant roles -- for each assigned to this
		//  stage in setup, iff there is only one user for the group,
		//  automatically assign the user to the stage.
		$stageAssignmentDao = DAORegistry::getDAO('StageAssignmentDAO'); /* @var $stageAssignmentDao StageAssignmentDAO */
		$submissionStageGroups = $userGroupDao->getUserGroupsByStage($submission->getContextId(), WORKFLOW_STAGE_ID_SUBMISSION);
		while ($userGroup = $submissionStageGroups->next()) {
			// Only handle manager and assistant roles
			if (!in_array($userGroup->getRoleId(), array(ROLE_ID_MANAGER, ROLE_ID_ASSISTANT))) continue;

			$users = $userGroupDao->getUsersById($userGroup->getId(), $submission->getContextId());
			if($users->getCount() == 1) {
				$user = $users->next();
				$stageAssignmentDao->build($submission->getId(), $userGroup->getId(), $user->getId(), $userGroup->getRecommendOnly());
				$notifyUsers[] = $user->getId();
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
			$roleDao = DAORegistry::getDAO('RoleDAO'); /* @var $roleDao RoleDAO */

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
		// End adaption from SubmissionSubmitStep4Form

		// Send author notification email
		import('classes.mail.ArticleMailTemplate');
		$context = $request->getContext();
		$router = $request->getRouter();
		$mail = new ArticleMailTemplate($submission, 'SUBMISSION_ACK', null, null, false);
		$mail->setContext($context);
		$authorMail = new ArticleMailTemplate($submission, 'SUBMISSION_ACK_NOT_USER', null, null, false);
		$authorMail->setContext($context);

		if ($mail->isEnabled()) {
			// submission ack emails should be from the contact.
			$mail->setFrom($context->getSetting('contactEmail'), $context->getSetting('contactName'));
			$authorMail->setFrom($context->getSetting('contactEmail'), $context->getSetting('contactName'));
			{
				$mail->addRecipient($user->getEmail(), $user->getFullName());
			}
			// Add primary contact and e-mail address as specified in the journal submission settings
			if ($context->getSetting('copySubmissionAckPrimaryContact')) {
				$mail->addBcc(
					$context->getSetting('contactEmail'),
					$context->getSetting('contactName')
				);
			}
			if ($copyAddress = $context->getSetting('copySubmissionAckAddress')) {
				$mail->addBcc($copyAddress);
			}

			if ($user->getEmail() != $primaryAuthor->getEmail()) {
				$authorMail->addRecipient($primaryAuthor->getEmail(), $primaryAuthor->getFullName());
			}

			$assignedAuthors = $submission->getAuthors();

			foreach ($assignedAuthors as $author) {
				$authorEmail = $author->getEmail();
				// only add the author email if they have not already been added as the primary author
				// or user creating the submission.
				if ($authorEmail != $primaryAuthor->getEmail() && $authorEmail != $user->getEmail()) {
					$authorMail->addRecipient($author->getEmail(), $author->getFullName());
				}
			}
			$mail->bccAssignedSubEditors($submission->getId(), WORKFLOW_STAGE_ID_SUBMISSION);

			$mail->assignParams(array(
				'authorName' => $user->getFullName(),
				'authorUsername' => $user->getUsername(),
				'editorialContactSignature' => $context->getSetting('contactName'),
				'submissionUrl' => $router->url($request, null, 'authorDashboard', 'submission', $submission->getId()),
			));

			$authorMail->assignParams(array(
				'submitterName' => $user->getFullName(),
				'editorialContactSignature' => $context->getSetting('contactName'),
			));

			$mail->send($request);

			$recipients = $authorMail->getRecipients();
			if (!empty($recipients)) {
				$authorMail->send($request);
			}
		}

		// Log submission.
		import('classes.log.SubmissionEventLogEntry'); // Constants
		import('lib.pkp.classes.log.SubmissionLog');
		SubmissionLog::logEvent($request, $submission, SUBMISSION_LOG_SUBMISSION_SUBMIT, 'submission.event.submissionSubmitted');
	}
}

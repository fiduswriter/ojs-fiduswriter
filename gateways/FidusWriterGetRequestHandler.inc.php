<?php
FidusWriterPluginHelper::getFidusWriterPlugin()->import('gateways.FidusWriterRequestHandler');

class FidusWriterGetRequestHandler extends FidusWriterRequestHandler
{
	public function get_test()
	{
		return [
			"message" => "GET response",
			"version" => $this->apiVersion
		];
	}

	public function get_journals()
	{
		// Get all journals setup on this server.
		if (!isset($_GET['key']) || $this->apiKey !== $_GET['key']) {
			// Not correct api key.
			return false;
		}

		/* @var $journalDao JournalDAO */
		$journalDao = DAORegistry::getDAO('JournalDAO');
		$journalsObject = $journalDao->getAll();
		$journals = $journalsObject->toAssociativeArray();
		$journalArray = [];

		foreach ($journals as $journal) {
			$journalArray[] = [
				'id' => $journal->getId(),
				'name' => $journal->getLocalizedName(),
				'contact_email' => $journal->getSetting('contactEmail'),
				'contact_name' => $journal->getSetting('contactName'),
				'url_relative_path' => $journal->getPath(),
				'description' => $journal->getLocalizedDescription(),
			];
		}

		if (empty($journalArray)) {
			// No journal is available
			return false;
		}

		return [
			"journals" => $journalArray,
			"version" => $this->apiVersion
		];
	}

	public function get_documentReview()
	{
		$submissionId = filter_input(INPUT_GET, 'submissionId', FILTER_VALIDATE_INT);
		$versionString = filter_input(INPUT_GET, 'version');

		if (!$submissionId || empty($versionString)) {
			return false;
		}

		$this->loginFidusWriter($submissionId, $versionString);
	}

	/**
	 * Forwards user to Fidus Writer after checking access rights.
	 * @param $fidusUrl
	 * @param $fidusId
	 * @param $submissionId
	 * @param $version
	 * @return string
	 */
	protected function loginFidusWriter($submissionId, $versionString)
	{
		$fidusId = FidusWriterPluginHelper::getSubmissionSetting($submissionId, 'fidusId');
		$fidusUrl = FidusWriterPluginHelper::getSubmissionSetting($submissionId, 'fidusUrl');
		$sessionManager = SessionManager::getManager();
		$userSession = $sessionManager->getUserSession();
		$user = $userSession->getUser();
		$submissionDao = DAORegistry::getDAO('SubmissionDAO');
		$submission = $submissionDao->getById($submissionId);
		$journalId = $submission->getContextId();
		// Editor users will fallback to being logged in as the editor user on the backend,
		// if they are not registered as either reviewers or authors of the revision they are trying to look at.
		$isEditor = FidusWriterPluginHelper::isEditor($user->getId(), $journalId);
		$userId = $user->getId();
		$loginToken = FidusWriterPluginHelper::getLoginToken($fidusUrl, $fidusId, $versionString, $userId, $isEditor, $this->apiKey);

		if (!$loginToken) {
			return false;
		}

		echo '<html>
				<body onload="document.frm1.submit()">
				<form method="post" action="' . $fidusUrl . '/api/ojs/revision/' . $fidusId . '/' . $versionString . '/" name = "frm1" class="inline">
				<input type="hidden" name="token" value="' . $loginToken . '">
				<button type="submit" name="submit_param" style="display=none;" value="submit_value" class="link-button"></button>
				</form>
				</body >
				</html >';

		exit;
	}
}

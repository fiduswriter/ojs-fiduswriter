<?php

class FidusWriterPluginHelper
{
	public static function getFidusWriterPlugin()
	{
		return PluginRegistry::getPlugin('generic', 'fiduswriterplugin');
	}

	public static function getSubmissionSetting($submissionId, $settingName)
	{
		$submissionDao = DAORegistry::getDAO('SubmissionDAO');
		$submission = $submissionDao->getById($submissionId);
		return $submission->getData($settingName);
	}

	public static function getGatewayPluginUrl()
	{
		$request =& Registry::get('request');
		return $request->getBaseUrl() . '/index.php/index/gateway/plugin/FidusWriterGatewayPlugin';
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
	 * @return string
	 */
	public static function stageToVersion($stageId, $round = 0, $revisionType = 'Reviewer')
	{
		switch ($stageId) {
			case 0:
			case 1:
				// submission
				return '1.0.0';

			case 2:
				// internal review
				// TODO: does this also operate with review rounds? Couldn't
				// find it how to do internal reviews.
				if ($revisionType == 'Reviewer') {
					return '2.' . $round . '.0';
				} else {
					return '2.' . $round . '.5';
				}

			case 3:
				if ($revisionType == 'Reviewer') {
					return '3.' . $round . '.0';
				} else {
					return '3.' . $round . '.5';
				}

			case 4:
				return '4.0.0';

			case 5:
				return '5.0.0';
		}

		return null;
	}

	/**
	 * Takes a FW versionString and returns a stageId and round number.
	 * Does the opposite of stageToVersion(...) in the parent plugin.
	 * @param $versionString
	 * @return array
	 */
	public static function versionToStage($versionString)
	{
		$parts = explode('.', $versionString);
		$stageId = intval($parts[0]);
		$round = intval($parts[1]);
		if ($parts[2] == '5') {
			$revisionType = 'Author';
		} else {
			$revisionType = 'Reviewer';
		}

		$returnArray = array();

		$returnArray['stageId'] = $stageId;
		$returnArray['round'] = $round;
		$returnArray['revisionType'] = $revisionType;

		return $returnArray;
	}

	public static function getUser($userId)
	{
		$userDao = DAORegistry::getDAO('UserDAO');
		return $userDao->getById($userId);
	}

	/**
	 * @param $requestType
	 * @param $url
	 * @param $dataArray
	 * @return string
	 */
	public static function sendRequest($requestType, $url, $dataArray)
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
	public static function sendPostRequest($url, $dataArray)
	{
		return self::sendRequest('POST', $url, $dataArray);
	}

	/**
	 * @param $submissionId
	 * @param $stageId
	 * @return int[]
	 */
	public static function getAssignedUserIds($submissionId, $stageId)
	{
		/**
		 * @var StageAssignmentDAO $stageAssignmentDao
		 */
		$stageAssignmentDao = DAORegistry::getDAO('StageAssignmentDAO');
		$stageAssignments = $stageAssignmentDao->getBySubmissionAndStageId($submissionId, $stageId);

		$userIds = [];

		while ($assignment = $stageAssignments->next()) {
			$userIds[] = intval($assignment->getUserId());
		}

		return $userIds;
	}

	/**
	 * @param $submissonId
	 * @param $userId
	 * @return boolean
	 */
	public static function isUserAssignedAsEditor($submissionId, $stageId, $userId)
	{
		/**
		 * @var StageAssignmentDAO $stageAssignmentDao
		 * @var StageAssignment $stageAssignment
		 */
		$stageAssignmentDao = DAORegistry::getDAO('StageAssignmentDAO');
		$stageAssignments = $stageAssignmentDao->getBySubmissionAndUserIdAndStageId($submissionId, $userId, $stageId);
		$stageAssignment = $stageAssignments->next();

		if ($stageAssignment) {
			$userGroupDao = DAORegistry::getDAO('UserGroupDAO');
			/** @var UserGroup $userGroup */
			$userGroup = $userGroupDao->getById($stageAssignment->getUserGroupId());
			$role = $userGroup->getRoleId();

			if (in_array($role, [ROLE_ID_MANAGER, ROLE_ID_SUB_EDITOR, ROLE_ID_SITE_ADMIN])) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether or not the user can be counted as an editor..
	 * @param $user
	 * @param $journalId
	 * @return bool
	 */
	public static function isEditor($userId, $journalId)
	{
		$roleDao = DAORegistry::getDAO('RoleDAO');

		// Check various roles that all could be counted as editors.
		if ($roleDao->userHasRole($journalId, $userId, ROLE_ID_MANAGER)) {
			return true;
		} elseif ($roleDao->userHasRole($journalId, $userId, ROLE_ID_SUB_EDITOR)) {
			return true;
		} elseif ($roleDao->userHasRole(CONTEXT_ID_NONE, $userId, ROLE_ID_SITE_ADMIN)) {
			return true;
		} elseif ($roleDao->userHasRole($journalId, $userId, ROLE_ID_ASSISTANT)) {
			return true;
		}

		return false;
	}

	/**
	 * Gets a temporary access token from the Fidus Writer server to log the
	 * given user in. This way we avoid exposing the api key in the client.
	 * @param $userId
	 * @param $accessRights
	 */
	public static function getLoginToken($fidusUrl, $fidusId, $versionString, $userId, $isEditor, $apiKey)
	{
		$dataArray = array(
			'fidus_id' => $fidusId,
			'version' => $versionString,
			'user_id' => $userId,
			'is_editor' => $isEditor,
			'key' => $apiKey
		);

		$request = curl_init(
			$fidusUrl . '/api/ojs/get_login_token/?' . http_build_query($dataArray)
		);
		curl_setopt($request, CURLOPT_RETURNTRANSFER, true);
		$result = json_decode(curl_exec($request), true);

		return empty($result['token']) ? false : $result['token'];
	}
}

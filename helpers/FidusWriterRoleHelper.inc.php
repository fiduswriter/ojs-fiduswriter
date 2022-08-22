<?php

class FidusWriterRoleHelper
{
	/**
	 * Hook stageassignmentdao::_insertobject
	 * Assing the user to the role in FidusWriter
	 *
	 * @param $hookname
	 * @param $args
	 * @return false
	 */
	public function assignRole($hookname, $args)
	{
		$params = $this->getAssignmentData($args);

		if ($params) {
			extract($params);

			$fidusUrl .= "/api/ojs/add_{$roleType}/{$fidusId}/";
			$user = FidusWriterPluginHelper::getUser($data['user_id']);
			$data['email'] = $user->getEmail();
			$data['username'] = $user->getUserName();

			FidusWriterPluginHelper::sendPostRequest($fidusUrl, $data);
		}

		return false;
	}

	/**
	 * Hook stageassignmentdao::_deletebyall
	 * Unassing the user from the role in FidusWriter
	 *
	 * @param $hookname
	 * @param $args
	 * @return false
	 */
	public function unassignRole($hookname, $args)
	{
		$params = $this->getAssignmentData($args);

		if ($params) {
			extract($params);

			$fidusUrl .= "/api/ojs/remove_{$roleType}/{$fidusId}/";

			FidusWriterPluginHelper::sendPostRequest(
				$fidusUrl,
				$data
			);
		}

		return false;
	}

	protected function getAssignmentData($args)
	{
		$submissionId = $args[1][0];
		$userGroupId = $args[1][1];
		$userId = $args[1][2];

		$fidusUrl = FidusWriterPluginHelper::getSubmissionSetting($submissionId, 'fidusUrl');
		$fidusId = FidusWriterPluginHelper::getSubmissionSetting($submissionId, 'fidusId');

		if (!$fidusUrl || !$fidusId) {
			return false;
		}

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

		$plugin = FidusWriterPluginHelper::getFidusWriterPlugin();
		$data = [
			'key' => $plugin->getApiKey(),
			'user_id' => $userId,
			'role' => $role,
			'stage_ids' => '',
		];

		$userGroupStages = $userGroupDao->getAssignedStagesByUserGroupId($userGroup->getContextId(), $userGroup->getId());
		if (!empty($userGroupStages)) {
			$data['stage_ids'] = implode(',', array_keys($userGroupStages));
		}

		return [
			'fidusUrl' => FidusWriterPluginHelper::getSubmissionSetting($submissionId, 'fidusUrl'),
			'fidusId' => FidusWriterPluginHelper::getSubmissionSetting($submissionId, 'fidusId'),
			'submissionId' => $submissionId,
			'roleType' => $roleType,
			'data' => $data,
		];
	}
}

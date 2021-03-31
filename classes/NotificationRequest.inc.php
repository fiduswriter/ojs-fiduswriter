<?php

/**
 * Copyright (c) 2021 Takuto Kojima
 * License: GNU GPL v2. See LICENSE.md for details.
 */

import('lib.pkp.classes.core.PKPRequest');

class NotificationRequest extends PKPRequest {
	protected $user;
	protected $journalId;

	public function &getContext()
	{
		if (empty($this->journalId)) {
			$context = parent::getContext();
		} else {
			$contextDao = Application::getContextDAO();
			$context = $contextDao->getById($this->journalId);
		}

		return $context;
	}

	public function &getUser()
	{
		return $this->user;
	}

	/**
	 * @param PKPRequest $baseRequest
	 * @return NotificationRequest
	 */
	public static function create(PKPRequest $baseRequest, $user, $journalId = null)
	{
		$className = 'NotificationRequest';

		/**
		 * @var NotificationRequest
		 */
		$request = unserialize(
			preg_replace(
				'/^O:\d+:"[^"]++"/',
				'O:'.strlen($className).':"'.$className.'"',
				serialize($baseRequest)
			)
		);

		$request->user = $user;
		$request->journalId = $journalId;

		return $request;
	}
}

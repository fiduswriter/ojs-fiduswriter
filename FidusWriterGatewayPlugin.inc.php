<?php

/**
 * Copyright (c) 2015-2017 Afshin Sadehghi
 * Copyright (c) 2017 Firas Kassawat
 * Copyright (c) 2016-2018 Johannes Wilm
 * Copyright (c) 2014-2017 Simon Fraser University
 * Copyright (c) 2000-2017 John Willinsky
 * License: GNU GPL v2. See LICENSE.md for details.
 */

import('lib.pkp.classes.security.authorization.PolicySet');
import('lib.pkp.classes.plugins.GatewayPlugin');

class FidusWriterGatewayPlugin extends GatewayPlugin
{
	// BEGIN STANDARD PLUGIN FUNCTIONS
	protected $parentPlugin;

	/**
	 * Constructor
	 * @param $parentPlugin FidusWriterPlugin
	 */
	function __construct($parentPlugin)
	{
		$this->parentPlugin = $parentPlugin;
		parent::__construct();
	}

	/**
	 * Get the name of this plugin. The name must be unique within
	 * its category.
	 * @return String name of plugin
	 */
	public function getName()
	{
		return 'FidusWriterGatewayPlugin';
	}

	/**
	 * Hide this plugin from the management interface (it's subsidiary)
	 */
	public function getHideManagement()
	{
		return true;
	}

	/**
	 * @copydoc Plugin::getDisplayName()
	 */
	public function getDisplayName()
	{
		return __('plugins.generic.fidusWriter.displayName');
	}

	/**
	 * @copydoc Plugin::getDescription()
	 */
	public function getDescription()
	{
		return __('plugins.generic.fidusWriter.description');
	}

	/**
	 * Override the builtin to get the correct plugin path.
	 */
	public function getPluginPath()
	{
		return $this->parentPlugin->getPluginPath();
	}

	/**
	 * Get whether or not this plugin is enabled. (Should always return true, as the
	 * parent plugin will take care of loading this one when needed)
	 * @return boolean
	 */
	public function getEnabled()
	{
		return $this->parentPlugin->getEnabled();
	}

	/**
	 * Override the builtin to get the correct template path.
	 * @return string
	 */
	public function getTemplatePath($inCore = false)
	{
		return $this->parentPlugin->getTemplatePath($inCore);
	}

	/**
	 * @see Plugin::isSitePlugin()
	 */
	function isSitePlugin()
	{
		return true;
	}
	// END STANDARD PLUGIN FUNCTIONS

	/**
	 * @return mixed|null
	 */
	public function getApiKey()
	{
		return $this->parentPlugin->getApiKey();
	}

	/**
	 * @return string
	 */
	public function getApiVersion()
	{
		return "1.0";
	}

	/**
	 * Handle all requests for this plugin.
	 * @param $args array
	 * @param $request PKPRequest Request object
	 * @return bool
	 */
	public function fetch($args, $request)
	{
		if (!$this->getEnabled()) {
			return false;
		}

		ignore_user_abort(true);
		set_time_limit(0);
		ob_start();

		try {
			$restCallType = $this->getRESTRequestType();
			$operator = array_shift($args);

			if ($restCallType === "GET") {
				$this->parentPlugin->import('gateways.FidusWriterGetRequestHandler');
				$requestHandler = new FidusWriterGetRequestHandler($request, $this->getApiKey(), $this->getApiVersion());
				$handlerName = "get_{$operator}";
				$response = false;
				if (method_exists($requestHandler, $handlerName)) {
					$response = $requestHandler->$handlerName();
				}

				if ($response) {
					$this->sendJsonResponse($response);
				} else {
					$error = "Not a valid request";
					$this->sendErrorResponse($error);
				}
			}

			if ($restCallType === "POST") {
				$key = $_GET['key'];
				if ($this->getApiKey() !== $key) {
					// Not correct api key.
					$error = "Incorrect API Key";
					$this->sendErrorResponse($error);
				}

				$this->parentPlugin->import('gateways.FidusWriterSubmissionHandler');
				$submissionHandler = new FidusWriterSubmissionHandler($request, $this->getApiKey(), $this->getApiVersion());
				$handlerName = "post_{$operator}";
				$response = false;
				if (method_exists($submissionHandler, $handlerName)) {
					$response = $submissionHandler->$handlerName();
				}

				if ($response) {
					$this->sendJsonResponse($response);
				} else {
					$error = "Not a valid request";
					$this->sendErrorResponse($error);
				}
			}


			if ($restCallType === "PUT") {
				$response = [
					"message" => "PUT response",
					"version" => $this->getApiVersion()
				];
				$this->sendJsonResponse($response);
			}

			if ($restCallType === "DELETE") {
				$response = [
					"message" => "DELETE response",
					"version" => $this->getApiVersion()
				];
				$this->sendJsonResponse($response);
			}

			return true;
		} catch (Exception $e) {
			$this->sendErrorResponse($e->getMessage());
			return true;
		}
	}

	/**
	 * @return string
	 */
	function getRESTRequestType()
	{
		$callType = $_SERVER['REQUEST_METHOD'];
		switch ($callType) {
			case 'PUT':
			case 'DELETE':
			case 'GET':
			case 'POST':
				$result = $callType;
				break;
			default:
				$result = "";
		}
		return $result;
	}

	/**
	 * @param array $response
	 */
	function sendJsonResponse($response)
	{
		header("Content-Type: application/json;charset=utf-8");
		http_response_code(200);
		echo json_encode($response);
		header('Connection: close');
		header('Content-Length: ' . ob_get_length());
		ob_flush();
		flush();
		ob_end_flush();
	}

	/**
	 * Display an error message and exit
	 * @param $errorMessage
	 */
	public function sendErrorResponse($errorMessage)
	{
		header("HTTP/1.0 500 Internal Server Error");
		http_response_code(500);
		$response = [

			"error" => "internal server error",
			"errorMessage" => $errorMessage,
			"code" => "500"
		];
		echo json_encode($response);

		header('Connection: close');
		header('Content-Length: ' . ob_get_length());
		ob_flush();
		flush();
		ob_end_flush();
	}
}

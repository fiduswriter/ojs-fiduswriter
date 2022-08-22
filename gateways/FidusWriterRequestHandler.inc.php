<?php
class FidusWriterRequestHandler {
	/**
	 * @var PKPRequest
	 */
	public $request;
	public $apiKey;
	public $apiVersion;
	public $locale;

	/**
	 * @param $request PKPRequest
	 * @param $apiKey string
	 * @param $apiVersion string
	 * @return void
	 */
	public function __construct($request, $apiKey, $apiVersion)
	{
		$this->request = $request;
		$this->apiKey = $apiKey;
		$this->apiVersion = $apiVersion;
		$this->locale = AppLocale::getLocale();
	}
}

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

class FidusWriterSettingsForm extends Form {

	/** @var $plugin object */
	private $_plugin;

	/**
	* Constructor
	* @param $plugin object
	* @param $journalId int
	*/
	function __construct($plugin) {
		$this->_plugin = $plugin;

		parent::__construct($plugin->getTemplatePath() . 'settingsForm.tpl');
		$this->addCheck(new FormValidatorPost($this));
		$this->addCheck(new FormValidatorCSRF($this));
	}

	/**
	* Initialize form data.
	*/
	function initData() {
		$plugin = $this->_plugin;
		$this->setData('apiKey', $plugin->getSetting(CONTEXT_ID_NONE, 'apiKey'));
	}

	/**
	* Assign form data to user-submitted data.
	*/
	function readInputData() {
		$this->readUserVars(array('apiKey',));
		$this->addCheck(new FormValidator($this, 'apiKey', 'required', 'plugins.generic.fidusWriter.manager.settings.apiKeyRequired'));
	}

	/**
	* Save settings.
	*/
	function execute($object = NULL) {
		$plugin = $this->_plugin;
		$plugin->updateSetting(CONTEXT_ID_NONE, 'apiKey', trim($this->getData('apiKey'),"\"\';"), 'string');
	}

	/**
	* Fetch the form.
	* @copydoc Form::fetch()
	*/
	function fetch($request, $template = NULL, $display = false) {
		$templateMgr = TemplateManager::getManager($request);
		$templateMgr->assign('pluginName', $this->_plugin->getName());
		return parent::fetch($request);
	}

}

?>

<?php
class FidusWriterSchemaHelper {
	public function hookSubmissionSchema($hookName, $args)
	{
		$schema = $args[0];

		$schema->properties->fidusId = (object)[
			'type' => 'string',
			'apiSummary' => true,
			'validation' => ['nullable']
		];

		$schema->properties->fidusUrl = (object)[
			'type' => 'string',
			'apiSummary' => true,
			'validation' => ['nullable']
		];
	}
}

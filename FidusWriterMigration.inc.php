<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Capsule\Manager as Capsule;

class FidusWriterMigration extends Migration {
	/**
	 * Run the migrations.
	 * @return void
	 */
	public function up() {
		Capsule::schema()->create('ojs_fiduswriter_revisions', function (Blueprint $table) {
			$table->bigInteger('ojs_fiduswriter_revision_id')->autoIncrement();
			$table->tinyInteger('review_round');
			$table->string('revision_url', 255);
			$table->primary('ojs_fiduswriter_revision_id');
		});
	}
}

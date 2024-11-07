<?php

namespace towardstudio\entrytemplates\migrations;

use Craft;
use craft\db\Migration;

/**
 * Install migration.
 */
class Install extends Migration
{
	// Public Properties
	// =========================================================================

	/**
	 * @var ?string The database driver to use
	 */
	public ?string $driver = null;

	/**
	 * @inheritdoc
	 */
	public function safeUp(): bool
	{
		$this->driver = Craft::$app->getConfig()->getDb()->driver;
		if ($this->createTables()) {
			// Refresh the db schema caches
			Craft::$app->db->schema->refresh();
		}

		return true;
	}

	/**
	 * @inheritdoc
	 */
	public function safeDown(): bool
	{
		$this->dropTableIfExists("{{%towardtemplates}}");

		return true;
	}

	// Protected Properties
	// =========================================================================

	/**
	 * @return bool
	 */
	protected function createTables(): bool
	{
		$tablesCreated = false;

		$tableSchema = Craft::$app->db->schema->getTableSchema(
			"{{%towardtemplates}}"
		);
		if ($tableSchema === null) {
			$tablesCreated = true;

			$this->createTable("{{%towardtemplates}}", [
				"id" => $this->integer()->notNull(),
				"typeId" => $this->integer()->notNull(),
				"typeId" => $this->integer()->notNull(),
				"sectionIds" => $this->string(),
				"previewImage" => $this->integer(),
				"description" => $this->string(),

				"PRIMARY KEY([[id]])",
			]);
			$this->createIndex(null, "{{%towardtemplates}}", ["typeId"], false);
			$this->addForeignKey(
				null,
				"{{%towardtemplates}}",
				["id"],
				"{{%elements}}",
				["id"],
				"CASCADE",
				null
			);
			$this->addForeignKey(
				null,
				"{{%towardtemplates}}",
				["typeId"],
				"{{%entrytypes}}",
				["id"],
				"CASCADE",
				null
			);

			return true;
		}

		return $tablesCreated;
	}
}

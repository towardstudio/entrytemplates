<?php

namespace towardstudio\entrytemplates\migrations;

use Craft;
use craft\db\Migration;
use craft\models\FieldLayout;

use towardstudio\entrytemplates\records\EntryTemplate;

/**
 * m240628_102424_craft_5 migration.
 */
class m240628_102424_craft_5 extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        // Change Structure to Section in DB
        if ($this->db->columnExists('{{%towardtemplates}}', 'structureId')) {
            $this->renameColumn('{{%towardtemplates}}', 'structureId', 'sectionIds');
            $this->alterColumn('{{%towardtemplates}}', 'sectionIds', 'string');

            $transaction = Craft::$app->getDb()->beginTransaction();
            $records = EntryTemplate::find()
                ->all();

            foreach($records as $record)
            {
                $sectionIds = $record->getAttribute('sectionIds');
                $record->setAttribute('sectionIds', serialize([intval($sectionIds)]));
                $record->save();
            }
            $transaction->commit();

            // Content Migration
            foreach (Craft::$app->getEntries()->getAllEntryTypes() as $blockType) {
                $this->updateElements(
                    (new Query())->from('{{%towardtemplates}}')->where(['typeId' => $blockType->id]),
                    $blockType->getFieldLayout(),
                );
            }

        }

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        echo "m240628_102424_craft_5 cannot be reverted.\n";
        return false;
    }
}

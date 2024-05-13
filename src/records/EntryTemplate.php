<?php

namespace towardstudio\entrytemplates\records;

use craft\db\ActiveRecord;
use craft\records\Element;
use craft\records\EntryType;
use yii\db\ActiveQueryInterface;

class EntryTemplate extends ActiveRecord
{
    /**
     * @inheritdoc
     */
    public static function tableName(): string
    {
        return '{{%towardtemplates}}';
    }

    /**
     * Returns the content template's element.
     *
     * @return ActiveQueryInterface
     */
    public function getElement(): ActiveQueryInterface
    {
        return $this->hasOne(Element::class, ['id' => 'id']);
    }

    /**
     * Returns the content template's entry type.
     *
     * @return ActiveQueryInterface
     */
    public function getType(): ActiveQueryInterface
    {
        return $this->hasOne(EntryType::class, ['id' => 'typeId']);
    }
}

<?php

namespace towardstudio\entrytemplates\elements\db;

use Craft;
use craft\elements\Entry;
use craft\db\Query;
use craft\elements\db\ElementQuery;
use craft\db\Table;
use craft\helpers\Db;

/**
 * Content template element query class.
 *
 * @package spicyweb\towardtemplates\elements\db
 * @author Spicy Web <plugins@spicyweb.com.au>
 * @since 1.0.0
 */
class EntryTemplateQuery extends ElementQuery
{
    /**
     * @var int[]|int|null The entry type ID(s) for this query.
     */
    public array|int|null $typeId = null;

    /**
     * @var int[]|int|null The entry type ID(s) for this query.
     */
    public array|string|null $sectionIds = null;

     /**
     * @var array[]|int|null The preview image ID(s) for this query.
     */
    public array|null $previewImage = null;

    /**
     * Filters the query results based on the entry type IDs.
     *
     * @param int[]|int|null $value The entry type ID(s).
     * @return self
     */
    public function typeId(array|int|null $value): self
    {
        $this->typeId = $value;

        return $this;
    }

    /**
     * Filters the query results based on the entry type sections.
     *
     * @param int[]|int|null $value The entry type section ID(s).
     * @return self
     */
    public function sectionIds(array|int|null $value): self
    {
        $this->sectionIds = $value;

        return $this;
    }

    /**
     * Filters the query results based on the entry type IDs.
     *
     * @param int[]|int|null $value The entry type ID(s).
     * @return self
     */
    public function previewImage(array|int|null $value): self
    {
        $this->previewImage = $value;

        return $this;
    }

    /**
     * @inheritdoc
     */
    protected function fieldLayouts(): array
    {
        $layouts = Craft::$app->getFields()->getLayoutsByType(Entry::class);
        return $layouts;
    }

    /**
     * @inheritdoc
     */
    protected function beforePrepare(): bool
    {
        $this->joinElementTable('towardtemplates');

        $this->query->select([
            'towardtemplates.id',
            'towardtemplates.typeId',
            'towardtemplates.previewImage',
            'towardtemplates.description',
        ]);

        if ($this->draftId) {
            $this->subQuery->andWhere(['elements.draftId' => $this->draftId]);
        }

        if ($this->typeId) {
            $this->subQuery->andWhere(['towardtemplates.typeId' => $this->typeId]);

            // Should we set the structureId param?
            if (
                $this->withStructure !== false &&
                !isset($this->structureId) &&
                (is_numeric($this->typeId) || count($this->typeId) === 1)
            ) {
                $structureId = (new Query())
                    ->select(['sectionIds'])
                    ->from(['cts' => '{{%towardtemplates}}'])
                    ->where(['typeId' => $this->typeId])
                    ->scalar();

                if ($structureId) {
                    $this->structureId = $structureId;
                } else {
                    $this->withStructure = false;
                }
            }
        }

        return parent::beforePrepare();
    }

}

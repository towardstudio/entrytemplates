<?php

namespace towardstudio\entrytemplates\elements\db;

use craft\db\Query;
use craft\elements\db\ElementQuery;
use craft\db\Table;

/**
 * Content template element query class.
 *
 * @package spicyweb\towardtemplates\elements\db
 * @author Spicy Web <plugins@spicyweb.com.au>
 * @since 1.0.0
 */
class EntryTemplateQuery extends ElementQuery
{
    public const TOWARDTEMPLATES = '{{%towardtemplates}}';

    /**
     * @var int[]|int|null The entry type ID(s) for this query.
     */
    public array|int|null $typeId = null;

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

    protected function beforePrepare(): bool
    {
        $this->_normalizeTypeId();

        // See if 'type' as set to invalid handle
        if ($this->typeId === []) {
            return false;
        }

        $this->joinElementTable(self::TOWARDTEMPLATES);

        $this->query->addSelect([
            'towardtemplates.id',
            'towardtemplates.typeId',
            'towardtemplates.previewImage',
            'towardtemplates.description',
        ]);

        if ($this->typeId) {
            $this->subQuery->andWhere(['towardtemplates.typeId' => $this->typeId]);

            // Should we set the structureId param?
            if (
                $this->withStructure !== false &&
                !isset($this->structureId) &&
                (is_numeric($this->typeId) || count($this->typeId) === 1)
            ) {
                $structureId = (new Query())
                    ->select(['structureId'])
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

    /**
     * Normalizes the typeId param to an array of IDs or null
     *
     * @throws InvalidConfigException
     */
    private function _normalizeTypeId(): void
    {
        if (empty($this->typeId)) {
            $this->typeId = is_array($this->typeId) ? [] : null;
        } elseif (is_numeric($this->typeId)) {
            $this->typeId = [$this->typeId];
        } elseif (!is_array($this->typeId) || !ArrayHelper::isNumeric($this->typeId)) {
            $this->typeId = (new Query())
                ->select(['id'])
                ->from([Table::ENTRYTYPES])
                ->where(Db::parseNumericParam('id', $this->typeId))
                ->column();
        }
    }

}

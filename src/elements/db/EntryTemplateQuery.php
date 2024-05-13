<?php

namespace towardstudio\entrytemplates\elements\db;

use craft\db\Query;
use craft\elements\db\ElementQuery;

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

    protected function beforePrepare(): bool
    {
        $this->joinElementTable('towardtemplates');

        $this->query->select([
            'towardtemplates.typeId',
            'towardtemplates.previewImage',
            'towardtemplates.description',
        ]);

        if ($this->typeId) {
            $this->query->leftJoin(
                '{{%towardtemplatestructure}} cts',
                '[[cts.typeId]] = [[towardtemplates.typeId]]',
            );
            $this->subQuery->andWhere(['towardtemplates.typeId' => $this->typeId]);

            // Should we set the structureId param?
            if (
                $this->withStructure !== false &&
                !isset($this->structureId) &&
                (is_numeric($this->typeId) || count($this->typeId) === 1)
            ) {
                $structureId = (new Query())
                    ->select(['structureId'])
                    ->from(['cts' => '{{%towardtemplatestructure}}'])
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

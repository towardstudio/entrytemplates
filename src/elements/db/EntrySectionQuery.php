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
class EntrySectionQuery extends ElementQuery
{
    public const TOWARDTEMPLATES = '{{%towardtemplates}}';

    /**
     * @var int|null The structure/section ID(s) for this query.
     */
    public mixed $structureId = null;

    /**
     * Filters the query results based on the entry type IDs.
     *
     * @param int[]|int|null $value The entry type ID(s).
     * @return static
     */
    public function structureId(?int $value = null): static
    {
        $this->structureId = $value;
        return $this;
    }

    protected function beforePrepare(): bool
    {
        // See if 'type' as set to invalid handle
        if ($this->structureId === []) {
            return false;
        }

        $this->joinElementTable(self::TOWARDTEMPLATES);

        $this->query->addSelect([
            'towardtemplates.id',
            'towardtemplates.typeId',
            'towardtemplates.sectionIds',
            'towardtemplates.previewImage',
        ]);

        $this->subQuery->andWhere(['towardtemplates.sectionIds' => $this->structureId]);

        return parent::beforePrepare();
    }

}

<?php
namespace towardstudio\entrytemplates\services;

use Craft;
use craft\events\ConfigEvent;
use craft\models\Section as SectionModel;
use craft\services\Entries;
use craft\records\Section as SectionRecord;

use towardstudio\entrytemplates\records\EntryTemplate as EntryTemplateRecord;
use towardstudio\entrytemplates\elements\EntryTemplate as EntryTemplateElement;

use yii\base\Component;
use Throwable;

// Illuminate
use Illuminate\Support\Collection;

class EntryTypes extends Component
{
    /**
     * Get all Entry Types which belong to a section
     */
    public function getAll(): ?array
    {
        $entryTypes = Collection::make(Craft::$app->getEntries()->getAllEntryTypes())->all();
        $typesToSelect = [];

        foreach ($entryTypes as $type) {
            if (!isset($typesToSelect[$type->handle])) {
                $typesToSelect[$type->handle] = (object) [
                    'handle' => $type->handle,
                    'sites' => [],
                    'sections' => [],
                    'id' => $type->id,
                    'name' => $type->name,
                    'uid' => $type->uid,
                ];
            }

            $usages = $type->findUsages();

            foreach ($usages as $item) {
                if (is_a($item, SectionModel::class))
                {
                    $settings = $item->siteSettings;
                    $sites = [];
                    $sections = [];

                    foreach ($settings as $setting)
                    {
                        if(!in_array($setting->siteId, $typesToSelect[$type->handle]->sites, true)) {
                            array_push($typesToSelect[$type->handle]->sites, $setting->siteId);
                        }

                        if(!in_array($setting->section->id, $typesToSelect[$type->handle]->sections, true)){
                            array_push($typesToSelect[$type->handle]->sections, $setting->section->id);
                        }
                    }
                }
            }
        }

        $availableTypes = array_filter($typesToSelect, function($value, $k) {
            return !empty($value->sections);
        }, ARRAY_FILTER_USE_BOTH);

        return [
            'entryTypes' => $availableTypes,
        ];
    }

    public function removeSectionIdFromRecord(int $sectionId): void
    {
        $records = EntryTemplateRecord::find()
            ->where(['like', 'sectionIds', $sectionId])
            ->all();


        if ($records)
        {
            $transaction = Craft::$app->getDb()->beginTransaction();

            foreach ($records as $record)
            {
                $ids = unserialize($record->sectionIds);
                unset($ids[array_search($sectionId, $ids)]);
                $record->setAttribute('sectionIds', serialize($ids));
                $record->save();
            }

            $transaction->commit();
        }
    }

    public function updateRecord(array $types, int $sectionId): void
    {
        $ids = array_column($types, 'id');

        $transaction = Craft::$app->getDb()->beginTransaction();

        foreach($ids as $id)
        {
            $record = EntryTemplateRecord::find()
                ->where('typeId=:typeId')
                ->addParams(['typeId' => (int)$id])
                ->one();

            if ($record)
            {
                $ids = unserialize($record->sectionIds);

                if (!in_array($sectionId, $ids))
                {
                    array_push($ids, $sectionId);
                }

                $record->setAttribute('sectionIds', serialize($ids));
                $record->save();

            }
        }

        $transaction->commit();

    }

    public function handleUpdatedSectionTypes(ConfigEvent $event)
    {
        $sectionUid = $event->tokenMatches[0];

        try {
            $sectionRecord = $this->_getSectionRecord($sectionUid, true);
        } catch (Throwable $e) {
            throw $e;
        }

        $sectionId = $sectionRecord->id;

        if ($sectionId)
        {
            $section = Craft::$app->entries->getSectionById($sectionId);

            if ($section) {

                // Fist we want to remove the Section ID from all records
                $this->removeSectionIdFromRecord($sectionId);

                // Get entry types
                $entryTypes = $section->entryTypes;

                if ($entryTypes)
                {
                    $this->updateRecord($entryTypes, $sectionId);
                }

            }
        }
    }


    /**
     * Gets a sections's record by uid.
     *
     * @param string $uid
     * @param bool $withTrashed Whether to include trashed sections in search
     * @return SectionRecord
     */
    private function _getSectionRecord(string $uid, bool $withTrashed = false): SectionRecord
    {
        $query = $withTrashed ? SectionRecord::findWithTrashed() : SectionRecord::find();
        $query->andWhere(['uid' => $uid]);
        /** @noinspection PhpIncompatibleReturnTypeInspection */
        /** @var SectionRecord */
        return $query->one() ?? new SectionRecord();
    }
}

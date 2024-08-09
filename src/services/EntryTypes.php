<?php
namespace towardstudio\entrytemplates\services;

use Craft;
use craft\models\Section as SectionModel;
use yii\base\Component;

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
}

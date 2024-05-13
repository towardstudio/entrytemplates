<?php
namespace towardstudio\entrytemplates\services;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Asset;
use craft\events\ConfigEvent;
use craft\helpers\Db;
use craft\helpers\ProjectConfig as ProjectConfigHelper;
use craft\models\Structure;
use craft\services\Structures;
use towardstudio\entrytemplates\elements\EntryTemplate;
use towardstudio\entrytemplates\records\EntryTemplate as EntryTemplateRecord;
use yii\base\Component;

class ProjectConfig extends Component
{
    /**
     * Handles an external change to the order of content templates for an entry type.
     *
     * @param ConfigEvent $event
     * @throws \Throwable
     */
    public function handleChangedContentTemplateOrder(ConfigEvent $event): void
    {
        $projectConfig = Craft::$app->getProjectConfig();

        if (!$projectConfig->getIsApplyingExternalChanges()) {
            return;
        }

        $typeUid = $event->tokenMatches[0];
        $newOrder = $event->newValue;

        // Ensure all content template changes have been processed
        foreach ($newOrder as $contentTemplateUid) {
            $projectConfig->processConfigChanges("entryTemplates.templates.$contentTemplateUid");
        }

        Craft::$app->getDb()->transaction(function() use ($typeUid, $newOrder) {
            $structuresService = Craft::$app->getStructures();
            $structureId = $this->_structureId(Db::idByUid(Table::ENTRYTYPES, $typeUid));

            // Get all element IDs currently on the structure, so we know which ones to delete afterwards
            $elementIdsToRemove = (new Query())
                ->select(['elementId', 'id'])
                ->from(['se' => Table::STRUCTUREELEMENTS])
                ->where(['structureId' => $structureId])
                ->pairs();

            // Append the content templates to the structure according to the project config order
            foreach ($newOrder as $contentTemplateUid) {
                $entryTemplate = new EntryTemplate();
                $entryTemplate->id = Db::idByUid(Table::ELEMENTS, $contentTemplateUid);
                unset($elementIdsToRemove[$entryTemplate->id]);
                $structuresService->appendToRoot($structureId, $entryTemplate);
            }

            // Delete from the structure anything that shouldn't be there anymore
            foreach (array_keys($elementIdsToRemove) as $elementIdToRemove) {
                $entryTemplate = new EntryTemplate();
                $entryTemplate->id = (int)$elementIdToRemove;
                $structuresService->remove($structureId, $entryTemplate);
            }
        });
    }

    /**
     * Handles deleting a content template.
     *
     * @param ConfigEvent $event
     * @throws \Throwable
     */
    public function handleDeletedContentTemplate(ConfigEvent $event): void
    {
        // If the changes aren't external, the element is already deleted
        if (Craft::$app->getProjectConfig()->getIsApplyingExternalChanges()) {
            $uid = $event->tokenMatches[0];
            $id = Db::idByUid(Table::ELEMENTS, $uid);
            Craft::$app->getElements()->deleteElementById($id);
        }
    }

    /**
     * Handles a content template change.
     *
     * @param ConfigEvent $event
     * @throws \Throwable
     */
    public function handleChangedContentTemplate(ConfigEvent $event): void
    {
        // Make sure the fields have been synced
        ProjectConfigHelper::ensureAllFieldsProcessed();
        $this->save($event->tokenMatches[0], $event->newValue);
    }

    public function save(string $uid, array $data): void
    {
        Craft::$app->getDb()->transaction(function() use ($uid, $data) {
            $projectConfig = Craft::$app->getProjectConfig();
            $id = Db::idByUid(Table::ELEMENTS, $uid);
            $record = EntryTemplateRecord::findOne(['id' => $id]);

            if ($record === null) {
                $record = new EntryTemplateRecord();
            } elseif ($projectConfig->getIsApplyingExternalChanges()) {
                // If we're applying external changes, we'll need to resave the element with the new content
                $elementsService = Craft::$app->getElements();
                $entryTemplate = $elementsService->getElementById($id);
                $entryTemplate->setFieldValues($data['content']);
                $elementsService->saveElement($entryTemplate);
            }

            if (!isset($data['preview'])) {
                $preview = null;
            } elseif (is_string($data['preview'])) {
                $preview = Craft::$app->getElements()->getElementByUid($data['preview'], Asset::class);
            } else {
                $volumeId = Db::idByUid(Table::VOLUMES, $data['preview']['volume']);
                $folderId = (new Query())
                    ->select(['id'])
                    ->from(Table::VOLUMEFOLDERS)
                    ->where([
                        'volumeId' => $volumeId,
                        'path' => $data['preview']['folderPath'],
                    ])
                    ->scalar();
                $preview = Asset::find()
                    ->volumeId($volumeId)
                    ->folderId($folderId)
                    ->filename($data['preview']['filename'])
                    ->one();
            }

            $typeId = Db::idByUid(Table::ENTRYTYPES, $data['type']);
            $record->id = $id;
            $record->typeId = $typeId;
            $record->previewImage = $data['previewImage'] ?? null;
            $record->description = $data['description'] ?? null;
            $record->save();

            // Put the content template in the correct place in the structure for its entry type, creating the structure
            // if it doesn't exist
            $structuresService = Craft::$app->getStructures();
            $entryTemplate = new EntryTemplate();
            $entryTemplate->id = $record->id;
            $structureId = $this->_structureId($typeId);

            $typeOrder = Craft::$app->getProjectConfig()->get("entryTemplates.orders.{$data['type']}");
            $sortOrder = $typeOrder ? array_search($uid, $typeOrder) : false;

            if (!$sortOrder) {
                $structuresService->prependToRoot($structureId, $entryTemplate);
            } else {
                $prevContentTemplate = new EntryTemplate();
                $prevContentTemplate->id = Db::idByUid(Table::ELEMENTS, $typeOrder[$sortOrder - 1]);
                $structuresService->moveAfter($structureId, $entryTemplate, $prevContentTemplate);
            }
        });
    }

    /**
     * Gets the structure ID for an entry type's content templates, creating the structure if it doesn't exist.
     */
    public function _structureId(int $typeId) {
        $structureId = (new Query())
            ->select(['structureId'])
            ->from(['cts' => '{{%towardtemplatestructure}}'])
            ->where(['typeId' => $typeId])
            ->scalar();

        if (!$structureId) {
            $structure = new Structure();
            $structure->maxLevels = 1;
            Craft::$app->getStructures()->saveStructure($structure);
            $structureId = $structure->id;
            Db::insert('{{%towardtemplatestructure}}', [
                'typeId' => $typeId,
                'structureId' => $structureId,
            ]);
        }

        return $structureId;
    }
}

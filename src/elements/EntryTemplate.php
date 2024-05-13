<?php
namespace towardstudio\entrytemplates\elements;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Entry;
use craft\elements\User;
use craft\fieldlayoutelements\entries\EntryTitleField;
use craft\fields\Matrix as MatrixField;
use craft\helpers\App;
use craft\helpers\Cp;
use craft\helpers\FileHelper;
use craft\helpers\UrlHelper;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\Section;
use ether\seo\fields\SeoField;

use towardstudio\entrytemplates\elements\db\EntryTemplateQuery;
use towardstudio\entrytemplates\EntryTemplates;

use towardstudio\entrytemplates\assetbundles\PreviewImageAsset;
use verbb\supertable\fields\SuperTableField;
use verbb\supertable\SuperTable;
use yii\db\Expression;
use yii\web\Response;

class EntryTemplate extends Element
{
    /**
     * @var ?int The ID of the entry type this content template is for.
     */
    public ?int $typeId = null;

    /**
     * @var string|null The content template's preview image filename.
     */
    public ?string $previewImage = null;

    /**
     * @var ?string The description of this content template.
     */
    public ?string $description = null;

    /**
     * @var ?int The structure ID for this content template.
     */
    public ?int $structureId = null;

    /**
     * @var ?Section
     */
    private ?Section $_section = null;

    /**
     * @var ?EntryType
     */
    private ?EntryType $_entryType = null;

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return Craft::t('entrytemplates', 'Entry Template');
    }

    /**
     * @inheritdoc
     */
    public static function lowerDisplayName(): string
    {
        return Craft::t('entrytemplates', 'entry template');
    }

    /**
     * @inheritdoc
     */
    public static function hasContent(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public static function hasTitles(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public static function isLocalized(): bool
    {
        return false;
    }

    /**
     * @inheritdoc
     */
    public static function hasStatuses(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public static function find(): EntryTemplateQuery
    {
        return new EntryTemplateQuery(static::class);
    }

    /**
     * @inheritdoc
     */
    protected static function defineSources(string $context): array
    {
        $sources = [];

        foreach (Craft::$app->getSections()->getAllEntryTypes() as $entryType) {
            $section = $entryType->getSection();
            $source = [
                'key' => 'entryType:' . $entryType->uid,
                'label' => Craft::t('site', $section->name . ' - ' . $entryType->name),
                'sites' => $section->getSiteIds(),
                'data' => [
                    'handle' => $section->handle . '/' . $entryType->handle,
                ],
                'criteria' => [
                    'typeId' => $entryType->id,
                ],
            ];
            $structureId = (new Query())
                ->select(['structureId'])
                ->from(['cts' => '{{%towardtemplatestructure}}'])
                ->where(['typeId' => $entryType->id])
                ->scalar();

            if ($structureId) {
                $user = Craft::$app->getUser()->getIdentity();
                $source['defaultSort'] = ['structure', 'asc'];
                $source['structureId'] = $structureId;
                $source['structureEditable'] = $user && $user->can("saveEntries:$section->uid");
            } else {
                $source['defaultSort'] = ['postDate', 'desc'];
            }

            $sources[] = $source;
        }

        return $sources;
    }

    /**
     * @inheritdoc
     */
    protected static function defineSortOptions(): array
    {
        return [
            [
                'label' => Craft::t('entrytemplates', 'Date Updated'),
                'orderBy' => function(int $dir) {
                    if ($dir === SORT_ASC) {
                        if (Craft::$app->getDb()->getIsMysql()) {
                            return new Expression('[[elements.dateUpdated]] IS NOT NULL DESC, [[elements.dateUpdated]] ASC');
                        } else {
                            return new Expression('[[elements.dateUpdated]] ASC NULLS LAST');
                        }
                    }
                    if (Craft::$app->getDb()->getIsMysql()) {
                        return new Expression('[[elements.dateUpdated]] IS NULL DESC, [[elements.dateUpdated]] DESC');
                    } else {
                        return new Expression('[[elements.dateUpdated]] DESC NULLS FIRST');
                    }
                },
                'attribute' => 'dateUpdated',
                'defaultDir' => 'desc',
            ],
        ];
    }

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['typeId'], 'number', 'integerOnly' => true];
        $rules[] = [['description'], 'string'];

        return $rules;
    }

    /**
     * @inheritdoc
     */
    public function prepareEditScreen(Response $response, string $containerId): void
    {
        $entryType = $this->getEntryType();
        $section = $this->getSection();
        $response->crumbs([
            [
                'label' => self::pluralDisplayName(),
                'url' => UrlHelper::cpUrl('entrytemplates'),
            ],
            [
                'label' => Craft::t('site', '{section} - {entryType}', [
                    'section' => $section->name,
                    'entryType' => $entryType->name,
                ]),
                'url' => UrlHelper::cpUrl("entrytemplates/$section->handle/$entryType->handle"),
            ],
        ]);
    }

    /**
     * @inheritdoc
     */
    public function afterSave(bool $isNew): void
    {
        $projectConfig = Craft::$app->getProjectConfig();

        // If this element has saved as a result of applying project config changes, opt out of infinite recursion
        if ($projectConfig->getIsApplyingExternalChanges()) {
            return;
        }

        $request = Craft::$app->getRequest();
        $this->previewImage = $request->getBodyParam('previewImage') ?: null;
        $this->description = $request->getBodyParam('description');
        $config = $this->getConfig();

        if ($this->getIsDraft()) {
            EntryTemplates::$plugin->projectConfig->save($this->uid, $config);
        } else {
            // Save the position in the order first
            $sortOrder = $config['sortOrder'];
            $typeOrderPath = "entryTemplates.orders.{$config['type']}";
            $typeOrder = $projectConfig->get($typeOrderPath) ?? [];
            $currentPositionInPath = array_search($this->uid, $typeOrder);

            if ($currentPositionInPath === false) {
                // New content templates get added to the top
                array_unshift($typeOrder, $this->uid);
            } elseif ($currentPositionInPath !== $sortOrder) {
                array_splice($typeOrder, $currentPositionInPath, 1);
                array_splice($typeOrder, $sortOrder, 0, $this->uid);
            }

            $projectConfig->set($typeOrderPath, $typeOrder);

            // Now save the actual template config
            unset($config['sortOrder']);
            $projectConfig->set("entryTemplates.templates.$this->uid", $config);
        }

        parent::afterSave($isNew);
    }

    /**
     * @inheritdoc
     */
    public function afterDelete(): void
    {
        $projectConfig = Craft::$app->getProjectConfig();

        if (!$projectConfig->getIsApplyingExternalChanges()) {
            // Remove this content template's data from the project config
            $projectConfig->remove("entryTemplates.$this->uid");
            $projectConfig = Craft::$app->getProjectConfig();
            $projectConfig->remove("entryTemplates.templates.$this->uid");
            $typeOrderPath = "entryTemplates.orders.{$this->getEntryType()->uid}";
            $typeOrder = $projectConfig->get($typeOrderPath);

            if ($typeOrder !== null && ($sortOrder = array_search($this->uid, $typeOrder)) !== false) {
                array_splice($typeOrder, $sortOrder, 1);
                $projectConfig->set($typeOrderPath, $typeOrder);
            }
        }

        parent::afterDelete();
    }

    /**
     * Gets the URL for this content template's preview image, if one is set.
     *
     * @param array|null $transform The width and height to scale/crop the image to.
     * @return string|null
     */
    public function getPreviewImageUrl(?array $transform = null): ?string
    {
        return $this->previewImage
            ? EntryTemplates::$plugin->previewImages->getPreviewImageUrl($this->previewImage, $transform)
            : null;
    }

    /**
     * Returns this content template's config.
     *
     * @return array
     */
    public function getConfig(): array
    {
        $request = Craft::$app->getRequest();

        // Try to get `lft` from the structure elements table if we don't already have it
        if (($lft = $this->lft) === null && $this->id !== null) {
            $lft = (new Query())
                ->select(['lft'])
                ->from(Table::STRUCTUREELEMENTS)
                ->where(['elementId' => $this->id])
                ->scalar();
        }

        return [
            'title' => $this->title,
            'type' => $this->getEntryType()->uid,
            'previewImage' => $this->previewImage,
            'content' => $this->_sanitiseFieldValues(),
            'description' => method_exists($request, 'getBodyParam')
                ? $this->description ?? $request->getBodyParam('description')
                : $this->description,
            'sortOrder' => $lft ? $lft / 2 - 1 : null,
        ];
    }

    /**
     * Sanitises values returned by fields' `serializeValue()` method for project config storage.
     *
     * - Removes  Matrix / Super Table block IDs from field values
     * - Converts SEO field data to an array format
     */
    private function _sanitiseFieldValues(
        ?array $serializedValues = null,
        ?int $fieldLayoutId = null
    ): array {
        if ($serializedValues === null) {
            $serializedValues = $this->getSerializedFieldValues();
        }

        if ($fieldLayoutId === null) {
            $fieldLayoutId = $this->getEntryType()->fieldLayoutId;
        }

        $elementsService = Craft::$app->getElements();
        $fieldsService = Craft::$app->getFields();
        $fields = [];

        if ($fieldLayoutId !== null) {
            foreach ($fieldsService->getLayoutById($fieldLayoutId)?->getCustomFieldElements() ?? [] as $customFieldElement) {
                $field = $customFieldElement->getField();
                $fields[$field->handle] = $field;
            }
        }

        foreach (array_keys($serializedValues) as $fieldHandle) {
            if (!isset($fields[$fieldHandle])) {
                // Assume property rather than field
                continue;
            }

            $field = $fields[$fieldHandle];
            switch (get_class($field)) {
                case MatrixField::class:
                case SuperTableField::class:
                    $serializedValues[$fieldHandle] = $this->_sanitiseBlockElementFieldValue(
                        $serializedValues[$fieldHandle],
                        $field,
                    );
                    break;
                case SeoField::class:
                    $serializedValues[$fieldHandle] = $this->_sanitiseSeoFieldValue($serializedValues[$fieldHandle]);
            };
        }

        return $serializedValues;
    }

    /**
     * Removes Matrix / Super Table block IDs from field values.
     */
    private function _sanitiseBlockElementFieldValue(array $fieldValue, FieldInterface $field): array
    {
        $fieldClass = get_class($field);
        $i = 1;

        foreach ($fieldValue as $oldBlockId => $blockValue) {
            $blockLayoutId = match ($fieldClass) {
                MatrixField::class => (new Query())
                    ->select(['fieldLayoutId'])
                    ->from(Table::MATRIXBLOCKTYPES)
                    ->where([
                        'fieldId' => $field->id,
                        'handle' => $blockValue['type'],
                    ])
                    ->scalar(),
                SuperTableField::class => SuperTable::$plugin->getService()
                    ->getBlockTypeById($blockValue['type'])
                    ->fieldLayoutId,
            };
            $blockValue['fields'] = $this->_sanitiseFieldValues(
                $blockValue['fields'],
                $blockLayoutId,
            );
            $fieldValue['new' . $i++] = $blockValue;
            unset($fieldValue[$oldBlockId]);
        }

        return $fieldValue;
    }

    /**
     * Converts SEO field data to an array format.
     */
    private function _sanitiseSeoFieldValue(mixed $fieldValue): array
    {
        $socialValue = [];

        foreach ($fieldValue->social as $name => $data) {
            $socialValue[$name] = [
                'image' => $data->imageId,
                'title' => $data->title,
                'description' => (string)$data->description,
            ];
        }

        return [
            'titleRaw' => $fieldValue->titleRaw,
            'description' => $fieldValue->descriptionRaw,
            'keywords' => $fieldValue->keywords,
            'score' => $fieldValue->score,
            'social' => $socialValue,
            'advanced' => $fieldValue->advanced,
        ];
    }

    public function getSection(): ?Section
    {
        if ($this->_section === null && $this->typeId !== null) {
            $this->_section = Craft::$app->getSections()->getSectionById($this->getEntryType()->sectionId);
        }

        return $this->_section;
    }

    public function getEntryType(): ?EntryType
    {
        if ($this->_entryType === null && $this->typeId !== null) {
            $this->_entryType = Craft::$app->getSections()->getEntryTypeById($this->typeId);
            // Set the section while we're here
            $this->getSection();
        }

        return $this->_entryType;
    }

    /**
     * @inheritdoc
     */
    public function canView(User $user): bool
    {
        return $this->_can('view', $user);
    }

    /**
     * @inheritdoc
     */
    public function canSave(User $user): bool
    {
        return $this->_can('save', $user);
    }

    /**
     * @inheritdoc
     */
    public function canDelete(User $user): bool
    {
        // Fall back to the save permission for single sections, which would otherwise always return false
        return $this->getSection()->type !== Section::TYPE_SINGLE
            ? $this->_can('delete', $user)
            : $this->_can('save', $user);
    }

    /**
     * Common code for checking user permissions.
     *
     * @param string $action
     * @param User $user
     * @return bool Whether $user can do $action
     */
    private function _can(string $action, User $user): bool
    {
        $can = 'can' . ucfirst($action);
        return parent::{$can}($user) ? true : $this->_mockEntryForPermissionChecks()->{$can}($user);
    }

    /**
     * Creates an entry with this content template's entry type, for checking user permissions.
     *
     * @return Entry
     */
    private function _mockEntryForPermissionChecks(): Entry
    {
        $entryType = $this->getEntryType();
        $mockEntry = new Entry();
        $mockEntry->id = -1;
        $mockEntry->sectionId = $entryType->sectionId;
        $mockEntry->setTypeId($entryType->id);

        return $mockEntry;
    }

    /**
     * @inheritdoc
     */
    public function getFieldLayout(): ?FieldLayout
    {
        if (($fieldLayout = parent::getFieldLayout()) !== null) {
            return $this->_fieldLayoutWithoutEntryTitleField($fieldLayout);
        }
        try {
            $entryType = $this->getEntryType();
        } catch (InvalidConfigException) {
            // The entry type was probably deleted
            return null;
        }

        return $entryType !== null
            ? $this->_fieldLayoutWithoutEntryTitleField($entryType->getFieldLayout())
            : null;
    }

    /**
     * Hacky stuff to remove the EntryTitleField
     */
    private function _fieldLayoutWithoutEntryTitleField(FieldLayout $fieldLayout): FieldLayout
    {
        foreach ($fieldLayout->getTabs() as $tab) {
            $tab->setElements(array_filter($tab->getElements(), fn($element) => !$element instanceof EntryTitleField));
        }

        return $fieldLayout;
    }

    /**
     * @inheritdoc
     */
    public function canCreateDrafts(User $user): bool
    {
        // Everyone with view permissions can create drafts
        return true;
    }

    /**
     * @inheritdoc
     */
    protected function cpEditUrl(): ?string
    {
        $entryType = $this->getEntryType();
        $section = $entryType->getSection();
        $path = sprintf('entrytemplates/%s/%s/%s', $section->handle, $entryType->handle, $this->getCanonicalId());

        return UrlHelper::cpUrl($path);
    }

    /**
     * @inheritdoc
     */
    public function getPostEditUrl(): ?string
    {
        return UrlHelper::cpUrl('entrytemplates');
    }

    /**
     * @inheritdoc
     */
    public function metaFieldsHtml(bool $static): string
    {
        $fields = [];

        // Title Field
        $fields[] = Cp::textFieldHtml([
            'label' => Craft::t('entrytemplates', 'Template Title'),
            'id' => 'title',
            'name' => 'title',
            'autocorrect' => false,
            'autocapitalize' => false,
            'value' => $this->title,
            'disabled' => $static,
            'errors' => $this->getErrors('title'),
        ]);

        // Get the preview image URLs for the add/replace menu, and also to store the valid URL for the set image
        $previewSource = App::parseEnv(EntryTemplates::$plugin->getSettings()->previewSource);
        $previewImagePaths = FileHelper::findFiles($previewSource, [
            'only' => [
                '*.jpeg',
                '*.jpg',
                '*.png',
                '*.svg',
            ],
            'recursive' => false,
        ]);
        $previewImageUrls = [];
        $setPreviewImageUrl = null;

        foreach ($previewImagePaths as $path) {
            $relativePath = substr($path, strlen($previewSource) + 1);
            $previewImageUrl = EntryTemplates::$plugin->previewImages->getPreviewImageUrl(
                substr($path, strlen($previewSource) + 1),
                [
                    'width' => 70,
                    'height' => 70,
                ]
            );
            $previewImageUrls[$relativePath] = $previewImageUrl;

            if ($this->previewImage !== null && $this->previewImage === $relativePath) {
                $setPreviewImageUrl = $previewImageUrl;
            }
        }

        if (!$static) {
            Craft::$app->getView()->registerAssetBundle(PreviewImageAsset::class);
        }

        $fields[] =  Cp::fieldHtml('template:entrytemplates/_preview-image', [
            'label' => Craft::t('entrytemplates', 'Preview Image'),
            'id' => 'previewImage',
            'name' => 'previewImage',
            // If $setPreviewImageUrl is still null, the current previewImage is invalid
            'value' => $setPreviewImageUrl !== null ? $this->previewImage : null,
            'initialPreviewImageUrl' => $setPreviewImageUrl,
            'previewImageUrls' => $previewImageUrls,
            'disabled' => $static,
            'errors' => $this->getErrors('previewImage'),
        ]);

        $fields[] = parent::metaFieldsHtml($static);

        return implode("\n", $fields);
    }
}

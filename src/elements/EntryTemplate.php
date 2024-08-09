<?php
namespace towardstudio\entrytemplates\elements;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\elements\User;
use craft\fieldlayoutelements\entries\EntryTitleField;
use craft\fields\Matrix as MatrixField;
use craft\helpers\App;
use craft\helpers\Cp;
use craft\helpers\Db;
use craft\helpers\UrlHelper;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\Section;
use ether\seo\fields\SeoField;

use towardstudio\entrytemplates\EntryTemplates;
use towardstudio\entrytemplates\elements\db\EntrySectionQuery;
use towardstudio\entrytemplates\elements\db\EntryTemplateQuery;
use towardstudio\entrytemplates\records\EntryTemplate as EntryTemplateRecord;

use towardstudio\entrytemplates\assetbundles\PreviewImageAsset;
use verbb\supertable\fields\SuperTableField;
use verbb\supertable\SuperTable;
use yii\db\Expression;
use yii\web\Response;

class EntryTemplate extends Element
{
    /**
     * @var ?int The ID of the entry type this entry template is for.
     */
    public ?int $typeId = null;

    /**
     * @var int|null The entry template's preview image ID.
     */
    public mixed $previewImage = null;

    /**
     * @var ?int The structure ID for this entry template.
     */
    public array|string|null $sectionIds = null;

    /**
     * @var ?string The description for this entry template.
     */
    public ?string $description = null;

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
    public static function search(): EntrySectionQuery
    {
        return new EntrySectionQuery(static::class);
    }

    /**
     * @inheritdoc
     */
    protected static function defineSources(string $context): array
    {
        $entryTypes = EntryTemplates::$plugin->typeService->getAll();
        $sources = [];

        foreach ($entryTypes['entryTypes'] as $entryType) {
            $source = [
                'key' => 'entryType:' . $entryType->uid,
                'label' => Craft::t('site', $entryType->name),
                'sites' => Craft::$app->sites->getEditableSiteIds(), // Update
                'data' => [
                    'handle' => $entryType->handle,
                ],
                'criteria' => [
                    'typeId' => $entryType->id,
                    'sectionIds' => serialize($entryType->sections),
                ],
            ];
            $source['defaultSort'] = ['postDate', 'desc'];
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
    protected static function defineFieldLayouts(?string $source): array
    {
        if ($source !== null) {
            if ($source === '*') {
                $sections = Craft::$app->getEntries()->getAllSections();
            } elseif ($source === 'singles') {
                $sections = Craft::$app->getEntries()->getSectionsByType(Section::TYPE_SINGLE);
            } else {
                $sections = [];
                if (preg_match('/^section:(.+)$/', $source, $matches)) {
                    $section = Craft::$app->getEntries()->getSectionByUid($matches[1]);
                    if ($section) {
                        $sections[] = $section;
                    }
                }
            }

            $entryTypes = array_values(array_unique(array_merge(
                ...array_map(fn(Section $section) => $section->getEntryTypes(), $sections),
            )));
        } else {
            // get all entry types, including those which may only be used by Matrix fields
            $entryTypes = Craft::$app->getEntries()->getAllEntryTypes();
        }

        return array_map(fn(EntryType $entryType) => $entryType->getFieldLayout(), $entryTypes);
    }

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['typeId'], 'number', 'integerOnly' => true];

        return $rules;
    }

    /**
     * @inheritdoc
     */
    public function prepareEditScreen(Response $response, string $containerId): void
    {
        $entryType = $this->getEntryType();
        $response->crumbs([
            [
                'label' => self::pluralDisplayName(),
                'url' => UrlHelper::cpUrl('entrytemplates'),
            ],
            [
                'label' => Craft::t('site', '{entryType}', [
                    'entryType' => $entryType->name,
                ]),
                'url' => UrlHelper::cpUrl("entrytemplates/$entryType->handle"),
            ],
        ]);
    }

    /**
     * @inheritdoc
     */
    public function afterSave(bool $isNew): void
    {
        $entryType = $this->getEntryType();

        // Request
        $request = Craft::$app->getRequest();
        $elementId = $request->getBodyParam('elementId');
        $sectionIds = $request->getBodyParam('sections');

        $this->previewImage = $request->getBodyParam('previewImage') ? $request->getBodyParam('previewImage')[0] : null;
        $this->description = $request->getBodyParam('description');

        // Add Data to database
        if (!$this->propagating) {
            if (!$sectionIds && !$isNew) {
                $record = EntryTemplateRecord::findOne($this->id);

                if (!$record) {
                    throw new InvalidConfigException("Invalid entry templates ID: $this->id");
                }

                $record->previewImage = $this->previewImage;
                $record->description = $this->description;

                $record->save(false);
            } else {
                $record = new EntryTemplateRecord();
                $record->id = (int)$this->id;
                $record->typeId = (int)$this->typeId;
                $record->sectionIds = serialize($sectionIds);
                $record->previewImage = $this->previewImage;
                $record->description = $this->description;

                $record->save(false);
            }
        };

        parent::afterSave($isNew);
    }

    /**
     * @inheritdoc
     */
    public function afterDelete(): void
    {
        parent::afterDelete();
    }

    /**
     * Get the description
     *
     * @return string|null
     */
    public function getDescription(): ?string
    {
        $description = (new Query())
            ->select(['description'])
            ->from(['cts' => '{{%towardtemplates}}'])
            ->where(['id' => $this->id])
            ->scalar();

        return $description ?: null;
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

    public function getEntryType(): ?EntryType
    {
        if ($this->_entryType === null && $this->typeId !== null) {
            $this->_entryType = Craft::$app->entries->getEntryTypeById($this->typeId);
        }

        return $this->_entryType;
    }

    /**
     * @inheritdoc
     */
    public function canView(User $user): bool
    {
        $userSession = Craft::$app->getUser();
        return $userSession->checkPermission("entrytemplates:dashboard");
    }

    /**
     * @inheritdoc
     */
    public function canSave(User $user): bool
    {
        $userSession = Craft::$app->getUser();
        return $userSession->checkPermission("entrytemplates:dashboard");
    }

    /**
     * @inheritdoc
     */
    public function canDelete(User $user): bool
    {
        $userSession = Craft::$app->getUser();
        return $userSession->checkPermission("entrytemplates:dashboard");
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
        return false;
    }

    /**
     * @inheritdoc
     */
    protected function cpEditUrl(): ?string
    {
        $entryType = $this->getEntryType();
        $path = sprintf('entrytemplates/%s/%s', $entryType->handle, $this->getCanonicalId());

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

        if (!$static) {
            Craft::$app->getView()->registerAssetBundle(PreviewImageAsset::class);
        }

        // Preview Image
        $asset = $this->previewImage
            ? $asset = Asset::find()->id($this->previewImage)->one() : null;

        $fields[] =  Cp::fieldHtml('template:entrytemplates/_preview-image', [
            'label' => Craft::t('entrytemplates', 'Preview Image'),
            'id' => 'previewImage',
            'name' => 'previewImage',
            'value' => $asset,
            'errors' => $this->getErrors('previewImage'),
        ]);

        // Description
        $fields[] = Cp::textareaFieldHtml([
            'label' => Craft::t('entrytemplates', 'Description'),
            'id' => 'description',
            'name' => 'description',
            'value' => $this->description,
            'disabled' => $static,
            'errors' => $this->getErrors('description'),
            'inputAttributes' => [
                'aria' => [
                    'label' => Craft::t('entrytemplates', 'The description to use for this entry template.'),
                ],
            ],
        ]);

        $fields[] = parent::metaFieldsHtml($static);

        return implode("\n", $fields);
    }
}

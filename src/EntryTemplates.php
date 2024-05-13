<?php
namespace towardstudio\entrytemplates;

/* Craft */
use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin;
use craft\controllers\ElementsController;
use craft\elements\Entry;
use craft\events\DefineElementEditorHtmlEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\helpers\Json;
use craft\services\Elements;
use craft\services\ProjectConfig;
use craft\web\View;
use craft\web\UrlManager;

/* Plugin */
use towardstudio\entrytemplates\assetbundles\ModalAsset;
use towardstudio\entrytemplates\elements\EntryTemplate as EntryTemplateElements;
use towardstudio\entrytemplates\models\Settings as SettingsModel;
use towardstudio\entrytemplates\services\PreviewImages;
use towardstudio\entrytemplates\services\ProjectConfig as TowardProjectConfig;


/* Yii */
use yii\base\Event;

/* Logging */
use craft\log\MonologTarget;
use Monolog\Formatter\LineFormatter;
use Psr\Log\LogLevel;

/**
 * @author    Toward Studio
 * @package   EntryTemplates
 * @since     1.0.0
 *
 */
class EntryTemplates extends Plugin
{
    // Public Methods
	// =========================================================================

	public static ?EntryTemplates $plugin;
    public bool $hasCpSection = true;
	public bool $hasCpSettings = true;
	public static ?SettingsModel $settings;

	/**
	 * @inheritdoc
	 */
	public function init()
	{
		parent::init();
		self::$plugin = $this;
        self::$settings = $this->getSettings();

        // Create Custom Alias
		Craft::setAlias('@entrytemplates', __DIR__);

        // Components
        $this->setComponents([
            "previewImages" => PreviewImages::class,
			"projectConfig" => TowardProjectConfig::class,
		]);

        // Events
        $this->_registerElementTypes();
        $this->_registerCPRules();
        $this->_registerLogger();
        $this->_registerProjectConfigApply();
        $this->_registerProjectConfigRebuild();
        $this->_registerModal();
	}

    // Rename the Control Panel Item & Add Sub Menu
	public function getCpNavItem(): ?array
	{
		// Get the site info
		$handle = Craft::$app->sites->currentSite->handle;
		$url = Craft::$app->sites->currentSite->baseUrl;

		// Set additional information on the nav item
		$item = parent::getCpNavItem();

        // Nav Item
		$item["label"] = "Entry Templates";
		$item["icon"] = "@entrytemplates/icons/nav.svg";

		return $item;
	}

    // Private Methods
	// =========================================================================

    /**
     * Registers element types.
     */
    private function _registerElementTypes(): void
    {
        Event::on(
            Elements::class,
            Elements::EVENT_REGISTER_ELEMENT_TYPES,
            function(RegisterComponentTypesEvent $event) {
                $event->types[] = EntryTemplateElements::class;
            }
        );
    }

    /**
     * Registers control panel rules.
     */
    private function _registerCPRules(): void
    {
        Event::on(
			UrlManager::class,
			UrlManager::EVENT_REGISTER_CP_URL_RULES,
			function (RegisterUrlRulesEvent $event) {
				Craft::debug(
					"UrlManager::EVENT_REGISTER_CP_URL_RULES",
					__METHOD__
				);
				// Register our Control Panel routes
				$event->rules = array_merge(
					$event->rules,
					$this->customAdminCpRoutes()
				);
			}
		);
    }

    /**
     * Registers logger.
     */
    private function _registerLogger(): void
    {
        Craft::getLogger()->dispatcher->targets[] = new MonologTarget([
			'name' => 'entry-templates',
			'categories' => ['entry-templates'],
			'level' => LogLevel::INFO,
			'logContext' => false,
			'allowLineBreaks' => false,
			'formatter' => new LineFormatter(
				format: "%datetime% %message%\n",
				dateFormat: 'Y-m-d H:i:s',
				allowInlineLineBreaks: true
			),
		]);
    }

    /**
     * Listens for content template updates in the project config to apply them to the database.
     */
    private function _registerProjectConfigApply(): void
    {
        Craft::$app->getProjectConfig()
            ->onUpdate('entryTemplates.orders.{uid}', [$this->projectConfig, 'handleChangedContentTemplateOrder'])
            ->onAdd('entryTemplates.templates.{uid}', [$this->projectConfig, 'handleChangedContentTemplate'])
            ->onUpdate('entryTemplates.templates.{uid}', [$this->projectConfig, 'handleChangedContentTemplate'])
            ->onRemove('entryTemplates.templates.{uid}', [$this->projectConfig, 'handleDeletedContentTemplate']);
    }

    /**
     * Registers an event listener for a project config rebuild, and provides content template data from the database.
     */
    private function _registerProjectConfigRebuild(): void
    {
        Event::on(ProjectConfig::class, ProjectConfig::EVENT_REBUILD, function(RebuildConfigEvent $event) {
            $entryTemplateConfig = [];
            $entryTemplateOrdersConfig = [];

            foreach (EntryTemplateElements::find()->withStructure(true)->all() as $entryTemplate) {
                $config = $entryTemplate->getConfig();
                $entryTemplateConfig[$entryTemplate->uid] = $config;
                $entryTemplateOrdersConfig[$config['type']][$config['sortOrder']] = $entryTemplate->uid;
            }

            foreach ($entryTemplateOrdersConfig as $typeUid => $templateUids) {
                $entryTemplateOrdersConfig[$typeUid] = array_values($templateUids);
            }

            $event->config['entryTemplates'] = [
                'templates' => $entryTemplateConfig,
                'orders' => $entryTemplateOrdersConfig,
            ];
        });
    }

    /**
     * Listens for element editor content generation, and registers the content template selection modal if the element
     * is an entry with no existing custom field content.
     */
    private function _registerModal(): void
    {
        Event::on(
            ElementsController::class,
            ElementsController::EVENT_DEFINE_EDITOR_CONTENT,
            function(DefineElementEditorHtmlEvent $event) {
                $element = $event->element;

                // We only support entries
                if (!$element instanceof Entry) {
                    return;
                }

                // Register the modal for new drafts only
                if (
                    $element->draftId === null ||
                    $element->canonicalId !== $element->id ||
                    $element->dateCreated != $element->dateUpdated
                ) {
                    return;
                }

                $entryTemplates = EntryTemplateElements::find()
                    ->typeId($element->typeId)
                    ->collect();

                if (!$entryTemplates->isEmpty()) {
                    $modalSettings = [
                        'elementId' => $element->id,
                        'entryTemplates' => $entryTemplates->map(fn($entryTemplate) => [
                            'id' => $entryTemplate->id,
                            'title' => $entryTemplate->title,
                            'preview' => $entryTemplate->getPreviewImageUrl([
                                'width' => 232,
                                'height' => 232,
                            ]),
                        ])->all(),
                    ];
                    $encodedModalSettings = Json::encode($modalSettings, JSON_UNESCAPED_UNICODE);
                    $view = Craft::$app->getView();
                    $view->registerAssetBundle(ModalAsset::class);
                    $view->registerJs("new Craft.EntryTemplates.Modal($encodedModalSettings)");
                }
            }
        );
    }


    // Protected Methods
	// =========================================================================

    /**
     * Return the custom Control Panel routes
     *
     * @return array
     */
    protected function customAdminCpRoutes(): array
    {
        return [
            "entrytemplates" => "entrytemplates/templates/index",
            "entrytemplates/templates" => "entrytemplates/templates/index",
            "entrytemplates/<sectionHandle:{handle}>" => "entrytemplates/templates/index",
            "entrytemplates/<sectionHandle:{handle}>/<entryTypeHandle:{handle}>" => "entrytemplates/templates/index",
            "entrytemplates/<sectionHandle:{handle}>/<entryTypeHandle:{handle}>/<elementId:\d+>" => "elements/edit",
        ];
    }

    /**
     * @inheritdoc
     */
    protected function createSettingsModel(): ?Model
    {
        return new SettingsModel();
    }

    /**
     * @inheritdoc
     */
    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('entrytemplates/plugin-settings', [
            'settings' => $this->getSettings(),
        ]);
    }
}

<?php
namespace towardstudio\entrytemplates;

/* Craft */
use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin;
use craft\elements\Entry;
use craft\events\DefineHtmlEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\events\SectionEvent;
use craft\helpers\Json;
use craft\services\Elements;
use craft\services\Entries;
use craft\services\ProjectConfig;
use craft\services\UserPermissions;
use craft\web\View;
use craft\web\UrlManager;

/* Plugin */
use towardstudio\entrytemplates\assetbundles\ModalAsset;
use towardstudio\entrytemplates\elements\EntryTemplate as EntryTemplateElements;
use towardstudio\entrytemplates\services\PreviewImages;
use towardstudio\entrytemplates\services\EntryTypes as EntryTypesService;

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

	/**
	 * @inheritdoc
	 */
	public function init()
	{
		parent::init();
		self::$plugin = $this;

		// Create Custom Alias
		Craft::setAlias("@entrytemplates", __DIR__);

		// Components
		$this->setComponents([
			"previewImages" => PreviewImages::class,
			"typeService" => EntryTypesService::class,
		]);

		// Events
		$this->_registerElementTypes();
		$this->_registerCPRules();
		$this->_registerLogger();
		$this->_registerSidebar();
		$this->_registerPermissions();

        // Check for Project Config changes
        $projectConfig = Craft::$app->getProjectConfig()
            ->onUpdate(ProjectConfig::PATH_SECTIONS . '.{uid}', function (Event $e) {
                self::$plugin->typeService->handleUpdatedSectionTypes($e);
            });
	}

	// Rename the Control Panel Item & Add Sub Menu
	public function getCpNavItem(): ?array
	{
		// Get current user
		$currentUser = Craft::$app->getUser()->getIdentity();

		// Get the site info
		$handle = Craft::$app->sites->currentSite->handle;
		$url = Craft::$app->sites->currentSite->baseUrl;

		if ($currentUser->can("entrytemplates:dashboard")) {
			// Set additional information on the nav item
			$item = parent::getCpNavItem();

			// Nav Item
			$item["label"] = "Entry Templates";
			$item["icon"] = "@entrytemplates/icons/nav.svg";

			return $item;
		} else {
			return null;
		}
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
			function (RegisterComponentTypesEvent $event) {
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
			"name" => "entry-templates",
			"categories" => ["entry-templates"],
			"level" => LogLevel::INFO,
			"logContext" => false,
			"allowLineBreaks" => false,
			"formatter" => new LineFormatter(
				format: "%datetime% %message%\n",
				dateFormat: "Y-m-d H:i:s",
				allowInlineLineBreaks: true
			),
		]);
	}

	/**
	 * Registers sidebar meta box
	 */
	private function _registerSidebar(): void
	{
		Event::on(Element::class, Element::EVENT_DEFINE_SIDEBAR_HTML, function (
			DefineHtmlEvent $event
		) {
			/** @var Element $element */
			$element = $event->sender;

			// We only support entries
			if (!$element instanceof Entry) {
				return;
			}

			// If the Entry is connected to a section
			if ($element->section) {
				$entryTemplates = EntryTemplateElements::search()
					->sectionIds($element->section->id)
					->collect();

				if (!$entryTemplates->isEmpty()) {
					$html = "";
					// Register Modal JS
					$modalSettings = [
						"elementId" => $element->id,
						"entryTemplates" => $entryTemplates
							->map(
								fn($entryTemplate) => [
									"id" => $entryTemplate->id,
									"title" => $entryTemplate->title,
									"preview" => $entryTemplate->getPreviewImageUrl(
										[
											"width" => 400,
											"height" => 400,
										]
									),
									"description" => $entryTemplate->getDescription(),
								]
							)
							->all(),
					];
					$encodedModalSettings = Json::encode(
						$modalSettings,
						JSON_UNESCAPED_UNICODE
					);
					$view = Craft::$app->getView();
					$view->registerAssetBundle(ModalAsset::class);
					$view->registerJs(
						"new Craft.EntryTemplates.Modal($encodedModalSettings)"
					);

					$html .= Craft::$app->view->renderTemplate(
						"entrytemplates/_sidebar/entryTemplateSelect"
					);
					$html .= $event->html;
					$event->html = $html;
				}
			}
		});
	}

	/**
	 * Registers permissions
	 */
	private function _registerPermissions(): void
	{
		Event::on(
			UserPermissions::class,
			UserPermissions::EVENT_REGISTER_PERMISSIONS,
			function (RegisterUserPermissionsEvent $event) {
				Craft::debug(
					"UserPermissions::EVENT_REGISTER_PERMISSIONS",
					__METHOD__
				);
				// Register our custom permissions
				$event->permissions[] = [
					"heading" => "Entry Templates",
					"permissions" => $this->customAdminCpPermissions(),
				];
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
			"entrytemplates/<entryTypeHandle:{handle}>" =>
				"entrytemplates/templates/index",
			"entrytemplates/<entryTypeHandle:{handle}>/<elementId:\d+>" =>
				"elements/edit",
		];
	}

	/**
	 * @inheritdoc
	 */
	protected function settingsHtml(): ?string
	{
		return Craft::$app
			->getView()
			->renderTemplate("entrytemplates/plugin-settings", [
				"settings" => $this->getSettings(),
			]);
	}

	/**
	 * Returns the custom Control Panel user permissions.
	 *
	 * @return array
	 * @noinspection PhpArrayShapeAttributeCanBeAddedInspection
	 */
	protected function customAdminCpPermissions(): array
	{
		// The script meta containers for the global meta bundle
		try {
			$currentSiteId = Craft::$app->getSites()->getCurrentSite()->id ?? 1;
		} catch (SiteNotFoundException $e) {
			$currentSiteId = 1;
			Craft::error($e->getMessage(), __METHOD__);
		}

		return [
			"entrytemplates:dashboard" => [
				"label" => "View Templates",
			],
		];
	}
}

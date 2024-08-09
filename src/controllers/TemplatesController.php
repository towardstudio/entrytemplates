<?php
namespace towardstudio\entrytemplates\controllers;

use towardstudio\entrytemplates\EntryTemplates;

// Craft
use Craft;
use craft\base\Element;
use craft\helpers\ArrayHelper;
use craft\helpers\UrlHelper;
use craft\web\Controller;

// Illuminate
use Illuminate\Support\Collection;

// Plugin
use towardstudio\entrytemplates\assetbundles\TemplateAsset;
use towardstudio\entrytemplates\elements\EntryTemplate as EntryTemplateElements;

// Yii
use yii\base\InvalidConfigException;
use yii\web\NotFoundHttpException;
use yii\web\BadRequestHttpException;
use yii\web\Response;

class TemplatesController extends Controller
{
	// Protected Properties
	// =========================================================================

	protected array|bool|int $allowAnonymous = [];

	// Public Methods
	// =========================================================================

	/**
     * Index.
     *
     * @return Response The rendering result
     */
    public function actionIndex(): Response
    {
        return $this->renderTemplate('entrytemplates/_index.twig', [
            'settings' => EntryTemplates::$plugin->typeService->getAll(),
        ]);
    }

    /**
     * Entry Types.
     *
     * @return Response The rendering result
     */
    public function actionTypes()
    {
        return json_encode(EntryTemplates::$plugin->typeService->getAll());
    }

    /**
     * Create Template.
     *
     * @return Response The rendering result
     */
    public function actionCreate()
    {
        $data = Craft::$app->getRequest()->getBodyParams();

        // Data
        $siteId = $data['siteId'];
        $typeHandle = $data['entryType'];
        $sectionIds = $data['sections'];

        // Section
        $entryType = Craft::$app->getEntries()->getAllEntryTypes();
        $entryType = ArrayHelper::firstWhere($entryType, 'handle', $typeHandle);

        if (!$entryType) {
            throw new BadRequestHttpException("Invalid entry type Ids: $sectionIds");
        }

        // Site
        $sitesService = Craft::$app->getSites();
        $siteId = $this->request->getBodyParam('siteId');

        if ($siteId) {
            $site = $sitesService->getSiteById($siteId);
            if (!$site) {
                throw new BadRequestHttpException("Invalid site ID: $siteId");
            }
        } else {
            $site = CpHelper::requestedSite();
            if (!$site) {
                throw new ForbiddenHttpException('User not authorized to edit content in any sites.');
            }
        }

        $editableSiteIds = Craft::$app->sites->getEditableSiteIds();

         if (!in_array($site->id, $editableSiteIds)) {
            // If there’s more than one possibility and entries doesn’t propagate to all sites, let the user choose
            if (count($editableSiteIds) > 1 && $entryType->propagationMethod !== Section::PROPAGATION_METHOD_ALL) {
                return $this->renderTemplate('_special/sitepicker.twig', [
                    'siteIds' => $editableSiteIds,
                    'baseUrl' => "entrytemplates/$entryType->handle/new",
                ]);
            }

            // Go with the first one
            $site = $sitesService->getSiteById($editableSiteIds[0]);
        }

        $user = static::currentUser();

        // Create & populate the draft
        $template = Craft::createObject(EntryTemplateElements::class);
        $template->siteId = $site->id;
        $template->typeId = $entryType->id;
        $template->sectionIds = $sectionIds;

        // Status
        if (($status = $this->request->getQueryParam('status')) !== null) {
            $enabled = $status === 'enabled';
        } else {
            $enabled = true;
        }
        if (Craft::$app->getIsMultiSite() && count($template->getSupportedSites()) > 1) {
            $template->enabled = true;
            $template->setEnabledForSite($enabled);
        } else {
            $template->enabled = $enabled;
            $template->setEnabledForSite(true);
        }

        // Title
        $template->title = $this->request->getQueryParam('title');

        // Custom fields
        foreach ($template->getFieldLayout()->getCustomFields() as $field) {
            if (($value = $this->request->getQueryParam($field->handle)) !== null) {
                $template->setFieldValue($field->handle, $value);
            }
        }

        // Save it
        $template->setScenario(Element::SCENARIO_ESSENTIALS);
        $success = Craft::$app->getDrafts()->saveElementAsDraft($template, Craft::$app->getUser()->getId(), null, null, false);

        if (!$success) {
            return $this->asModelFailure($template, Craft::t('entrytemplates', 'Couldn’t create {type}.', [
                'type' => EntryTemplateElements::lowerDisplayName(),
            ]), 'entryTemplates');
        }

        $editUrl = $template->getCpEditUrl();

        $response = $this->asModelSuccess($template, Craft::t('entrytemplates', '{type} created.', [
            'type' => EntryTemplateElements::displayName(),
        ]), 'entryTemplates', array_filter([
            'cpEditUrl' => $this->request->isCpRequest ? $editUrl : null,
        ]));

        if (!$this->request->getAcceptsJson()) {
            $response->redirect(UrlHelper::urlWithParams($editUrl, [
                'fresh' => 1,
            ]));
        }

        return $response;
    }

    /**
     * Applies a content template's content to an entry.
     *
     * @return Response
     */
    public function actionApply(): Response
    {
        $request = Craft::$app->getRequest();
        $elementsService = Craft::$app->getElements();
        $elementId = $request->getRequiredBodyParam('elementId');
        $entryTemplateId = $request->getRequiredBodyParam('entryTemplateId');
        $siteHandle = $request->getQueryParam('site', null);

        // set the current requested site
        if ($siteHandle !== null) {
            $site = Craft::$app->getSites()->getSiteByHandle($siteHandle);
        }

        // query the element based on the current requested site, or the default as fallback
        $element = $elementsService->getElementById($elementId, null, $site->id ?? null);

        // failsafe if we don't find the specified element
        if ($element === null) {
            return $this->asFailure('Failed to find the specified element: ' . $elementId);
        }

        $contentTemplate = $elementsService->getElementById($entryTemplateId);
        $tempDuplicateTemplate = $elementsService->duplicateElement($contentTemplate);
        $element->setFieldValues($tempDuplicateTemplate->getSerializedFieldValues());
        $element->title = $element->title ?? 'Untitled';
        $element->slug = null;
        $element->typeId = $contentTemplate->typeId;

        $success = $elementsService->saveElement($element, !$element->getIsDraft());
        $elementsService->deleteElement($tempDuplicateTemplate);

        if (!$success) {
            return $this->asFailure('Failed to apply content template to ' . $element::lowerDisplayName());
        }

        return $this->asSuccess(data: [
            'redirect' => UrlHelper::urlWithParams($element->getCpEditUrl(), [
                'fresh' => 1,
            ]),
        ]);
    }
}

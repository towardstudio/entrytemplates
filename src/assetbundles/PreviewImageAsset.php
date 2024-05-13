<?php
namespace towardstudio\entrytemplates\assetbundles;

use Craft;
use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

class PreviewImageAsset extends AssetBundle
{

    // Public Methods
    // =========================================================================

    public function init()
    {
        $this->sourcePath = "@towardstudio/entrytemplates/resources";

        // define the dependencies
        $this->depends = [
            CpAsset::class,
        ];

		$this->css = [
            'css/dist/index.min.css',
        ];

        $this->js = [
            'js/dist/preview.min.js',
        ];

        parent::init();
    }

    /**
     * @inheritdoc
     */
    public function registerAssetFiles($view): void
    {
        $view->registerTranslations('entrytemplates', [
            'Add',
            'None set',
            'Remove',
            'Replace',
        ]);

        parent::registerAssetFiles($view);
    }
}

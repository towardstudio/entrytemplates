<?php
namespace towardstudio\entrytemplates\assetbundles;

use Craft;
use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;
use craft\web\assets\vue\VueAsset;

class TemplateAsset extends AssetBundle
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
            'js/index.js',
        ];

        parent::init();
    }
}

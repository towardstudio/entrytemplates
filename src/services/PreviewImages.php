<?php
namespace towardstudio\entrytemplates\services;

use Craft;
use craft\elements\Asset;
use craft\helpers\App;
use craft\helpers\FileHelper;
use towardstudio\entrytemplates\EntryTemplates;
use yii\base\Component;

class PreviewImages extends Component
{
    /**
     * Gets the URL of the preview image with the given filename, if it exists.
     *
     * @param string $filename The image filename, relative to the plugin's `previewSource` setting.
     * @param array|null $transform The width and height to scale/crop the image to.
     * @return string|null
     */
    public function getPreviewImageUrl(string $id, ?array $transform = null): ?string
    {
        $asset = Asset::find()->id($id)->one();

        if ($asset)
        {
            if ($transform !== null) {
                $asset = $asset->setTransform([
                    'width' => $transform['width'],
                    "height" => $transform['height'],
                    "position" => 'center-center'
                ]);
            }

            return $asset->url;
        }

        return null;

    }
}

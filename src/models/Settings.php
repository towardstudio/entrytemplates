<?php
namespace towardstudio\entrytemplates\models;

use towardstudio\entrytemplates\EntryTemplates;

use Craft;
use craft\base\Model;

class Settings extends Model
{
	// Public Properties
	// =========================================================================

	/**
	 * @var string
	 */
    public string $previewSource = '@webroot';

	// Public Methods
	// =========================================================================

	/**
	 * @inheritdoc
	 */
	public function rules(): array
	{
		return [
            [
                'previewSource',
                'required',
            ],
		];
	}
}

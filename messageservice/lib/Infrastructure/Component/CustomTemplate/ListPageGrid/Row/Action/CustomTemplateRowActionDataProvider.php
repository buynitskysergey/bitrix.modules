<?php

declare(strict_types=1);

namespace Bitrix\MessageService\Infrastructure\Component\CustomTemplate\ListPageGrid\Row\Action;

use Bitrix\Main\Grid\Row\Action\Action;
use Bitrix\Main\Grid\Row\Action\DataProvider;
use Bitrix\MessageService\Infrastructure\Component\CustomTemplate\ListPageGrid\Settings\CustomTemplateGridSettings;

/**
 * Row Action data provider for the custom-template grid.
 *
 * @method CustomTemplateGridSettings getSettings()
 */
final class CustomTemplateRowActionDataProvider extends DataProvider
{
	public function __construct(CustomTemplateGridSettings $settings)
	{
		parent::__construct($settings);
	}

	/**
	 * @return Action[]
	 */
	public function prepareActions(): array
	{
		return [
			new EditAction($this->getSettings()),
			new DeleteAction($this->getSettings()),
		];
	}
}

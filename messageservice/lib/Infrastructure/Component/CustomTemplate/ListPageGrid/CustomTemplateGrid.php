<?php

declare(strict_types=1);

namespace Bitrix\MessageService\Infrastructure\Component\CustomTemplate\ListPageGrid;

use Bitrix\Main\Grid\Column\Columns;
use Bitrix\Main\Grid\Grid;
use Bitrix\Main\Grid\Pagination\PaginationFactory;
use Bitrix\Main\Grid\Row\Rows;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\UI\PageNavigation;
use Bitrix\MessageService\Infrastructure\Component\CustomTemplate\ListPageGrid\Column\Provider\CustomTemplateDataProvider;
use Bitrix\MessageService\Infrastructure\Component\CustomTemplate\ListPageGrid\Row\Action\CustomTemplateRowActionDataProvider;
use Bitrix\MessageService\Infrastructure\Component\CustomTemplate\ListPageGrid\Row\Assembler\CustomTemplateRowAssembler;
use Bitrix\MessageService\Infrastructure\Component\CustomTemplate\ListPageGrid\Settings\CustomTemplateGridSettings;
use Bitrix\UI\Buttons\Button;
use Bitrix\UI\Buttons\Color;
use Bitrix\UI\Buttons\Icon;

/**
 * @method CustomTemplateGridSettings getSettings()
 */
final class CustomTemplateGrid extends Grid
{
	protected function createPagination(): ?PageNavigation
	{
		return (new PaginationFactory($this, $this->getPaginationStorage()))->create();
	}

	protected function createColumns(): Columns
	{
		return new Columns(
			new CustomTemplateDataProvider($this->getSettings())
		);
	}

	protected function createRows(): Rows
	{
		return new Rows(
			new CustomTemplateRowAssembler($this->getVisibleColumnsIds(), $this->getSettings()),
			new CustomTemplateRowActionDataProvider($this->getSettings()),
		);
	}

	public function createToolbarCreateButton(): Button
	{
		return new Button([
			'text' => (string)Loc::getMessage('MSGSVC_CT_TOOLBAR_CREATE'),
			'color' => Color::SUCCESS,
			'icon' => Icon::ADD,
			'click' => new \Bitrix\UI\Buttons\JsCode(
				sprintf(
					"BX.Event.EventEmitter.emit('BX.MessageService.CustomTemplate.List:onCreateRequested', { gridId: '%s', zoneId: '%s', sceneId: '%s', targetId: '%s' })",
					\CUtil::JSEscape($this->getId()),
					\CUtil::JSEscape($this->getSettings()->getZone()),
					\CUtil::JSEscape($this->getSettings()->getScene()),
					\CUtil::JSEscape($this->getSettings()->getTargetId()),
				),
			),
		]);
	}
}

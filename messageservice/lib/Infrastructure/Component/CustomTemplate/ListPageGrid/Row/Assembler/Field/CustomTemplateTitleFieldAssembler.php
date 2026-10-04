<?php

declare(strict_types=1);

namespace Bitrix\MessageService\Infrastructure\Component\CustomTemplate\ListPageGrid\Row\Assembler\Field;

use Bitrix\Main\Grid\Row\FieldAssembler;
use Bitrix\Main\Text\HtmlFilter;
use Bitrix\MessageService\Infrastructure\Component\CustomTemplate\ListPageGrid\Row\Action\EditAction;
use Bitrix\MessageService\Infrastructure\Component\CustomTemplate\ListPageGrid\Settings\CustomTemplateGridSettings;

/**
 * Merged "title + preview" cell: the title is a link that triggers the same edit
 * flow as the "edit" row action, the grey body preview stacks below it. The cell
 * classes are styled by the list component template style.css.
 *
 * main.ui.grid echoes the column value as raw HTML, so both user-controlled parts
 * (TITLE, BODY_PREVIEW) are HTML-encoded here; the onclick payload is JS-escaped
 * by {@see EditAction::buildOnclick()} and then attribute-encoded on top.
 */
class CustomTemplateTitleFieldAssembler extends FieldAssembler
{
	public function __construct(
		array $columnIds,
		private readonly CustomTemplateGridSettings $gridSettings,
	)
	{
		parent::__construct($columnIds);
	}

	protected function prepareRow(array $row): array
	{
		$row['columns'] ??= [];

		foreach ($this->getColumnIds() as $columnId)
		{
			$row['columns'][$columnId] = $this->renderCell($row['data'] ?? []);
		}

		return $row;
	}

	private function renderCell(array $data): string
	{
		return '<div class="msgsvc-ct-list-title-cell">'
			. $this->renderTitle($data)
			. $this->renderPreview($data)
			. '</div>';
	}

	private function renderTitle(array $data): string
	{
		$title = HtmlFilter::encode((string)($data['TITLE'] ?? ''));

		$id = (int)($data['ID'] ?? 0);
		if ($id <= 0)
		{
			return '<span class="msgsvc-ct-list-title-cell__title">' . $title . '</span>';
		}

		$onclick = HtmlFilter::encode(
			EditAction::buildOnclick($this->gridSettings, $data) . '; return false;',
		);

		return '<a href="#" class="msgsvc-ct-list-title-cell__title" onclick="' . $onclick
			. '" data-testid="custom-template-list-title">' . $title . '</a>';
	}

	private function renderPreview(array $data): string
	{
		$preview = HtmlFilter::encode((string)($data['BODY_PREVIEW'] ?? ''));
		if ($preview === '')
		{
			return '';
		}

		return '<div class="msgsvc-ct-list-title-cell__preview">' . $preview . '</div>';
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\MessageService\Infrastructure\Component\CustomTemplate\ListPageGrid\Row\Assembler;

use Bitrix\Main\Grid\Row\Assembler\Field\StringFieldAssembler;
use Bitrix\Main\Grid\Row\RowAssembler;
use Bitrix\MessageService\Infrastructure\Component\CustomTemplate\ListPageGrid\Row\Assembler\Field\CustomTemplateTitleFieldAssembler;
use Bitrix\MessageService\Infrastructure\Component\CustomTemplate\ListPageGrid\Row\Assembler\Field\CustomTemplateUserFieldAssembler;
use Bitrix\MessageService\Infrastructure\Component\CustomTemplate\ListPageGrid\Settings\CustomTemplateGridSettings;

final class CustomTemplateRowAssembler extends RowAssembler
{
	public function __construct(
		array $visibleColumnIds,
		private readonly CustomTemplateGridSettings $settings,
	)
	{
		parent::__construct($visibleColumnIds);
	}

	protected function prepareFieldAssemblers(): array
	{
		// Encode user-controlled cell text: main.ui.grid echoes column values as raw HTML
		// (and falls back to raw `data` when a column is unset). Raw `data` is preserved for
		// the row actions (EditAction re-reads TITLE/BODY for the edit payload). The audit dates
		// are already formatted strings; running them through StringFieldAssembler only guarantees
		// a non-empty column value so the grid never falls back to raw data.
		return [
			// TITLE renders the merged "title link + body preview" cell and encodes both
			// user-controlled parts itself (BODY_PREVIEW is raw row data, not a column).
			new CustomTemplateTitleFieldAssembler(['TITLE'], $this->settings),
			new StringFieldAssembler([
				'SCENE_LABEL',
				'CONTEXT_LABEL',
				'DATE_CREATE',
				'DATE_MODIFY',
			]),
			// One instance for both id columns so the user-name lookup cache is shared. The author
			// name is HTML-escaped by the parent (CUser::FormatName); the cell renders trusted HTML
			// (escaped name wrapped in a static profile link), so these columns must NOT also go
			// through StringFieldAssembler — that would double-encode the link tags.
			new CustomTemplateUserFieldAssembler([
				'AUTHOR_ID',
				'MODIFIED_BY',
			]),
		];
	}
}

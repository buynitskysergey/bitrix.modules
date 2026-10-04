<?php

declare(strict_types=1);

namespace Bitrix\MessageService\Infrastructure\Component\CustomTemplate\ListPageGrid\Column\Provider;

use Bitrix\Main\Grid\Column\DataProvider;
use Bitrix\Main\Grid\Column\Type;
use Bitrix\Main\Localization\Loc;

final class CustomTemplateDataProvider extends DataProvider
{
	public function prepareColumns(): array
	{
		return [
			// TITLE renders the merged "title + preview" cell (see CustomTemplateTitleFieldAssembler),
			// so there is no separate preview column: a combined cell is CUSTOM-typed and
			// necessary (cannot be hidden in grid settings), header sorting stays on TITLE.
			$this->createColumn('TITLE')
				->setName(Loc::getMessage('MSGSVC_CT_GRID_COL_TITLE'))
				->setType(Type::CUSTOM)
				->setDefault(true)
				->setNecessary(true)
				->setSort('TITLE'),

			$this->createColumn('SCENE_LABEL')
				->setName(Loc::getMessage('MSGSVC_CT_GRID_COL_SCENE'))
				->setDefault(true),

			$this->createColumn('CONTEXT_LABEL')
				->setName(Loc::getMessage('MSGSVC_CT_GRID_COL_CONTEXT'))
				->setDefault(true),

			$this->createColumn('AUTHOR_ID')
				->setName(Loc::getMessage('MSGSVC_CT_GRID_COL_AUTHOR'))
				->setDefault(false),

			$this->createColumn('MODIFIED_BY')
				->setName(Loc::getMessage('MSGSVC_CT_GRID_COL_MODIFIED_BY'))
				->setDefault(false),

			$this->createColumn('DATE_CREATE')
				->setName(Loc::getMessage('MSGSVC_CT_GRID_COL_DATE_CREATE'))
				->setDefault(false)
				->setSort('DATE_CREATE'),

			$this->createColumn('DATE_MODIFY')
				->setName(Loc::getMessage('MSGSVC_CT_GRID_COL_DATE_MODIFY'))
				->setDefault(false)
				->setSort('DATE_MODIFY'),
		];
	}
}

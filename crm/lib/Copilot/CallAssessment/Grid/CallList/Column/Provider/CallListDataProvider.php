<?php

namespace Bitrix\Crm\Copilot\CallAssessment\Grid\CallList\Column\Provider;

use Bitrix\Main\Grid\Column\DataProvider;
use Bitrix\Main\Grid\Column\Type;
use Bitrix\Main\Localization\Loc;

final class CallListDataProvider extends DataProvider
{
	public function prepareColumns(): array
	{
		Loc::loadMessages(__FILE__);

		return [
			$this->createColumn('CALL_THEME')
				->setType(Type::HTML)
				->setName(Loc::getMessage('CRM_COPILOT_CALL_ASSESSMENT_CALL_LIST_GRID_COLUMN_THEME'))
				->setTitle(Loc::getMessage('CRM_COPILOT_CALL_ASSESSMENT_CALL_LIST_GRID_COLUMN_THEME'))
				->setDefault(true)
			,
			$this->createColumn('MANAGER')
				->setType(Type::HTML)
				->setName(Loc::getMessage('CRM_COPILOT_CALL_ASSESSMENT_CALL_LIST_GRID_COLUMN_MANAGER'))
				->setTitle(Loc::getMessage('CRM_COPILOT_CALL_ASSESSMENT_CALL_LIST_GRID_COLUMN_MANAGER'))
				->setSort('RATED_USER_ID')
				->setDefault(true)
			,
			$this->createColumn('ASSESSMENT')
				->setType(Type::HTML)
				->setName(Loc::getMessage('CRM_COPILOT_CALL_ASSESSMENT_CALL_LIST_GRID_COLUMN_RESULT'))
				->setTitle(Loc::getMessage('CRM_COPILOT_CALL_ASSESSMENT_CALL_LIST_GRID_COLUMN_RESULT'))
				->setSort('ASSESSMENT')
				->setDefault(true)
			,
			$this->createColumn('CLIENT')
				->setType(Type::HTML)
				->setName(Loc::getMessage('CRM_COPILOT_CALL_ASSESSMENT_CALL_LIST_GRID_COLUMN_CLIENT'))
				->setTitle(Loc::getMessage('CRM_COPILOT_CALL_ASSESSMENT_CALL_LIST_GRID_COLUMN_CLIENT'))
				->setDefault(true)
			,
			$this->createColumn('CALL_DATE')
				->setType(Type::HTML)
				->setName(Loc::getMessage('CRM_COPILOT_CALL_ASSESSMENT_CALL_LIST_GRID_COLUMN_DATE'))
				->setTitle(Loc::getMessage('CRM_COPILOT_CALL_ASSESSMENT_CALL_LIST_GRID_COLUMN_DATE'))
				->setSort('ACTIVITY.START_TIME')
				->setDefault(true)
			,
			$this->createColumn('SCRIPT')
				->setType(Type::TEXT)
				->setName(Loc::getMessage('CRM_COPILOT_CALL_ASSESSMENT_CALL_LIST_GRID_COLUMN_SCRIPT'))
				->setTitle(Loc::getMessage('CRM_COPILOT_CALL_ASSESSMENT_CALL_LIST_GRID_COLUMN_SCRIPT'))
				->setDefault(true)
			,
		];
	}
}

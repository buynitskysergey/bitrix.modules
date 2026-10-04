<?php

namespace Bitrix\Crm\Copilot\CallAssessment\Grid\CallList;

use Bitrix\Crm\Copilot\AiQualityAssessment\Entity\AiQualityAssessmentTable;
use Bitrix\Crm\Copilot\CallAssessment\Grid\CallList\Column\Provider\CallListDataProvider;
use Bitrix\Crm\Copilot\CallAssessment\Grid\CallList\Row\Assembler\CallListRowAssembler;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\Grid\Column\Columns;
use Bitrix\Main\Grid\Grid;
use Bitrix\Main\Grid\Pagination\PaginationFactory;
use Bitrix\Main\Grid\Row\Rows;
use Bitrix\Main\Grid\Settings;
use Bitrix\Main\UI\PageNavigation;
use CCrmActivity;

final class CallListGrid extends Grid
{
	public function __construct(
		Settings $settings,
		private readonly array $userFilterConditions = [],
	)
	{
		parent::__construct($settings);
	}

	protected function createColumns(): Columns
	{
		return new Columns(new CallListDataProvider());
	}

	protected function createRows(): Rows
	{
		return new Rows(new CallListRowAssembler($this->getVisibleColumnsIds()));
	}

	protected function createPagination(): ?PageNavigation
	{
		return (new PaginationFactory($this, null))->create();
	}

	protected function getDefaultSorting(): array
	{
		return ['ACTIVITY.START_TIME' => 'DESC'];
	}

	public function getOrmFilter(): ?array
	{
		$filter = parent::getOrmFilter() ?? [];

		foreach ($this->userFilterConditions as $key => $value)
		{
			$filter[$key] = $value;
		}

		$filter['=USE_IN_RATING'] = true;
		$filter['=ACTIVITY_TYPE'] = AiQualityAssessmentTable::ACTIVITY_TYPE_CALL;

		$permSql = CCrmActivity::BuildPermSql('A', 'READ', ['RAW_QUERY' => true]);
		if ($permSql === false)
		{
			// User has no access to any activities; force empty result.
			$filter['=ACTIVITY_ID'] = 0;
		}
		elseif ($permSql !== '')
		{
			$filter['@ACTIVITY_ID'] = new SqlExpression($permSql);
		}

		return $filter;
	}

	public function getOrmSelect(): array
	{
		return [
			'ID',
			'ACTIVITY_ID',
			'ASSESSMENT',
			'ASSESSMENT_SETTING_ID',
			'RATED_USER_ID',
			'ACTIVITY_SUBJECT' => 'ACTIVITY.SUBJECT',
			'ACTIVITY_START' => 'ACTIVITY.START_TIME',
			'OWNER_TYPE_ID' => 'ACTIVITY.OWNER_TYPE_ID',
			'OWNER_ID' => 'ACTIVITY.OWNER_ID',
			'CALL_THEME' => 'SUMMARY.THEME',
		];
	}
}

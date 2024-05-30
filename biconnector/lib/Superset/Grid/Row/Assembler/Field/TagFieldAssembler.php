<?php

namespace Bitrix\BIConnector\Superset\Grid\Row\Assembler\Field;

use Bitrix\BIConnector\LimitManager;
use Bitrix\Main\Grid\Row\FieldAssembler;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Web\Json;

class TagFieldAssembler extends FieldAssembler
{
	protected function prepareColumn($value): array
	{
		$items = [];
		$dashboardId = (int)$value['ID'];
		$tags = $value['TAGS'] ?? [];
		foreach ($tags as $tag)
		{
			$tag = array_intersect_key($tag, array_flip(['ID', 'TITLE', 'IS_FILTERED']));
			$eventTag = Json::encode($tag);
			$isActive = (bool)($tag['IS_FILTERED'] ?? false);
			$item = [
				'text' => $tag['TITLE'],
				'active' => $isActive,
				'events' => [
					'click' => "BX.delegate((event) => BX.BIConnector.SupersetDashboardGridManager.Instance.handleTagClick('$eventTag'))"
				],
			];

			if ($isActive)
			{
				$item['removeButton'] = [
					'events' => [
						'click' => "BX.delegate((event) => BX.BIConnector.SupersetDashboardGridManager.Instance.handleTagClick('$eventTag'))"
					]
				];
			}

			$items[] = $item;
		}

		$ids = Json::encode(array_column($tags, 'ID'));

		return [
			'items' => $items,
			'addButton' => [
				'events' => [
					'click' => "BX.delegate((event) => BX.BIConnector.SupersetDashboardGridManager.Instance.handleTagAddClick({$dashboardId}, '{$ids}', event))"
				]
			],
		];
	}

	protected function prepareRow(array $row): array
	{
		if (empty($this->getColumnIds()))
		{
			return $row;
		}

		$row['columns'] ??= [];
		foreach ($this->getColumnIds() as $columnId)
		{
			$row['columns'][$columnId] = $this->prepareColumn($row['data']);
		}

		return $row;
	}
}

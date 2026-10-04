<?php

namespace Bitrix\Crm\Copilot\CallAssessment\Grid\CallList\Row\Assembler\Field;

use Bitrix\Crm\Service\Container;
use Bitrix\Main\Grid\Row\FieldAssembler;
use CCrmOwnerType;

final class ClientFieldAssembler extends FieldAssembler
{
	protected function prepareRow(array $row): array
	{
		$row['columns'] ??= [];
		$ownerTypeId = (int)($row['data']['OWNER_TYPE_ID'] ?? 0);
		$ownerId     = (int)($row['data']['OWNER_ID'] ?? 0);

		foreach ($this->getColumnIds() as $columnId)
		{
			$row['columns'][$columnId] = $this->renderClient($ownerTypeId, $ownerId);
		}

		return $row;
	}

	private function renderClient(int $ownerTypeId, int $ownerId): string
	{
		if ($ownerTypeId <= 0 || $ownerId <= 0)
		{
			return '';
		}

		$name = (string)CCrmOwnerType::GetCaption($ownerTypeId, $ownerId, false);
		if ($name === '')
		{
			return '';
		}

		$url = (string)Container::getInstance()->getRouter()->getItemDetailUrl($ownerTypeId, $ownerId);
		if ($url === '')
		{
			return htmlspecialcharsbx($name);
		}

		return sprintf(
			'<a href="%s">%s</a>',
			htmlspecialcharsbx($url),
			htmlspecialcharsbx($name),
		);
	}
}

<?php

namespace Bitrix\Crm\Copilot\CallAssessment\Grid\CallList\Row\Assembler\Field;

use Bitrix\Crm\Service\Container;
use Bitrix\Main\Grid\Row\FieldAssembler;
use CCrmViewHelper;

final class ManagerFieldAssembler extends FieldAssembler
{
	private array $users = [];

	public function prepareRows(array $rowList): array
	{
		$userIds = [];
		foreach ($rowList as $row)
		{
			$userId = (int)($row['data']['RATED_USER_ID'] ?? 0);
			if ($userId > 0)
			{
				$userIds[$userId] = $userId;
			}
		}

		$this->users = empty($userIds)
			? []
			: Container::getInstance()->getUserBroker()->getBunchByIds(array_values($userIds))
		;

		return parent::prepareRows($rowList);
	}

	protected function prepareRow(array $row): array
	{
		$row['columns'] ??= [];
		$userId = (int)($row['data']['RATED_USER_ID'] ?? 0);

		foreach ($this->getColumnIds() as $columnId)
		{
			$row['columns'][$columnId] = $this->renderManager($userId);
		}

		return $row;
	}

	private function renderManager(int $userId): string
	{
		if ($userId <= 0 || empty($this->users[$userId]))
		{
			return '';
		}

		$info = $this->users[$userId];

		return CCrmViewHelper::PrepareUserBaloonHtml([
			'PREFIX' => 'COPILOT_CALL_LIST',
			'USER_ID' => $userId,
			'USER_NAME' => (string)($info['FORMATTED_NAME'] ?? ''),
			'USER_PROFILE_URL' => (string)($info['SHOW_URL'] ?? ''),
			'ENCODE_USER_NAME' => true,
		]);
	}
}

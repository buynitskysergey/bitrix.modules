<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\Dictionary;

use Bitrix\Crm\PhaseSemantics;
use Bitrix\Crm\V2\Public\Entity\Item\StageSemantic;
use Bitrix\Main\Localization\Loc;

class StageSemanticRepository
{
	public function getById(string $id, string $languageId): ?StageSemantic
	{
		foreach ($this->getList($languageId) as $semantic)
		{
			if ($semantic->getId() === $id)
			{
				return $semantic;
			}
		}

		return null;
	}

	/**
	 * @return list<StageSemantic>
	 */
	public function getList(string $languageId): array
	{
		$previousLanguageId = Loc::getCurrentLang();

		try
		{
			Loc::setCurrentLang($languageId);
			$items = PhaseSemantics::getListFilterInfo(\CCrmOwnerType::Deal, [], true)['items'] ?? [];
		}
		finally
		{
			Loc::setCurrentLang($previousLanguageId);
		}

		$result = [];
		foreach ([PhaseSemantics::PROCESS, PhaseSemantics::SUCCESS, PhaseSemantics::FAILURE] as $id)
		{
			$result[] = new StageSemantic($id, isset($items[$id]) ? (string)$items[$id] : null);
		}

		return $result;
	}
}

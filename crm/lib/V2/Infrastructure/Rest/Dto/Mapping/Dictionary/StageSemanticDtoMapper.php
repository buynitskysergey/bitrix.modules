<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Dictionary;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Dictionary\StageSemanticDto;
use Bitrix\Crm\V2\Public\Entity\Item\StageSemantic;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Dto\Mapping\Mapper;

final class StageSemanticDtoMapper extends Mapper
{
	/**
	 * @param list<StageSemantic> $items
	 */
	public function mapCollection(array $items, array $fields = []): DtoCollection
	{
		$collection = new DtoCollection(StageSemanticDto::class);
		foreach ($items as $semantic)
		{
			$dto = new StageSemanticDto();
			if ($fields === [] || in_array('id', $fields, true))
			{
				$dto->id = $semantic->getId();
			}
			if ($fields === [] || in_array('name', $fields, true))
			{
				$dto->name = (string)$semantic->getName();
			}

			$collection->add($dto);
		}

		return $collection;
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Requisite;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Requisite\RequisiteDto;
use Bitrix\Crm\V2\Internal\Entity\Requisite\Requisite;
use Bitrix\Rest\V3\Dto\DtoCollection;

/**
 * Builds {@see RequisiteDto} out of the internal {@see Requisite}. One direction only - the method behind
 * this contract reads, and there is no write path to map back into.
 *
 * A record becomes an answer by name rather than through a list of seventy-four assignments: the field
 * names of a requisite are its property names by contract, the same identity the read itself is built on,
 * and a list written out here would be a third one to keep in step with it.
 *
 * `select` is honoured twice over: the read loads the asked-for fields and nothing besides
 * ({@see RequisiteListRequestMapper::mapSelect()} resolves the list), and a property the client did not ask
 * for is never filled here and therefore stays out of the response entirely.
 *
 * {@see Requisite} is an internal type and is used here as a transitional exception: the scenario behind
 * this method is a single read of the requisites of the portal, and a public contract of the domain would be
 * a refactoring out of all proportion to it. The dependency stays inside REST and carries no compatibility
 * promise.
 */
final class RequisiteDtoMapper
{
	/**
	 * @param Requisite[] $requisites
	 * @param string[] $selectedFieldNames {@see RequisiteListRequestMapper::mapSelect()}
	 */
	public function getDtoCollectionByRequisites(array $requisites, array $selectedFieldNames): DtoCollection
	{
		$collection = new DtoCollection(RequisiteDto::class);
		foreach ($requisites as $requisite)
		{
			$collection->add(self::createDtoByRequisite($requisite, $selectedFieldNames));
		}

		return $collection;
	}

	/**
	 * @param string[] $selectedFieldNames
	 */
	private static function createDtoByRequisite(Requisite $requisite, array $selectedFieldNames): RequisiteDto
	{
		/** @var RequisiteDto $dto */
		$dto = RequisiteDto::create();
		$values = get_object_vars($requisite);
		foreach ($selectedFieldNames as $dtoFieldName)
		{
			if (!isset($dto->getFields()[$dtoFieldName]))
			{
				continue;
			}

			// Writes into the field rather than into the property, so an unselected property stays
			// uninitialized and drops out of the response.
			$dto->__set($dtoFieldName, $values[$dtoFieldName] ?? null);
		}

		return $dto;
	}
}

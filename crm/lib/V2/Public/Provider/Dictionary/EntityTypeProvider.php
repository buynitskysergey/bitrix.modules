<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Provider\Dictionary;

use Bitrix\Crm\V2\Internal\Repository\Dictionary\EntityTypeTitleProvider;
use Bitrix\Crm\V2\Public;
use Bitrix\Crm\V2\Public\Entity\Dictionary;

final class EntityTypeProvider
{
	private EntityTypeTitleProvider $titleProvider;

	public function __construct()
	{
		$this->titleProvider = new EntityTypeTitleProvider();
	}

	public function getById(int $id, string $responseLanguage): ?Dictionary\EntityType
	{
		$requestedType = Public\EntityType::fromId($id);

		foreach ($this->getSupportedTypes() as $supportedType)
		{
			if ($supportedType->equals($requestedType))
			{
				return $this->createDictionaryItem($supportedType, $responseLanguage);
			}
		}

		return null;
	}

	/**
	 * @return Dictionary\EntityType[]
	 */
	public function getList(string $responseLanguage): array
	{
		$items = array_map(
			fn(Public\EntityType $entityType): Dictionary\EntityType => $this->createDictionaryItem(
				$entityType,
				$responseLanguage,
			),
			$this->getSupportedTypes(),
		);

		usort(
			$items,
			static fn(Dictionary\EntityType $left, Dictionary\EntityType $right): int => $left->getId() <=> $right->getId(),
		);

		return $items;
	}

	/**
	 * @return Public\EntityType[]
	 */
	private function getSupportedTypes(): array
	{
		return [
			Public\EntityType::lead(),
			Public\EntityType::deal(),
			Public\EntityType::contact(),
			Public\EntityType::company(),
			Public\EntityType::quote(),
			Public\EntityType::smartInvoice(),
			Public\EntityType::smartDocument(),
			Public\EntityType::smartB2eDocument(),
		];
	}

	private function createDictionaryItem(
		Public\EntityType $entityType,
		string $responseLanguage,
	): Dictionary\EntityType
	{
		return new Dictionary\EntityType(
			$entityType->getId(),
			(string)$entityType->getCode(),
			$this->titleProvider->getTitle($entityType, $responseLanguage),
		);
	}
}

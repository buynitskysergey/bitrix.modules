<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Rest\Dto\Mapping;

use Bitrix\BizprocDesigner\Infrastructure\Rest\Dto\DocumentFieldDto;
use Bitrix\BizprocDesigner\Internal\Entity\DocumentField;
use Bitrix\Rest\V3\Dto\DtoCollection;

final class DocumentFieldMapper extends AbstractAgentMapper
{
	/**
	 * @param list<DocumentField> $items
	 */
	public function mapCollection(array $items, array $fields = []): DtoCollection
	{
		$collection = new DtoCollection(DocumentFieldDto::class);
		foreach ($items as $item)
		{
			$collection->add(
				$item instanceof DocumentField ? $this->mapField($item, $fields) : new DocumentFieldDto(),
			);
		}

		return $collection;
	}

	/**
	 * The domain entity stays the source of the payload shape: options are transferred only when the
	 * field emits them, so the conditional emission is not duplicated here.
	 */
	private function mapField(DocumentField $field, array $fields): DocumentFieldDto
	{
		$dto = new DocumentFieldDto();
		foreach ($field->toArray() as $propertyName => $value)
		{
			if (!$this->isRequested($propertyName, $fields))
			{
				continue;
			}

			$dto->{$propertyName} = $value;
		}

		return $dto;
	}
}

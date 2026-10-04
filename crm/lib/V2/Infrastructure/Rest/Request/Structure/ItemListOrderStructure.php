<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure;

use Bitrix\Rest\V3\Exception\InvalidOrderException;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\Ordering\OrderStructure;
use Bitrix\Rest\V3\Structure\Structure;

final class ItemListOrderStructure extends Structure
{
	private function __construct(
		private readonly OrderStructure $structure,
	)
	{
	}

	public static function create(mixed $value, string $dtoClass, Request $request): self
	{
		if (!is_array($value) || ($value !== [] && array_is_list($value)))
		{
			throw new InvalidOrderException($value);
		}

		foreach ($value as $order)
		{
			if (!is_string($order))
			{
				throw new InvalidOrderException($order);
			}
		}

		return new self(OrderStructure::create($value, $dtoClass, $request));
	}

	public function getItems(): array
	{
		return $this->structure->getItems();
	}

	public function getList(): array
	{
		return $this->structure->getList();
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure;

use Bitrix\Rest\V3\Exception\InvalidPaginationException;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\PaginationStructure;
use Bitrix\Rest\V3\Structure\Structure;

final class ItemListPaginationStructure extends Structure
{
	private const ALLOWED_KEYS = [
		'limit',
		'offset',
		'page',
	];

	private function __construct(
		private readonly PaginationStructure $structure,
	)
	{
	}

	public static function create(mixed $value, string $dtoClass, Request $request): self
	{
		if (
			!is_array($value)
			|| ($value !== [] && array_is_list($value))
			|| array_diff(array_keys($value), self::ALLOWED_KEYS) !== []
		)
		{
			throw new InvalidPaginationException($value);
		}

		return new self(PaginationStructure::create($value, $dtoClass, $request));
	}

	public function getLimit(): int
	{
		return $this->structure->getLimit();
	}

	public function getOffset(): int
	{
		return $this->structure->getOffset();
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Rest\V3\Structure;

use Bitrix\Main\Error;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\Structure;

// Search pages by offset, not by cursor: the order is relevance, and a relevance cursor would have
// to carry a score the next query recomputes. Kept separate from the collection cursor structure so
// the search schema does not advertise an afterCursor it has no place for.
final class SearchPaginationStructure extends Structure
{
	public const DEFAULT_LIMIT = 50;
	public const MAX_LIMIT = 200;

	/**
	 * Twice the biggest corpus we have seen asked about (5295 documents), and still short of the
	 * depth where the database walks the whole ranking only to throw the head of it away.
	 */
	public const MAX_OFFSET = 10000;

	protected int $limit = self::DEFAULT_LIMIT;
	protected int $offset = 0;

	public static function create(mixed $value, ?string $dtoClass = null, ?Request $request = null): self
	{
		$structure = new self();

		if (!is_array($value))
		{
			return $structure;
		}

		if (isset($value['limit']))
		{
			$limit = (int)$value['limit'];
			if ($limit > 0)
			{
				$structure->limit = min($limit, self::MAX_LIMIT);
			}
		}

		if (isset($value['offset']))
		{
			$offset = (int)$value['offset'];
			if ($offset > self::MAX_OFFSET)
			{
				throw new RequestValidationException([
					new Error(
						'The offset of the search may not exceed ' . self::MAX_OFFSET
						. ': narrow the question instead of walking deeper.',
						'pagination',
					),
				]);
			}

			// A negative offset is the first page asked for clumsily, not a request worth refusing.
			$structure->offset = max(0, $offset);
		}

		return $structure;
	}

	public function getLimit(): int
	{
		return $this->limit;
	}

	public function getOffset(): int
	{
		return $this->offset;
	}
}

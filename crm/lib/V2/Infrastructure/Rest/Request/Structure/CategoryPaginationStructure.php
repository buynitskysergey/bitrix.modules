<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure;

use Bitrix\Rest\V3\Exception\InvalidPaginationException;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\PaginationStructure;
use Bitrix\Rest\V3\Structure\Structure;

/**
 * The paging of the category list, with a ceiling of its own.
 *
 * The categories of an entity type are read whole into memory and only then cut into a page, so the
 * thousand the framework allows would buy a client nothing and cost the portal a request holding
 * every category at once. A hundred stands well above what a portal actually keeps and twice above
 * the default page, and raising it later stays compatible while lowering it would not.
 *
 * Every bound of `limit` and `offset` is decided here and refused rather than quietly corrected. The
 * framework clips a limit above its own thousand to it, but that ceiling is the platform's and a
 * client knows it; a hundred is this family's alone, and a client that asked for two hundred and got
 * a hundred without being told would step its `offset` by two hundred and read half the categories
 * of the portal. The methods are not published yet, so the strict answer is the one to start from:
 * loosening it later stays compatible, tightening it would not.
 *
 * A limit that is not positive and a negative offset are refused for the same reason and not only
 * for it: {@see \Bitrix\Main\Provider\Params\Pager} throws on them, so the alternative to a refusal
 * here is not a quiet correction but a failure of the portal.
 *
 * The paging of the framework is `final`, hence composition rather than inheritance - the same way
 * {@see ItemListPaginationStructure} does it. The bounds are checked before the framework reads the
 * value, so that `page` counts pages of the size this contract actually serves.
 */
final class CategoryPaginationStructure extends Structure
{
	public const MAX_LIMIT = 100;

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

		if (
			isset($value['limit'])
			&& is_numeric($value['limit'])
			&& ((int)$value['limit'] > self::MAX_LIMIT || (int)$value['limit'] <= 0)
		)
		{
			throw new InvalidPaginationException(['limit' => $value['limit']]);
		}

		if (isset($value['offset']) && is_numeric($value['offset']) && (int)$value['offset'] < 0)
		{
			throw new InvalidPaginationException(['offset' => $value['offset']]);
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

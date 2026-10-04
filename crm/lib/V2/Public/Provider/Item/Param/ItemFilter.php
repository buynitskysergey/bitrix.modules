<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Provider\Item\Param;

use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\Provider\Params\FilterInterface;

/**
 * Caller-side spec for the WHERE clause of Provider read methods. Holds either an ORM-style
 * filter array (camelCase keys with Bitrix operator prefixes) or a pre-built
 * {@see ConditionTree}; the Provider resolves both forms to ORM column names.
 *
 * Example:
 * ```php
 * new ItemFilter([
 *     'stageId' => 'NEW',
 *     '>=opportunity' => 100,
 *     '@id' => [1, 2, 3],
 *     '%title' => 'Foo%',
 * ]);
 * ```
 */
final class ItemFilter implements FilterInterface
{
	public function __construct(
		private readonly array|ConditionTree $filter,
	)
	{
	}

	/**
	 * Returns the caller's raw input as-is. Used by the Provider, which performs the actual
	 * camelCase → ORM column-name translation against its bound entity type.
	 */
	public function getRaw(): array|ConditionTree
	{
		return $this->filter;
	}

	/**
	 * Implements {@see FilterInterface} so this ItemFilter plugs into
	 * {@see \Bitrix\Main\Provider\Params\GridParams}. Returns a cloned tree for the
	 * ConditionTree form and an empty tree for the array form — V2 Providers do not use
	 * this path, they call {@see getRaw()} and translate themselves.
	 */
	public function prepareFilter(): ConditionTree
	{
		return $this->filter instanceof ConditionTree
			? clone $this->filter
			: new ConditionTree()
		;
	}
}

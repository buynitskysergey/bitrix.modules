<?php

declare(strict_types=1);

namespace Bitrix\MessageService\Public\Provider\CustomTemplate\Param;

use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\Provider\Params\FilterInterface;
use Bitrix\Main\Text\Emoji;
use Bitrix\Main\Type\DateTime;
use Bitrix\MessageService\Public\Type\CustomTemplate\ReadableScope;
use Bitrix\MessageService\Public\Type\CustomTemplate\ReadableScopeKind;

final class TemplateFilter implements FilterInterface
{
	public const FIELD_SCENE = 'SCENE';
	public const FIELD_TARGET_ID = 'TARGET_ID';
	public const FIELD_SEARCH = 'FIND';
	public const FIELD_AUTHOR = 'AUTHOR_ID';
	public const FIELD_MODIFIED_BY = 'MODIFIED_BY';
	public const FIELD_DATE_CREATE = 'DATE_CREATE';
	public const FIELD_DATE_MODIFY = 'DATE_MODIFY';

	public function __construct(
		private readonly string $zone,
		private readonly ?string $scene = null,
		private readonly ?string $targetId = null,
		private readonly ?string $searchQuery = null,
		private readonly ?ReadableScope $scope = null,
		private readonly ?int $authorId = null,
		private readonly ?int $modifiedBy = null,
		private readonly ?DateTime $dateCreateFrom = null,
		private readonly ?DateTime $dateCreateTo = null,
		private readonly ?DateTime $dateModifyFrom = null,
		private readonly ?DateTime $dateModifyTo = null,
	)
	{
	}

	/**
	 * Map the grid filter request into a zone-scoped {@see TemplateFilter}.
	 *
	 * The zone is a server-side invariant — it is taken from the page binding and can never
	 * be overridden from the request. Scene/target are taken from the visible filter state
	 * (the list page seeds the current binding through a default main.ui.filter preset);
	 * the free-text query comes from the filter's quick-search field.
	 *
	 * The page is zone-wide, so the readable bindings are not implied by the URL: the caller
	 * resolves the permission-side {@see ReadableScope} and passes it here. Folding it into the
	 * filter keeps rows and count on a single query-level read model — both go through this one
	 * {@see self::prepareFilter()}.
	 *
	 * @param array<string, mixed> $appliedValues Applied filter values, e.g. from
	 *     {@see \Bitrix\Main\UI\Filter\Options::getFilter()}.
	 */
	public static function fromGridRequest(
		string $zone,
		array $appliedValues,
		?string $defaultScene = null,
		?string $defaultTargetId = null,
		?ReadableScope $scope = null,
	): self
	{
		return new self(
			zone: $zone,
			scene: self::pickString($appliedValues, self::FIELD_SCENE, $defaultScene),
			targetId: self::pickString($appliedValues, self::FIELD_TARGET_ID, $defaultTargetId),
			searchQuery: self::pickString($appliedValues, self::FIELD_SEARCH),
			scope: $scope,
			authorId: self::pickPositiveInt($appliedValues, self::FIELD_AUTHOR),
			modifiedBy: self::pickPositiveInt($appliedValues, self::FIELD_MODIFIED_BY),
			dateCreateFrom: self::pickDate($appliedValues, self::FIELD_DATE_CREATE . '_from'),
			dateCreateTo: self::pickDate($appliedValues, self::FIELD_DATE_CREATE . '_to'),
			dateModifyFrom: self::pickDate($appliedValues, self::FIELD_DATE_MODIFY . '_from'),
			dateModifyTo: self::pickDate($appliedValues, self::FIELD_DATE_MODIFY . '_to'),
		);
	}

	public function prepareFilter(): ConditionTree
	{
		// ZONE is a hard isolation boundary: every grid query must be scoped to a single zone
		// so that, e.g., the CRM list page never leaks templates from booking/calendar/etc.
		$tree = new ConditionTree();
		$tree->where('ZONE', '=', $this->zone);
		$this->applyScope($tree);
		if ($this->scene !== null && $this->scene !== '')
		{
			$tree->where('SCENE', '=', $this->scene);
		}
		if ($this->targetId !== null && $this->targetId !== '')
		{
			$tree->where('TARGET_ID', '=', $this->targetId);
		}
		if ($this->authorId !== null)
		{
			$tree->where('AUTHOR_ID', '=', $this->authorId);
		}
		if ($this->modifiedBy !== null)
		{
			$tree->where('MODIFIED_BY', '=', $this->modifiedBy);
		}
		if ($this->dateCreateFrom !== null)
		{
			$tree->where('DATE_CREATE', '>=', $this->dateCreateFrom);
		}
		if ($this->dateCreateTo !== null)
		{
			$tree->where('DATE_CREATE', '<=', $this->dateCreateTo);
		}
		if ($this->dateModifyFrom !== null)
		{
			$tree->where('DATE_MODIFY', '>=', $this->dateModifyFrom);
		}
		if ($this->dateModifyTo !== null)
		{
			$tree->where('DATE_MODIFY', '<=', $this->dateModifyTo);
		}
		if ($this->searchQuery !== null && $this->searchQuery !== '')
		{
			// TITLE and BODY store emoji as `:HEX8:` tokens (see CustomTemplateTable), so the LIKE
			// pattern is built from the encoded query to match. Encoding first also keeps the literal
			// ASCII: a raw emoji over the utf8mb3 connection would break against the utf8mb4 columns.
			$pattern = '%' . addcslashes(Emoji::encode($this->searchQuery), '\\%_') . '%';
			$tree->where(
				(new ConditionTree())
					->logic(ConditionTree::LOGIC_OR)
					->whereLike('TITLE', $pattern)
					->whereLike('BODY', $pattern)
			);
		}

		return $tree;
	}

	/**
	 * Fold the permission-side {@see ReadableScope} into the query. `null` leaves it ungated
	 * (internal/direct callers). The four cases map to: all → no predicate beyond ZONE;
	 * none → ID = 0 (a positive auto-increment primary never matches, so a spoofed/unreadable
	 * filter yields zero rows and a zero count rather than leaking the zone's totals);
	 * targets → TARGET_ID IN (...); bindings → OR group of (SCENE, TARGET_ID).
	 */
	private function applyScope(ConditionTree $tree): void
	{
		if ($this->scope === null)
		{
			return;
		}

		switch ($this->scope->kind)
		{
			case ReadableScopeKind::All:
				return;

			case ReadableScopeKind::None:
				$tree->where('ID', '=', 0);

				return;

			case ReadableScopeKind::Targets:
				$targetIds = $this->scope->getTargetIds();
				if ($targetIds === [])
				{
					$tree->where('ID', '=', 0);

					return;
				}
				$tree->whereIn('TARGET_ID', $targetIds);

				return;

			case ReadableScopeKind::Bindings:
				$bindings = $this->scope->getBindings();
				if ($bindings === [])
				{
					$tree->where('ID', '=', 0);

					return;
				}
				$allowed = (new ConditionTree())->logic(ConditionTree::LOGIC_OR);
				foreach ($bindings as $binding)
				{
					$allowed->where(
						(new ConditionTree())
							->where('SCENE', '=', $binding->scene)
							->where('TARGET_ID', '=', $binding->targetId)
					);
				}
				$tree->where($allowed);

				return;
		}
	}

	/**
	 * @param array<string, mixed> $values
	 */
	private static function pickString(array $values, string $key, ?string $fallback = null): ?string
	{
		$raw = $values[$key] ?? null;
		if (is_string($raw) && $raw !== '')
		{
			return $raw;
		}

		return $fallback;
	}

	/**
	 * The user selector posts the picked id as a scalar; normalise it to a positive int so a
	 * spoofed `0`/non-numeric value drops the predicate instead of widening the read.
	 *
	 * @param array<string, mixed> $values
	 */
	private static function pickPositiveInt(array $values, string $key): ?int
	{
		$raw = $values[$key] ?? null;
		if (is_array($raw))
		{
			$raw = $raw[0] ?? null;
		}
		if ($raw === null || $raw === '' || !is_numeric($raw))
		{
			return null;
		}

		$id = (int)$raw;

		return $id > 0 ? $id : null;
	}

	/**
	 * Applied date-range bounds arrive as `*_from`/`*_to` (relative presets are already expanded
	 * to dates by {@see \Bitrix\Main\UI\Filter\Options::getFilter()}). The value is either an
	 * already-built {@see DateTime} or a user-time string parsed through the site culture.
	 *
	 * @param array<string, mixed> $values
	 */
	private static function pickDate(array $values, string $key): ?DateTime
	{
		$raw = $values[$key] ?? null;
		if ($raw instanceof DateTime)
		{
			return $raw;
		}
		if (!is_string($raw) || $raw === '')
		{
			return null;
		}

		try
		{
			return DateTime::createFromUserTime($raw);
		}
		catch (\Throwable)
		{
			return null;
		}
	}
}

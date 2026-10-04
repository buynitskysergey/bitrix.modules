<?php

declare(strict_types=1);

namespace Bitrix\MessageService\Public\Type\CustomTemplate;

/**
 * The set of zone bindings a user may read, expressed from the permission model rather than
 * scanned from stored data. A closed set of four cases the SQL layer maps to a predicate:
 *
 * - {@see self::all()} — reads the whole zone; the gate adds no predicate beyond ZONE.
 * - {@see self::none()} — reads nothing; the gate matches no row.
 * - {@see self::targets()} — read access keyed by targetId only (scene-agnostic); IN gate.
 * - {@see self::bindings()} — read access keyed by the full (scene, targetId) pair; OR gate.
 *
 * Label-free by design: labels live in {@see ReadableFilterOptions}, so the selector's gate
 * path never builds labels it does not use.
 */
final class ReadableScope
{
	/**
	 * @param string[] $targetIds
	 * @param TemplateBinding[] $bindings
	 */
	private function __construct(
		public readonly ReadableScopeKind $kind,
		private readonly array $targetIds = [],
		private readonly array $bindings = [],
	)
	{
	}

	public static function all(): self
	{
		return new self(ReadableScopeKind::All);
	}

	public static function none(): self
	{
		return new self(ReadableScopeKind::None);
	}

	/**
	 * @param string[] $targetIds
	 */
	public static function targets(array $targetIds): self
	{
		return new self(ReadableScopeKind::Targets, targetIds: array_values($targetIds));
	}

	/**
	 * @param TemplateBinding[] $bindings
	 */
	public static function bindings(array $bindings): self
	{
		return new self(ReadableScopeKind::Bindings, bindings: array_values($bindings));
	}

	/**
	 * @return string[] Defined only for the {@see ReadableScopeKind::Targets} case.
	 */
	public function getTargetIds(): array
	{
		return $this->targetIds;
	}

	/**
	 * @return TemplateBinding[] Defined only for the {@see ReadableScopeKind::Bindings} case.
	 */
	public function getBindings(): array
	{
		return $this->bindings;
	}
}

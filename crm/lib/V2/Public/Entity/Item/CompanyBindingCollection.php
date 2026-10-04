<?php
declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Item;

/**
 * Collection of {@see CompanyBinding}.
 *
 * Diverges intentionally from the {@see \Bitrix\Main\Entity\EntityCollection} idiom used by the other
 * collections in this namespace: `add()` here returns `$this` (fluent builder) rather than `void`, and
 * this class exposes `getPrimary()`. Migrating to EntityCollection is a breaking change — its `add()` is
 * `: void`, which would break the fluent `->add(...)->add(...)` chaining relied on by callers and tests —
 * so the surface is kept as-is. The element type {@see CompanyBinding} implements EntityInterface, matching
 * the other value objects, to keep a future migration cheap.
 */
final class CompanyBindingCollection implements \Countable, \IteratorAggregate
{
	/** @var CompanyBinding[] */
	private array $items = [];

	public function add(CompanyBinding $binding): static
	{
		$this->items[] = $binding;

		return $this;
	}

	/** @return CompanyBinding[] */
	public function getAll(): array
	{
		return $this->items;
	}

	public function getPrimary(): ?CompanyBinding
	{
		foreach ($this->items as $binding)
		{
			if ($binding->isPrimary())
			{
				return $binding;
			}
		}

		return null;
	}

	public function isEmpty(): bool
	{
		return empty($this->items);
	}

	public function count(): int
	{
		return count($this->items);
	}

	public function filter(callable $predicate): static
	{
		$new = new static();
		foreach ($this->items as $item)
		{
			if ($predicate($item))
			{
				$new->add($item);
			}
		}

		return $new;
	}

	public function getIterator(): \ArrayIterator
	{
		return new \ArrayIterator($this->items);
	}
}

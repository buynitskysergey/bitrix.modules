<?php
declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Item;

/**
 * Collection of {@see ContactBinding}.
 *
 * Diverges intentionally from the {@see \Bitrix\Main\Entity\EntityCollection} idiom used by the other
 * collections in this namespace: `add()` here returns `$this` (fluent builder) rather than `void`, and
 * this class exposes `getPrimary()`. Migrating to EntityCollection is a breaking change — its `add()` is
 * `: void`, which would break the fluent `->add(...)->add(...)` chaining relied on by callers and tests —
 * so the surface is kept as-is. The element type {@see ContactBinding} implements EntityInterface, matching
 * the other value objects, to keep a future migration cheap.
 */
final class ContactBindingCollection implements \Countable, \IteratorAggregate
{
	/** @var ContactBinding[] */
	private array $items = [];

	public function add(ContactBinding $binding): static
	{
		$this->items[] = $binding;

		return $this;
	}

	/** @return ContactBinding[] */
	public function getAll(): array
	{
		return $this->items;
	}

	public function getPrimary(): ?ContactBinding
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

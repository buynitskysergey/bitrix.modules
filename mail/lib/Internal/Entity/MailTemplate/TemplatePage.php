<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Entity\MailTemplate;

use InvalidArgumentException;

final readonly class TemplatePage
{
	/**
	 * @param list<TemplateListItem> $items
	 */
	public function __construct(
		private array $items,
		private ?int $nextOffset,
		private bool $hasMore,
	)
	{
		foreach ($this->items as $item)
		{
			if (!$item instanceof TemplateListItem)
			{
				throw new InvalidArgumentException('Template page contains an invalid item.');
			}
		}

		if ($this->nextOffset !== null && $this->nextOffset < 0)
		{
			throw new InvalidArgumentException('Next offset must not be negative.');
		}

		if ($this->hasMore !== ($this->nextOffset !== null))
		{
			throw new InvalidArgumentException('Next offset must be present if and only if the page has more items.');
		}
	}

	/**
	 * @return list<TemplateListItem>
	 */
	public function getItems(): array
	{
		return $this->items;
	}

	public function getNextOffset(): ?int
	{
		return $this->nextOffset;
	}

	public function hasMore(): bool
	{
		return $this->hasMore;
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\MailTemplate;

final readonly class MailTemplatePage
{
	/**
	 * @param list<MailTemplate> $items
	 */
	public function __construct(
		private array $items,
		private bool $hasMore,
	)
	{
	}

	/**
	 * @return list<MailTemplate>
	 */
	public function getItems(): array
	{
		return $this->items;
	}

	public function hasMore(): bool
	{
		return $this->hasMore;
	}
}

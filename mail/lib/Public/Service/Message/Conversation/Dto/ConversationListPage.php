<?php

declare(strict_types=1);

namespace Bitrix\Mail\Public\Service\Message\Conversation\Dto;

/**
 * One page of the grid. `grouped` reports whether the server really applied grouping: a client that
 * gets `false` keeps drawing its own plain list and ignores the rows of this page.
 */
final class ConversationListPage
{
	/**
	 * @param ConversationSummary[] $items
	 * @param array{enabled: bool, onboardingSeen: bool, featureAvailable: bool} $settings
	 */
	public function __construct(
		public readonly array $items,
		public readonly ?string $nextCursor,
		public readonly bool $grouped,
		public readonly array $settings,
	)
	{
	}

	/**
	 * @param array{enabled: bool, onboardingSeen: bool, featureAvailable: bool} $settings
	 */
	public static function plain(array $settings): self
	{
		return new self([], null, false, $settings);
	}

	public function toArray(): array
	{
		return [
			'items' => array_map(
				static fn(ConversationSummary $item): array => $item->toArray(),
				$this->items,
			),
			'nextCursor' => $this->nextCursor,
			'grouped' => $this->grouped,
			'settings' => $this->settings,
		];
	}
}

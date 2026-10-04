<?php

declare(strict_types=1);

namespace Bitrix\MessageService\Public\Type\CustomTemplate;

/**
 * A single entity-selector badge — one entry of the selector item's `badges` list.
 *
 * The badge text is computed entirely by the owning zone provider; the frontend
 * renders it as-is. Custom-template colors are applied by default, but callers
 * can override them or pass null to fall back to the platform badge styling.
 */
final class SelectorBadge
{
	public const DEFAULT_TEXT_COLOR = 'var(--ui-color-accent-main-link)';
	public const DEFAULT_BG_COLOR = 'var(--ui-color-accent-soft-blue-2)';

	public function __construct(
		public readonly string $title,
		public readonly ?string $textColor = self::DEFAULT_TEXT_COLOR,
		public readonly ?string $bgColor = self::DEFAULT_BG_COLOR,
	) {}

	/**
	 * Serialize to the shape consumed by {@see \Bitrix\UI\EntitySelector\Item::addBadges()}:
	 * a non-empty associative array. Color keys are omitted only when explicitly unset.
	 *
	 * @return array{title: string, textColor?: string, bgColor?: string}
	 */
	public function toArray(): array
	{
		$result = ['title' => $this->title];
		if ($this->textColor !== null)
		{
			$result['textColor'] = $this->textColor;
		}
		if ($this->bgColor !== null)
		{
			$result['bgColor'] = $this->bgColor;
		}

		return $result;
	}
}

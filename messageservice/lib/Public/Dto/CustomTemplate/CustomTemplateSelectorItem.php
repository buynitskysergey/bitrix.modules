<?php

declare(strict_types=1);

namespace Bitrix\MessageService\Public\Dto\CustomTemplate;

use Bitrix\MessageService\Public\Type\CustomTemplate\SelectorBadge;
use Bitrix\MessageService\Public\Type\CustomTemplate\TemplateBinding;

final class CustomTemplateSelectorItem
{
	/**
	 * @param SelectorBadge[] $badges Computed by the zone provider relative to the current binding.
	 * @param bool $isForeign Whether this template belongs to a binding other than the current editor
	 *     binding. Consumed only by the frontend save-flow (Update vs Save); never by template insertion.
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $title,
		public readonly string $body,
		public readonly string $bodyPreview,
		public readonly TemplateBinding $binding,
		public readonly bool $isForeign = false,
		public readonly array $badges = [],
	) {}

	/**
	 * @param SelectorBadge[] $badges
	 */
	public function withBadges(array $badges): self
	{
		return new self(
			$this->id,
			$this->title,
			$this->body,
			$this->bodyPreview,
			$this->binding,
			$this->isForeign,
			$badges,
		);
	}
}

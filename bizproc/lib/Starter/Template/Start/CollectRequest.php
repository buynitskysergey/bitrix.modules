<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Starter\Template\Start;

final readonly class CollectRequest
{
	/**
	 * @param int|null $visibleForUserId narrows the selection to the templates this employee may see; null
	 *  asks for no narrowing at all, which is what the automatic paths going through the same selection
	 *  need. An employee that could not be resolved is a 0, not a null: the narrowing then hides the
	 *  templates a pilot acts on instead of silently showing them.
	 */
	public function __construct(
		public array $complexDocumentType,
		public int $eventType,
		public ?int $categoryId = null,
		public bool $onlyParameterized = false,
		public bool $useAutoExecuteBitmask = false,
		public bool $requireActive = true,
		public bool $excludeSystem = true,
		public ?int $visibleForUserId = null,
	)
	{}
}

<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Entity\DataView;

/**
 * The part of a data view the read path needs to decide whether to recompute it before serving.
 * Kept separate from {@see DataView} so the check does not load and decode the definition JSON on
 * every read of an ordinary storage.
 */
final class DataViewFreshness
{
	public function __construct(
		public readonly DataViewStatus $status,
	) {
	}

	public function isBroken(): bool
	{
		return $this->status->isBroken();
	}
}

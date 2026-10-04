<?php

declare(strict_types=1);

namespace Bitrix\Crm\RepeatSale\Transcription;

/**
 * A call activity selected into the transcription scope of a client
 * (base deal or a deals_list deal).
 */
final class InScopeCall
{
	public function __construct(
		public readonly int $activityId,
		public readonly int $dealId,
		public readonly int $responsibleId = 0,
	)
	{
	}
}

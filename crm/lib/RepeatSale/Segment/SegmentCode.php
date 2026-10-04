<?php

namespace Bitrix\Crm\RepeatSale\Segment;

enum SegmentCode: string
{
	case LOST_CLIENT = 'deal_lost_more_12_month';
	case SLEEPING_CLIENT = 'deal_activity_less_12_month';
	case DEAL_EVERY_YEAR = 'deal_every_year';
	case DEAL_EVERY_HALF_YEAR = 'deal_every_half_year';
	case DEAL_EVERY_MONTH = 'deal_every_month_year';
	case AI_SCREENING = 'ai_screening';
	case AI_APPROVE = 'ai_approve';
	case REMAINING = 'remaining';

	public static function isNeedAiModule(string $segmentCode): bool
	{
		return
			$segmentCode === self::AI_SCREENING->value
			|| $segmentCode === self::AI_APPROVE->value
			|| $segmentCode === self::REMAINING->value
		;
	}

	/**
	 * Single source of truth for the segment analytics alias (used as p5 value: segment_<alias>).
	 *
	 * The alias must never contain the `_` symbol (only dashes), so the resulting p5 string
	 * splits into exactly two parts by `_`.
	 */
	public function toAnalyticsAlias(): string
	{
		return match ($this)
		{
			self::SLEEPING_CLIENT => 'deal-activity-less-12m',
			self::LOST_CLIENT => 'deal-lost-more-12m',
			self::DEAL_EVERY_YEAR => 'deal-annual',
			self::DEAL_EVERY_HALF_YEAR => 'deal-semiannual',
			self::DEAL_EVERY_MONTH => 'deal-month-yr',
			self::AI_SCREENING => 'deal-ai-screening',
			self::AI_APPROVE => 'deal-ai-approve',
			self::REMAINING => 'deal-remaining',
		};
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Crm\Copilot\CallAssessment\Summary;

use Bitrix\Crm\Copilot\CallAssessment\CallAssessmentItem;

enum Situation: string
{
	case BadStreak = 'badStreak';
	case GoodStreak = 'goodStreak';
	case RatingDropped = 'ratingDropped';
	case RatingRaised = 'ratingRaised';

	public function getDefaultThreshold(): int
	{
		return match ($this)
		{
			self::BadStreak     => 3,
			self::GoodStreak    => 5,
			self::RatingDropped => CallAssessmentItem::LOW_BORDER_DEFAULT,
			self::RatingRaised  => CallAssessmentItem::HIGH_BORDER_DEFAULT,
		};
	}

	public function getMinThreshold(): int
	{
		return match ($this)
		{
			self::BadStreak, self::GoodStreak => 1,
			self::RatingDropped, self::RatingRaised => 0,
		};
	}

	public function getMaxThreshold(): int
	{
		return match ($this)
		{
			self::BadStreak, self::GoodStreak => 20,
			self::RatingDropped, self::RatingRaised => 100,
		};
	}

	public function getStreakHeaderKey(): ?string
	{
		return match ($this)
		{
			self::GoodStreak => 'CRM_COPILOT_SUMMARY_STREAK_GOOD_HEADER',
			self::BadStreak  => 'CRM_COPILOT_SUMMARY_STREAK_BAD_HEADER',
			default => null,
		};
	}

	public function getStreakSelfHeaderKey(): ?string
	{
		return match ($this)
		{
			self::GoodStreak => 'CRM_COPILOT_SUMMARY_STREAK_GOOD_HEADER_SELF',
			self::BadStreak  => 'CRM_COPILOT_SUMMARY_STREAK_BAD_HEADER_SELF',
			default => null,
		};
	}

	public function getStreakStatsKey(): ?string
	{
		return match ($this)
		{
			self::GoodStreak => 'CRM_COPILOT_SUMMARY_STREAK_GOOD_STATS',
			self::BadStreak  => 'CRM_COPILOT_SUMMARY_STREAK_BAD_STATS',
			default => null,
		};
	}

	public function getStreakCallsHeaderKey(): ?string
	{
		return match ($this)
		{
			self::GoodStreak => 'CRM_COPILOT_SUMMARY_STREAK_GOOD_CALLS_HEADER',
			self::BadStreak  => 'CRM_COPILOT_SUMMARY_STREAK_BAD_CALLS_HEADER',
			default => null,
		};
	}

	public function getRatingHeaderKey(): ?string
	{
		return match ($this)
		{
			self::RatingDropped => 'CRM_COPILOT_SUMMARY_RATING_DROPPED_HEADER',
			self::RatingRaised  => 'CRM_COPILOT_SUMMARY_RATING_RAISED_HEADER',
			default => null,
		};
	}

	public function getRatingSelfHeaderKey(): ?string
	{
		return match ($this)
		{
			self::RatingDropped => 'CRM_COPILOT_SUMMARY_RATING_DROPPED_HEADER_SELF',
			self::RatingRaised  => 'CRM_COPILOT_SUMMARY_RATING_RAISED_HEADER_SELF',
			default => null,
		};
	}

	public function getRatingStatsKey(): ?string
	{
		return match ($this)
		{
			self::RatingDropped => 'CRM_COPILOT_SUMMARY_RATING_DROPPED_STATS',
			self::RatingRaised  => 'CRM_COPILOT_SUMMARY_RATING_RAISED_STATS',
			default => null,
		};
	}

	public function getRatingCallsHeaderKey(): ?string
	{
		return match ($this)
		{
			self::RatingDropped => 'CRM_COPILOT_SUMMARY_RATING_DROPPED_CALLS_HEADER',
			self::RatingRaised  => 'CRM_COPILOT_SUMMARY_RATING_RAISED_CALLS_HEADER',
			default => null,
		};
	}

	public function getRatingFooterKey(): ?string
	{
		return match ($this)
		{
			self::RatingDropped => 'CRM_COPILOT_SUMMARY_RATING_DROPPED_FOOTER',
			self::RatingRaised  => 'CRM_COPILOT_SUMMARY_RATING_RAISED_FOOTER',
			default => null,
		};
	}
}

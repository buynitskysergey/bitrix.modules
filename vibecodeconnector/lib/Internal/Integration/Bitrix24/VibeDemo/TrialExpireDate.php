<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Integration\Bitrix24\VibeDemo;

use Bitrix\Bitrix24\Feature;
use Bitrix\Main\Type\DateTime;

final class TrialExpireDate
{
	public static function forEdition(string $editionId): ?DateTime
	{
		return self::fromTillDate(Feature::getTrialEditionInfo($editionId)['tillDate'] ?? null);
	}

	public static function forFeature(string $featureId): ?DateTime
	{
		return self::fromTillDate(Feature::getTrialFeatureInfo($featureId)['tillDate'] ?? null);
	}

	private static function fromTillDate(mixed $tillDate): ?DateTime
	{
		if (!is_string($tillDate))
		{
			return null;
		}

		$timestamp = strtotime($tillDate);
		if ($timestamp === false || $timestamp <= time())
		{
			return null;
		}

		return DateTime::createFromTimestamp($timestamp);
	}
}

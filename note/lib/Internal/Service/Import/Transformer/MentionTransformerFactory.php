<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Import\Transformer;

use Bitrix\Main\SystemException;
use Bitrix\Note\Internal\Repository\ImportMapRepository;

class MentionTransformerFactory
{
	public static function create(
		string $sourceType,
		ImportMapRepository $mapRepository,
		string $sourceUrl,
	): MentionTransformer
	{
		return match ($sourceType)
		{
			'outline' => new OutlineMentionTransformer($mapRepository, $sourceType, $sourceUrl),
			'landing' => new LandingMentionTransformer($mapRepository, $sourceType, $sourceUrl),
			'wiki' => new WikiMentionTransformer($mapRepository, $sourceType, $sourceUrl),
			default => throw new SystemException('No mention transformer for source type: ' . $sourceType),
		};
	}
}

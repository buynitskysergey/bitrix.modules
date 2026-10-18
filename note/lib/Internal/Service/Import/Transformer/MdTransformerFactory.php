<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Import\Transformer;

use Bitrix\Main\SystemException;

class MdTransformerFactory
{
	public static function create(string $sourceType): ImportMdTransformer
	{
		return match ($sourceType)
		{
			'outline' => new OutlineMdTransformer(),
			'landing' => new LandingMdTransformer(),
			'wiki' => new WikiMdTransformer(),
			default => throw new SystemException('No MD transformer for source type: ' . $sourceType),
		};
	}
}

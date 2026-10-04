<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Validation\Provider;

use Bitrix\Crm\V2\Internal\Integration\IBlock\IBlockDictionary;
use Bitrix\Main\NotSupportedException;

/**
 * @internal
 */
class IBlockSectionIdProvider implements ValidValuesProviderInterface
{
	/**
	 * @return int[]
	 */
	public static function getValidValues(Context $context): array
	{
		if ($context->isShowValues()) // Show all IDs from DB table can impact performance
		{
			throw new NotSupportedException();
		}

		$iBlockId = (int)($context->getExtraArgs()['iBlockId'] ?? 0);

		return static::getDictionary()->getSectionsId(
			$iBlockId,
			(array)$context->getValue(),
		);
	}

	protected static function getDictionary(): IBlockDictionary
	{
		return new IBlockDictionary();
	}
}

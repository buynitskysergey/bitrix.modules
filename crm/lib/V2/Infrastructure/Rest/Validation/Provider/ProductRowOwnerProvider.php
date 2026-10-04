<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Validation\Provider;

use Bitrix\Crm\V2\Internal\Service\Item\Dictionary\ItemDictionary;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Main\NotSupportedException;

/**
 * The owners of a product row the access user may read, among the ones asked about.
 *
 * Unlike {@see ItemProvider} the owner type cannot be written into the attribute: one product row DTO
 * serves every entity type that works with products, and the type comes from the trusted route. It is
 * therefore given per request, as `entityTypeId` of the context. Without it this provider steps aside
 * and answers "all of them" rather than guessing a type: the write scenario checks the owner and the
 * right to it in any case, so an unknown type may not turn into a refusal of a valid request.
 *
 * An owner that does not exist and an owner the user may not read both simply stay out of the answer,
 * so it never tells the two apart.
 *
 * @internal
 */
class ProductRowOwnerProvider implements ValidValuesProviderInterface
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

		$ownerIds = array_map('intval', (array)$context->getValue());
		$entityTypeId = (int)($context->getExtraArgs()['entityTypeId'] ?? 0);
		if (!EntityType::isValid($entityTypeId))
		{
			return $ownerIds;
		}

		return static::getDictionary()->getAllIdByEntityType(
			$context->getUserId(),
			EntityType::fromId($entityTypeId),
			$ownerIds,
		);
	}

	protected static function getDictionary(): ItemDictionary
	{
		return new ItemDictionary();
	}
}

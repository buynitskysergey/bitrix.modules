<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Mention\Resolver;

use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Note\Internal\Access\PortalAdmin;
use Bitrix\Note\Internal\Access\Service\CollectionAccessService;
use Bitrix\Note\Internal\Mention\MentionType;
use Bitrix\Note\Internal\Model\CollectionTable;
use Bitrix\Note\Internal\Service\Mention\MentionEntityResolver;
use Bitrix\Note\Internal\Service\Mention\ResolvedMention;

final class CollectionMentionResolver implements MentionEntityResolver
{
	public function resolve(array $ids): array
	{
		$userId = (int)CurrentUser::get()->getId();
		$isAdmin = PortalAdmin::isCurrentUserAdmin();

		// Build access codes for non-admin ACL check.
		// '*' must be included so batchGetUserLevels picks up public-policy rows.
		$accessCodes = $isAdmin ? [] : array_values(array_unique([
			...CollectionAccessService::buildUserAccessCodes($userId),
			'*',
		]));

		$normalizedIds = array_values(array_filter(array_map('intval', $ids), static fn(int $i): bool => $i > 0));
		if ($normalizedIds === [])
		{
			return [];
		}

		$levelMap = $isAdmin ? [] : CollectionAccessService::batchGetUserLevels($normalizedIds, $accessCodes);

		// Load names; archived, trashed or missing collections are treated as deleted - the token must
		// not name a base whose card the reader may have no right to even see in the bin.
		$rows = CollectionTable::query()
			->setSelect(['ID', 'NAME'])
			->whereIn('ID', $normalizedIds)
			->where('IS_ARCHIVED', 'N')
			->whereNull('TRASHED_AT')
			->exec()
		;
		$nameMap = [];
		while ($row = $rows->fetch())
		{
			$nameMap[(int)$row['ID']] = (string)$row['NAME'];
		}

		$result = [];
		foreach ($ids as $id)
		{
			$id = (int)$id;
			if (!array_key_exists($id, $nameMap))
			{
				$result[$id] = ResolvedMention::unavailable(MentionType::Collection->value, $id, 'deleted');
				continue;
			}

			$level = $isAdmin ? CollectionAccessService::LEVEL_VIEW : ($levelMap[$id] ?? CollectionAccessService::LEVEL_NONE);

			if ($isAdmin || $level >= CollectionAccessService::LEVEL_VIEW)
			{
				$result[$id] = ResolvedMention::available(
					type: MentionType::Collection->value,
					id: $id,
					label: $nameMap[$id],
					url: MentionType::Collection->urlFor($id),
				);
			}
			else
			{
				$result[$id] = ResolvedMention::unavailable(MentionType::Collection->value, $id, 'no_access');
			}
		}

		return $result;
	}
}

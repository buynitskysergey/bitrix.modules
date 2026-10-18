<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Mention\Resolver;

use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Note\Internal\Access\PortalAdmin;
use Bitrix\Note\Internal\Access\Service\CollectionAccessService;
use Bitrix\Note\Internal\Access\Service\DocumentAccessService;
use Bitrix\Note\Internal\Mention\MentionType;
use Bitrix\Note\Internal\Model\DocumentTable;
use Bitrix\Note\Internal\Service\Mention\MentionEntityResolver;
use Bitrix\Note\Internal\Service\Mention\ResolvedMention;
use Bitrix\Note\Internal\Service\RecycleBin\RecycleBinFilter;

final class DocumentMentionResolver implements MentionEntityResolver
{
	public function resolve(array $ids): array
	{
		$userId = (int)CurrentUser::get()->getId();
		$isAdmin = PortalAdmin::isCurrentUserAdmin();
		$accessCodes = $isAdmin ? [] : CollectionAccessService::buildUserAccessCodes($userId);

		$normalizedIds = array_values(array_filter(array_map('intval', $ids), static fn(int $i): bool => $i > 0));
		if ($normalizedIds === [])
		{
			return [];
		}

		// Archived/trashed documents must resolve as 'deleted' — symmetric with collections.
		$query = DocumentTable::query()
			->setSelect(['ID', 'COLLECTION_ID', 'TITLE'])
			->whereIn('ID', $normalizedIds)
			->where('IS_ARCHIVED', 'N');
		(new RecycleBinFilter())->applyExclusion($query);
		$documents = $query->fetchCollection()->getAll();

		$loadedIds = [];
		$descriptors = [];
		foreach ($documents as $doc)
		{
			$docId = (int)$doc->getId();
			$loadedIds[$docId] = $doc;
			$descriptors[] = ['id' => $docId, 'collectionId' => (int)$doc->getCollectionId()];
		}

		$levelMap = $isAdmin ? [] : DocumentAccessService::batchGetEffectiveLevels($descriptors, $accessCodes, $userId);

		$result = [];
		foreach ($ids as $id)
		{
			$id = (int)$id;
			if (!isset($loadedIds[$id]))
			{
				$result[$id] = ResolvedMention::unavailable(MentionType::Document->value, $id, 'deleted');
				continue;
			}

			$doc = $loadedIds[$id];
			$level = $isAdmin ? DocumentAccessService::LEVEL_VIEW : ($levelMap[$id] ?? DocumentAccessService::LEVEL_NONE);

			if ($isAdmin || $level >= DocumentAccessService::LEVEL_VIEW)
			{
				$result[$id] = ResolvedMention::available(
					type: MentionType::Document->value,
					id: $id,
					label: (string)$doc->getTitle(),
					url: MentionType::Document->urlFor($id),
				);
			}
			else
			{
				$result[$id] = ResolvedMention::unavailable(
					type: MentionType::Document->value,
					id: $id,
					reason: 'no_access',
				);
			}
		}

		return $result;
	}
}

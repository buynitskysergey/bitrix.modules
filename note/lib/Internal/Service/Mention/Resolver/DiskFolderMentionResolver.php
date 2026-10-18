<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Mention\Resolver;

use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Note\Internal\Integration\Disk\DiskFolderGateway;
use Bitrix\Note\Internal\Mention\MentionType;
use Bitrix\Note\Internal\Service\Mention\MentionEntityResolver;
use Bitrix\Note\Internal\Service\Mention\ResolvedMention;

final class DiskFolderMentionResolver implements MentionEntityResolver
{
	private DiskFolderGateway $gateway;

	public function __construct(?DiskFolderGateway $gateway = null)
	{
		$this->gateway = $gateway ?? new DiskFolderGateway();
	}

	public function resolve(array $ids): array
	{
		$userId = (int)CurrentUser::get()->getId();

		$normalizedIds = array_values(array_filter(array_map('intval', $ids), static fn(int $i): bool => $i > 0));

		$folders = $this->gateway->resolveBatch($normalizedIds, $userId);

		$result = [];
		foreach ($ids as $id)
		{
			$id = (int)$id;
			$folder = $folders[$id] ?? null;

			// Gone and not readable are the same answer here: the batch query never tells them apart,
			// and neither may the reader.
			if ($folder === null)
			{
				$result[$id] = ResolvedMention::unavailable(MentionType::DiskFolder->value, $id, 'no_access');
				continue;
			}

			$result[$id] = ResolvedMention::available(
				type: MentionType::DiskFolder->value,
				id: $id,
				label: $folder->name,
				url: $folder->url,
			);
		}

		return $result;
	}
}

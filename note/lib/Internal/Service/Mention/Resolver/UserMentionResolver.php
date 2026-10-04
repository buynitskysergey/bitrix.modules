<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Mention\Resolver;

use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\UserTable;
use Bitrix\Note\Internal\Mention\MentionType;
use Bitrix\Note\Internal\Service\Mention\MentionEntityResolver;
use Bitrix\Note\Internal\Service\Mention\ResolvedMention;
use Bitrix\Note\Internal\Service\User\AvatarUrl;
use CSite;
use CUser;

final class UserMentionResolver implements MentionEntityResolver
{
	public function resolve(array $ids): array
	{
		$currentUserId = (int)CurrentUser::get()->getId();

		$normalizedIds = array_values(array_filter(array_map('intval', $ids), static fn(int $i): bool => $i > 0));
		if ($normalizedIds === [])
		{
			return [];
		}

		$rows = UserTable::query()
			->setSelect(['ID', 'NAME', 'LAST_NAME', 'SECOND_NAME', 'LOGIN', 'TITLE', 'EMAIL', 'PERSONAL_PHOTO', 'ACTIVE'])
			->whereIn('ID', $normalizedIds)
			->fetchAll()
		;

		$rowMap = [];
		foreach ($rows as $row)
		{
			$rowMap[(int)$row['ID']] = $row;
		}

		$nameFormat = CSite::GetNameFormat();

		$result = [];
		foreach ($ids as $id)
		{
			$id = (int)$id;
			if (!isset($rowMap[$id]))
			{
				$result[$id] = ResolvedMention::unavailable(MentionType::User->value, $id, 'deleted');
				continue;
			}

			$row = $rowMap[$id];
			if ($row['ACTIVE'] !== 'Y')
			{
				$result[$id] = ResolvedMention::unavailable(MentionType::User->value, $id, 'no_access');
				continue;
			}

			$result[$id] = ResolvedMention::available(
				type: MentionType::User->value,
				id: $id,
				label: CUser::FormatName($nameFormat, $row, true, false),
				url: "/company/personal/user/$id/",
				avatar: $this->resolveAvatar((int)($row['PERSONAL_PHOTO'] ?? 0)),
				isCurrentUser: ($id === $currentUserId),
			);
		}

		return $result;
	}

	private function resolveAvatar(int $fileId): ?string
	{
		return AvatarUrl::forFile($fileId);
	}
}

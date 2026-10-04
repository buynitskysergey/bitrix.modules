<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\History;

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\UserTable;
use Bitrix\Note\Internal\Service\User\AvatarUrl;
use Bitrix\Note\Internal\Service\User\IdentityColor;
use Bitrix\Note\Internal\Service\User\SystemUser;
use CSite;
use CUser;

/**
 * [P2.T3] Batch-resolves USER_ID -> {id, name, avatar} for the activity feed
 * (actor + co-authors), privacy-safe: a deleted or blocked user never leaks
 * name/photo, only a neutral "unavailable" placeholder — mirrors the
 * deleted/no_access split in Mention\Resolver\UserMentionResolver.
 *
 * Distinct from \Bitrix\Note\Internal\Service\User\AuthorResolver (used for
 * document-card authorship), which has no such fallback and simply omits
 * unresolved ids — the feed instead needs every requested id present in the
 * output, because a chip/avatar must render for every event.
 */
final class AuthorResolver
{
	/**
	 * @param int[] $userIds
	 * @return array<int, array{id: int, name: string, avatar: ?string, color: string}>
	 */
	public function resolve(array $userIds): array
	{
		$ids = array_values(array_unique(array_map(static fn($id): int => (int)$id, $userIds)));

		$result = [];
		$realIds = [];
		foreach ($ids as $id)
		{
			if (SystemUser::isSystem($id))
			{
				$result[SystemUser::ID] = [
					'id' => SystemUser::ID,
					'name' => SystemUser::name(),
					'avatar' => SystemUser::avatarUrl(),
					'color' => IdentityColor::forUser(SystemUser::ID),
				];
			}
			elseif ($id > 0)
			{
				$realIds[] = $id;
			}
		}

		if ($realIds === [])
		{
			return $result;
		}

		$rows = UserTable::query()
			->setSelect(['ID', 'NAME', 'LAST_NAME', 'SECOND_NAME', 'LOGIN', 'TITLE', 'EMAIL', 'PERSONAL_PHOTO', 'ACTIVE'])
			->whereIn('ID', $realIds)
			->fetchAll()
		;

		$rowMap = [];
		foreach ($rows as $row)
		{
			$rowMap[(int)$row['ID']] = $row;
		}

		$nameFormat = CSite::GetNameFormat();
		foreach ($realIds as $id)
		{
			$row = $rowMap[$id] ?? null;
			// Missing row = deleted user; ACTIVE != 'Y' = blocked. Both fold into the
			// same neutral fallback so neither case leaks whether the account exists.
			if ($row === null || ($row['ACTIVE'] ?? 'Y') !== 'Y')
			{
				$result[$id] = $this->unavailable($id);

				continue;
			}

			$result[$id] = [
				'id' => $id,
				'name' => CUser::FormatName($nameFormat, $row, true, false),
				'avatar' => $this->resolveAvatar((int)($row['PERSONAL_PHOTO'] ?? 0)),
				'color' => IdentityColor::forUser($id),
			];
		}

		return $result;
	}

	/**
	 * @return array{id: int, name: string, avatar: null, color: string}
	 */
	private function unavailable(int $id): array
	{
		return [
			'id' => $id,
			'name' => (string)Loc::getMessage('NOTE_HISTORY_AUTHOR_UNAVAILABLE'),
			'avatar' => null,
			// Color by id even for deleted/blocked users — it leaks nothing (it's a function of the
			// id we already hold) and keeps the fallback avatar colored rather than a bare default.
			'color' => IdentityColor::forUser($id),
		];
	}

	private function resolveAvatar(int $fileId): ?string
	{
		return AvatarUrl::forFile($fileId);
	}
}

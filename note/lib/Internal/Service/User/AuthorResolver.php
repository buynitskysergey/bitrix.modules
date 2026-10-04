<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\User;

use Bitrix\Main\UserTable;
use CSite;
use CUser;

/**
 * Batch-resolves author meta (id, name, photoUrl, color) for a list of user ids.
 * Returns associative array keyed by id with stable shape.
 */
final class AuthorResolver
{
	/**
	 * @param int[] $userIds
	 * @return array<int, array{id: int, name: string, photoUrl: ?string, color: string, isSystem?: true}>
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
				$result[SystemUser::ID] = SystemUser::asAuthorMeta();
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
			->setSelect([
				'ID',
				'NAME',
				'LAST_NAME',
				'SECOND_NAME',
				'LOGIN',
				'TITLE',
				'EMAIL',
				'PERSONAL_PHOTO',
			])
			->whereIn('ID', $realIds)
			->fetchAll()
		;

		$nameFormat = CSite::GetNameFormat();
		foreach ($rows as $row)
		{
			$id = (int)$row['ID'];
			$result[$id] = [
				'id' => $id,
				'name' => CUser::FormatName($nameFormat, $row, true, false),
				'photoUrl' => $this->resolvePhotoUrl((int)($row['PERSONAL_PHOTO'] ?? 0)),
				// Same per-user hue as the editor caret and every other avatar of this user
				// (see IdentityColor) — a photoless author must not read one color on a card
				// and another one in the activity feed.
				'color' => IdentityColor::forUser($id),
			];
		}

		return $result;
	}

	private function resolvePhotoUrl(int $fileId): ?string
	{
		return AvatarUrl::forFile($fileId);
	}
}

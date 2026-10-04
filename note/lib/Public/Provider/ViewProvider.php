<?php

declare(strict_types=1);

namespace Bitrix\Note\Public\Provider;

use Bitrix\Note\Internal\Access\Service\DocumentAccessService;
use Bitrix\Note\Internal\Exceptions\AccessDeniedException;
use Bitrix\Note\Internal\Exceptions\DocumentNotFoundException;
use Bitrix\Note\Internal\Repository\DocumentViewRepository;
use Bitrix\Note\Internal\Service\History\AuthorResolver;
use Bitrix\Note\Internal\Service\User\IdentityColor;

/**
 * [P3.T3 / API-06] "Who viewed the document" — list + unique counter. Right is
 * plain LEVEL_VIEW, no role matrix (unlike the activity feed, MTX-01 does not
 * apply here): every viewer sees every other viewer.
 */
final class ViewProvider
{
	public function __construct(
		private readonly DocumentProvider $documentProvider = new DocumentProvider(),
		private readonly DocumentViewRepository $documentViewRepository = new DocumentViewRepository(),
		private readonly AuthorResolver $authorResolver = new AuthorResolver(),
	) {}

	/**
	 * @param array{viewedAt: string, userId: int}|null $afterCursor
	 * @return array{
	 *   viewers: array<int, array{userId: int, name: string, avatar: ?string, color: string, viewedAt: string}>,
	 *   uniqueCount: int,
	 *   nextCursor: ?array{viewedAt: string, userId: int}
	 * }
	 * @throws DocumentNotFoundException
	 * @throws AccessDeniedException current user lacks LEVEL_VIEW on the document
	 */
	public function getViews(int $documentId, int $limit = 50, ?array $afterCursor = null): array
	{
		$ownership = $this->documentProvider->getOwnershipInfo($documentId);
		if ($ownership === null)
		{
			throw new DocumentNotFoundException();
		}

		$snapshot = DocumentAccessService::getCurrentUserSnapshot($documentId, $ownership['collectionId']);
		if (!$snapshot['canView'])
		{
			throw new AccessDeniedException();
		}

		$limit = max(1, min(200, $limit));

		$page = $this->documentViewRepository->listViewers($documentId, $limit, $afterCursor);
		$uniqueCount = $this->documentViewRepository->countUnique($documentId);

		$userIds = array_map(static fn(array $row): int => $row['userId'], $page['rows']);
		$resolvedUsers = $this->authorResolver->resolve($userIds);

		$viewers = [];
		$lastRow = null;
		foreach ($page['rows'] as $row)
		{
			$userId = $row['userId'];
			$viewer = $resolvedUsers[$userId] ?? [
				'id' => $userId,
				'name' => '',
				'avatar' => null,
				'color' => IdentityColor::forUser($userId),
			];

			$viewers[] = [
				'userId' => $userId,
				'name' => $viewer['name'],
				'avatar' => $viewer['avatar'],
				'color' => $viewer['color'],
				'viewedAt' => $row['viewedAt']->format('c'),
			];
			$lastRow = $row;
		}

		$nextCursor = null;
		if ($page['hasNextPage'] && $lastRow !== null)
		{
			$nextCursor = [
				'viewedAt' => $lastRow['viewedAt']->format('Y-m-d H:i:s'),
				'userId' => $lastRow['userId'],
			];
		}

		return [
			'viewers' => $viewers,
			'uniqueCount' => $uniqueCount,
			'nextCursor' => $nextCursor,
		];
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Rest\V3\Access;

use Bitrix\Note\Internal\Access\Service\DocumentAccessService;
use Bitrix\Note\Internal\Entity\RecycleBin\RecycleBinRecord;

/**
 * The production gate: both answers come from {@see DocumentAccessService}, which reads the rights of
 * the user the request is authenticated as.
 */
final class CurrentUserAccessGate implements DocumentAccessGate
{
	public function canViewInRecycleBin(int $userId, RecycleBinRecord $record): bool
	{
		return DocumentAccessService::canViewInRecycleBin($userId, $record);
	}

	public function snapshot(int $documentId, int $collectionId, bool $isArchived): array
	{
		// An archived knowledge base is still read here - the resolver above answers Archived and the
		// caller is told why writes are refused. A base in the recycle bin is not: it is closed on this
		// surface, and its documents keep their own rows, so nothing else would close them.
		$snapshot = DocumentAccessService::getCurrentUserSnapshot(
			$documentId,
			$collectionId,
			$isArchived,
			allowStates: [DocumentAccessService::STATE_ARCHIVED],
		);

		return [
			'canView' => (bool)$snapshot['canView'],
			'canEdit' => (bool)$snapshot['canEdit'],
			'sharedAccess' => (bool)$snapshot['sharedAccess'],
		];
	}
}

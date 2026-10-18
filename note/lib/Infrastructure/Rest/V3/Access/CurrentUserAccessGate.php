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
		$snapshot = DocumentAccessService::getCurrentUserSnapshot($documentId, $collectionId, $isArchived);

		return [
			'canView' => (bool)$snapshot['canView'],
			'canEdit' => (bool)$snapshot['canEdit'],
			'sharedAccess' => (bool)$snapshot['sharedAccess'],
		];
	}
}

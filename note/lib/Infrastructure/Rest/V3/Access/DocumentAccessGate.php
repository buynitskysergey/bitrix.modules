<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Rest\V3\Access;

use Bitrix\Note\Internal\Entity\RecycleBin\RecycleBinRecord;

/**
 * The two access questions {@see DocumentStateResolver} asks, behind an interface.
 *
 * The answers still belong to the access services; this only names the questions so the order they are
 * asked in can be tested without a portal, a session and a set of grants behind it.
 */
interface DocumentAccessGate
{
	public function canViewInRecycleBin(int $userId, RecycleBinRecord $record): bool;

	/**
	 * @return array{canView: bool, canEdit: bool, sharedAccess: bool}
	 */
	public function snapshot(int $documentId, int $collectionId, bool $isArchived): array;
}

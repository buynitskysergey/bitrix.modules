<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Rest\V3\Request;

use Bitrix\Rest\V3\Interaction\Request\Request;

class ListFilesRequest extends Request
{
	public const DEFAULT_LIMIT = 50;
	public const MAX_LIMIT = 200;

	/** Non-nullable on purpose: attachments are always asked about one document at a time. */
	public int $documentId;

	/**
	 * Page of the list: `limit` and `afterCursor`, the cursor of the previous answer passed back
	 * verbatim. Read as it arrives rather than through a Structure of its own - the cursor is opaque
	 * to the client, and there is nothing in it to validate against the DTO. Its numbers are still
	 * numbers, which is the one thing checked here ({@see PaginationNumber}).
	 */
	public ?array $pagination = null;

	public function getLimit(): int
	{
		$limit = PaginationNumber::read($this->pagination['limit'] ?? null, 'pagination.limit');

		return $limit > 0 ? min($limit, self::MAX_LIMIT) : self::DEFAULT_LIMIT;
	}

	public function getAfterFileId(): int
	{
		return max(
			PaginationNumber::read($this->pagination['afterCursor']['id'] ?? null, 'afterCursor.id'),
			0,
		);
	}
}

<?php

namespace Bitrix\Sign\Item\Api\Batch;

use Bitrix\Sign\Contract;
use Bitrix\Sign\Type\Api\BatchItemStatus;

/**
 * Outcome of a single item of a batch action, as it comes in `data.results` of a batch response.
 *
 * Status values belong to the service contract, so an unknown one must not be read as a success:
 * a new service outcome falls into the failed branch until the portal learns it.
 */
class ItemResult implements Contract\Item
{
	public function __construct(
		public readonly string $documentUid,
		public readonly ?string $memberUid,
		public readonly ?BatchItemStatus $status,
		public readonly ?string $message = null,
	)
	{}

	/**
	 * Response item keys are `documentId` and `memberId`; both carry the uids the request was
	 * built from, not the internal ids of the portal.
	 *
	 * A status the portal does not know reads as `null` instead of raising: the service is released
	 * apart from the portal, so an outcome of its own must not break the answer about the group.
	 */
	public static function createFromResponseItem(array $item): self
	{
		$memberUid = $item['memberId'] ?? null;
		$message = $item['message'] ?? null;

		return new self(
			(string)($item['documentId'] ?? ''),
			$memberUid === null ? null : (string)$memberUid,
			BatchItemStatus::tryFrom((string)($item['status'] ?? '')),
			$message === null ? null : (string)$message,
		);
	}

	/**
	 * A target state reached before this call counts as a success: it closes a repeat of the
	 * group after a lost response.
	 */
	public function isSuccess(): bool
	{
		return $this->status === BatchItemStatus::SUCCESS || $this->status === BatchItemStatus::ALREADY_DONE;
	}
}

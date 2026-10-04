<?php

declare(strict_types=1);

namespace Bitrix\Note\Public\Event;

use Bitrix\Main\Event;

/**
 * EVENT-NOTE-02 — note document content-settled backend-event.
 *
 * The published contract for a document whose materialized content has settled: subscribers
 * register on `('note', self::EVENT_NAME)` and read the payload via
 * {@see \Bitrix\Main\Event::getParameters()}. The reason literals below are part of that contract.
 */
final class OnDocumentContentSettledEvent extends Event
{
	public const MODULE_ID = 'note';
	public const EVENT_NAME = 'onDocumentContentSettled';

	public const COMPACTED = 'compacted';
	public const OVERWRITTEN = 'overwritten';
	public const TITLE_CHANGED = 'titleChanged';

	/**
	 * @param string $reason One of the content-settled reason constants above.
	 */
	public function __construct(string $reason, int $collectionId, int $documentId)
	{
		parent::__construct(self::MODULE_ID, self::EVENT_NAME, [
			'reason' => $reason,
			'collectionId' => $collectionId,
			'documentId' => $documentId,
		]);
	}
}

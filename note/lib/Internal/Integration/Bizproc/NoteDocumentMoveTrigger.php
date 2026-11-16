<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Integration\Bizproc;

/**
 * The contract of the "document moved" trigger node. Same shape as the creation trigger plus
 * who performed the move; see {@see NoteDocumentCreateTrigger} for why the code takes this form and why
 * neither value may be renamed after the first release.
 */
final class NoteDocumentMoveTrigger
{
	public const CODE = 'NoteDocumentMoveTrigger';

	// Fixed by the platform: the standard return-document trait reads the document under this key.
	public const FIELD_DOCUMENT = 'Document';
	public const FIELD_EVENT_DATE = 'EventDate';
	public const FIELD_MOVED_BY = 'MovedBy';
}

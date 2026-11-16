<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Integration\Bizproc;

/**
 * The contract of the "document created" trigger node, kept once for both of its sides: the
 * handler that fills the event data and the node that publishes it as returns.
 *
 * CODE is the node class name without the CBP prefix, because that is the string the platform writes
 * into the trigger-to-template binding (`CBPActivity::getType()`), and direct comparisons against that
 * column are case-sensitive.
 *
 * Values are frozen by the first release: the keys travel inside serialized data of deferred events and
 * the code travels into saved templates.
 */
final class NoteDocumentCreateTrigger
{
	public const CODE = 'NoteDocumentCreateTrigger';

	// Fixed by the platform: the standard return-document trait reads the document under this key.
	public const FIELD_DOCUMENT = 'Document';
	public const FIELD_EVENT_DATE = 'EventDate';
	public const FIELD_CREATED_BY = 'CreatedBy';
}

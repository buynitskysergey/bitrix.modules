<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Collaboration;

use Bitrix\Note\Internal\Model\DocumentTable;

/**
 * The rule that decides whether a document may live in the collaborative format - shared by the genesis
 * write that enforces it (SaveYjsStateCommand) and by the two load responses that report it to the client
 * as `canEnableCollaboration`.
 *
 * A document demoted to plain markdown by OverwriteDocumentContentCommand (REST rewrite, version restore)
 * is not collaborative as it stands: DocumentUpdateRepository::add refuses patches for it, and the
 * collaboration bootstrap serves MARKDOWN rather than YJS_STATE. What must never happen is the format
 * coming back under a client that still holds the Y.Doc from before the rewrite - it would put the
 * discarded text back. But that is a statement about the client, not about the document, and the two were
 * conflated for as long as the format was the only discriminator available: blocking the document forever
 * was the only way to block such a client, and it also froze the document for everyone else and for every
 * later session, with nothing able to thaw it.
 *
 * So promotion is allowed again, on one condition the server can actually check: the baseline must be a
 * rebuild of the document's own current text. A client declares the rebuild (SaveYjsStateCommand's
 * `rebuiltFromMarkdown`), and the declaration is only accepted while the document has no baseline stored
 * yet and still holds text to have been rebuilt from. A client of the discarded text never travels this
 * path: the editor rebuilds from the markdown the load response has just handed it, and the conversion
 * that declares the rebuild is the one place that does so.
 *
 * The materialization cursor is what separates a demoted document from a legacy markdown document that
 * never knew the CRDT: the overwrite pins the cursor, and nothing but the collaborative machinery
 * (materialization, compaction) or that command ever writes it, so a document that has been through
 * neither still holds NULL. An accepted rebuild resets the cursor for the same reason the overwrite pinned
 * it - see SaveYjsStateCommand: the pinned value describes the text that was replaced, and the new text
 * has materialized nothing yet. Import demotes a document its own way - Import\Step\FillContentStep switches
 * the format and clears YJS_STATE without pinning a cursor - but it clears no cursor either, so an
 * import-demoted document falls under this rule whenever materialization or compaction had already pinned
 * one.
 *
 * The predicates read nothing: callers pass the values they already have. SaveYjsStateCommand reads the
 * cursor uncached - a stale NULL is exactly the hole this rule closes - while a load path takes the
 * fields off the document it has just fetched. That document owes the predicate its fields in its own
 * select: an unselected cursor reads back as NULL (sysGetValue does not lazy-load), which is
 * indistinguishable from a never-materialized one.
 */
final class CollaborationEligibility
{
	public static function isEnabled(string $contentFormat, ?int $materializedUptoId): bool
	{
		if ($contentFormat === DocumentTable::CONTENT_FORMAT_MD && $materializedUptoId !== null)
		{
			return false;
		}

		return true;
	}

	/**
	 * Whether a demoted document still holds text for a client to have rebuilt its baseline from. Weak by
	 * nature - the server cannot read a Y.Doc, so it cannot compare the baseline against the markdown, only
	 * see that there is something to rebuild from. It rules out the one case that is checkable and plainly
	 * wrong: a rebuild claimed for a document that holds no text at all.
	 */
	public static function hasRebuildSource(?string $markdown): bool
	{
		return $markdown !== null && $markdown !== '';
	}

	/**
	 * The whole rule as the load responses report it: a demoted document is still offered to the client,
	 * because the client can bring it back by rebuilding the baseline from the text in the same response.
	 * Reported as false only for a document that is neither collaborative nor rebuildable, where an
	 * attempt would be refused and the client is better off not making it.
	 */
	public static function canBeCollaborative(
		string $contentFormat,
		?int $materializedUptoId,
		?string $markdown,
	): bool
	{
		return self::isEnabled($contentFormat, $materializedUptoId)
			|| self::hasRebuildSource($markdown);
	}
}

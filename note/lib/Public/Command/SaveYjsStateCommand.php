<?php

declare(strict_types=1);

namespace Bitrix\Note\Public\Command;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Result;
use Bitrix\Main\SystemException;
use Bitrix\Note\Internal\Exceptions\DocumentArchivedException;
use Bitrix\Note\Internal\Exceptions\DocumentInRecycleBinException;
use Bitrix\Note\Internal\Model\DocumentTable;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Repository\DocumentUpdateRepository;
use Bitrix\Note\Internal\Service\Collaboration\CollaborationEligibility;
use Bitrix\Note\Internal\Service\Collaboration\DocumentLockService;
use Bitrix\Note\Internal\Service\RecycleBin\RecycleBinFilter;

class SaveYjsStateCommand extends AbstractCommand
{
	private const LOCK_TIMEOUT = 10;

	private readonly int $documentId;
	private readonly int $userId;
	private readonly string $yjsState;
	private readonly bool $rebuiltFromMarkdown;
	private readonly ?string $markdownChecksum;
	private readonly DocumentRepository $documentRepository;
	private readonly DocumentUpdateRepository $updateRepository;
	private readonly RecycleBinFilter $recycleBinFilter;
	private readonly DocumentLockService $lockService;

	/**
	 * $rebuiltFromMarkdown is the caller's declaration that this baseline was built from the document's
	 * own markdown, as the load response had just served it. It is what lets a demoted document come back
	 * into the collaborative format instead of staying out of it for good - see CollaborationEligibility.
	 * Left false, the demotion still holds.
	 *
	 * $markdownChecksum names the text the declaration is about: CRC-32 of its UTF-8 bytes as an unsigned
	 * decimal, which is what crc32() returns. The declaration alone says nothing about WHICH text was
	 * rebuilt, and the document can be overwritten again between the load response and this write, so
	 * without the checksum an honest client of an already-replaced text would be taken at its word - see
	 * isRebuildOfCurrentText(). Left null, no declaration is accepted.
	 */
	public function __construct(
		int $documentId,
		int $userId,
		string $yjsState,
		bool $rebuiltFromMarkdown = false,
		?string $markdownChecksum = null,
		?DocumentRepository $documentRepository = null,
		?RecycleBinFilter $recycleBinFilter = null,
		?DocumentLockService $lockService = null,
		?DocumentUpdateRepository $updateRepository = null,
	)
	{
		$this->documentId = $documentId;
		$this->userId = $userId;
		$this->yjsState = $yjsState;
		$this->rebuiltFromMarkdown = $rebuiltFromMarkdown;
		$this->markdownChecksum = $markdownChecksum;
		$this->documentRepository = $documentRepository ?? new DocumentRepository();
		$this->recycleBinFilter = $recycleBinFilter ?? new RecycleBinFilter();
		$this->lockService = $lockService ?? new DocumentLockService();
		$this->updateRepository = $updateRepository ?? new DocumentUpdateRepository();
	}

	protected function execute(): Result
	{
		if ($this->recycleBinFilter->isInRecycleBin($this->documentId))
		{
			throw new DocumentInRecycleBinException();
		}

		// Serialize concurrent genesis writers (overwrite-rebuild, legacy MD conversion,
		// first save of a new doc): the first one wins, late clients observe the persisted
		// baseline instead of clobbering it with their own divergent Y.Doc.
		//
		// The shared 'compact' lock, the same one overwrite, compaction and materialization take, and not
		// a scope of its own. This write reads the format and the cursor and then promotes the document on
		// what it read; an overwrite running between the two demotes it and pins the cursor. Under separate
		// scopes the two never meet, and the promotion lands on a document that was overwritten a moment
		// earlier - the demotion undone by a claim that was true when it was checked. Nesting the two locks
		// instead of merging them is not open to us: on production MySQL (5.6) a session holds at most one
		// named lock, and GET_LOCK silently releases the previous one.
		$hasLock = $this->lockService->acquireLock($this->documentId, self::LOCK_TIMEOUT);
		// Without the lock there is no serialization guarantee, so a late writer could read a
		// stale null baseline and clobber a concurrent genesis. Abort instead of writing blind.
		if (!$hasLock)
		{
			throw new SystemException('Failed to acquire genesis lock');
		}

		try
		{
			$document = $this->documentRepository->getMetaById($this->documentId, ['ID', 'UPDATED_AT', 'CONTENT_FORMAT', 'IS_ARCHIVED']);
			if ($document === null)
			{
				throw new SystemException('Document not found');
			}

			if ($document->getIsArchived())
			{
				throw new DocumentArchivedException();
			}

			// Uncached read so a concurrent writer's genesis is observed inside the lock.
			$existingState = $this->documentRepository->getYjsState($this->documentId);
			if ($existingState !== null)
			{
				$result = new Result();
				$result->setData(['applied' => false, 'yjsState' => $existingState]);

				return $result;
			}

			// See CollaborationEligibility for the whole rule. The cursor is read uncached for the same
			// reason as the state above - a stale NULL reopens the hole.
			//
			// A demoted document is promoted back only by a baseline that is a rebuild of its own current
			// text: the lost-race branch above has just established that no baseline is stored for that
			// text yet, and the caller declares the rebuild. A client still holding the Y.Doc from before
			// the demotion never makes that declaration - only the conversion path does, and it rebuilds
			// from the markdown the load response served it.
			$isEligible = CollaborationEligibility::isEnabled(
				(string)$document->getContentFormat(),
				$this->documentRepository->getMaterializedUptoId($this->documentId),
			);
			// Asked only of a caller that makes the claim, and asked of the text itself: the document is
			// promoted back only while it still holds the very text the baseline claims to be a rebuild of.
			$isRebuild = $this->rebuiltFromMarkdown
				&& !$isEligible
				&& $this->isRebuildOfCurrentText();
			if (!$isEligible && !$isRebuild)
			{
				$result = new Result();
				// Same shape as the lost-race branch above, minus the baseline: nothing was stored and
				// there is no server state to rebuild onto - this document is not collaborative for this
				// caller.
				$result->setData(['applied' => false, 'yjsState' => null]);

				return $result;
			}

			$document->setYjsState($this->yjsState);
			$document->setUpdatedBy($this->userId);
			if ($document->getContentFormat() !== DocumentTable::CONTENT_FORMAT_YJS)
			{
				$document->setContentFormat(DocumentTable::CONTENT_FORMAT_YJS);
			}
			if ($isRebuild)
			{
				// The pinned cursor belongs to the text this baseline replaces: the overwrite put it there
				// to make every patch of that text count as settled. Left in place, it answers a client
				// still holding that text with a waterline it already stands at, so its next patch looks
				// like a continuation, and its materialization then publishes the discarded text over this
				// one. Reset, the journal honestly starts from nothing: such a client fails the continuity
				// check and merges the server state instead of publishing over it.
				//
				// Zero, not NULL, although the waterline reads the same either way (SavePatchCommand takes
				// a missing cursor as zero). NULL is reserved for a document that has never been through
				// the collaborative machinery, and this one has: a later demotion that pins no cursor of
				// its own - the import path does that - would otherwise leave it looking like legacy
				// markdown, promotable by anyone with no claim to make.
				$document->setMaterializedUptoId(0);
				// Any journal row present belongs to the replaced text: the overwrite cleared the journal,
				// and nothing legitimate has written one since - the document was out of the collaborative
				// format until this very save. Rows do appear all the same, through the check-then-insert
				// window in DocumentUpdateRepository::add, and the load response serves them on top of the
				// new baseline, which would put fragments of the discarded text back into it. Deleted
				// before the save, while the format still refuses new rows: after it, a co-author's first
				// patch is legitimate and must not be swept away with them.
				$this->updateRepository->deleteByDocumentId($this->documentId);
			}
			$saveResult = $this->documentRepository->save($document);

			if (!$saveResult->isSuccess())
			{
				$result = new Result();
				$result->addErrors($saveResult->getErrors());

				return $result;
			}

			$result = new Result();
			$result->setData(['applied' => true]);

			return $result;
		}
		finally
		{
			$this->lockService->releaseLock($this->documentId);
		}
	}

	/**
	 * Whether the claimed rebuild is a rebuild of the text this document holds right now.
	 *
	 * The claim on its own is not tied to any particular text, and a second overwrite landing between the
	 * load response and this write leaves nothing else to notice it by: such an overwrite finds the document
	 * already demoted, so it touches neither the journal nor the cursor - it only replaces MARKDOWN. Every
	 * signal the eligibility rule reads therefore looks exactly as it did before it, and a client that
	 * honestly rebuilt from the first text would have that baseline accepted as the content of the second.
	 * The checksum is what closes it: the client names the text it parsed, and only that text may be
	 * promoted.
	 *
	 * Read uncached, inside the document lock, for the same reason the baseline and the cursor above are -
	 * a value from before a concurrent write is the hole this whole rule exists to close. The lock is the
	 * one an overwrite takes, so the text weighed here cannot be replaced between this check and the save.
	 *
	 * A claim without a checksum is refused rather than trusted. It names no text, and the direction of the
	 * refusal is the safe one: the document simply stays out of the collaborative format, which is a state
	 * the client already knows how to recover from - it keeps what the user typed instead of publishing it
	 * over somebody else's text.
	 */
	private function isRebuildOfCurrentText(): bool
	{
		if ($this->markdownChecksum === null)
		{
			return false;
		}

		$markdown = $this->documentRepository->getRawMarkdown($this->documentId);
		if (!CollaborationEligibility::hasRebuildSource($markdown))
		{
			return false;
		}

		return (string)crc32((string)$markdown) === $this->markdownChecksum;
	}
}

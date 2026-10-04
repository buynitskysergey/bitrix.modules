<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Controller;

use Bitrix\Main\Command\Exception\CommandException;
use Bitrix\Main\Error;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\SystemException;
use Bitrix\Main\Web\Json;
use Bitrix\Note\Internal\Access\Service\DocumentAccessService;
use Bitrix\Note\Internal\Exceptions\DocumentArchivedException;
use Bitrix\Note\Internal\Exceptions\DocumentInRecycleBinException;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Repository\DocumentUpdateRepository;
use Bitrix\Note\Internal\Service\Analytics\AnalyticsDictionary;
use Bitrix\Note\Internal\Service\Analytics\AnalyticsService;
use Bitrix\Note\Internal\Service\Collaboration\PushNotificationService;
use Bitrix\Note\Internal\Service\History\ViewTrackingService;
use Bitrix\Note\Public\Command\CompactDocumentCommand;
use Bitrix\Note\Public\Command\MaterializeDocumentCommand;
use Bitrix\Note\Public\Command\SavePatchCommand;
use Bitrix\Note\Public\Command\SaveYjsStateCommand;
use Bitrix\Note\Public\Provider\CollaborationProvider;

class CollaborationSyncController extends Controller
{
	private ?DocumentRepository $documentRepository = null;

	protected function getDefaultPreFilters(): array
	{
		return array_merge(
			parent::getDefaultPreFilters(),
			[
				new ActionFilter\NoteAccess(),
			],
		);
	}

	public function loadForCollaborationAction(int $documentId): ?array
	{
		$collectionId = $this->resolveCollectionId($documentId);
		if ($collectionId === null)
		{
			return null;
		}

		if (!$this->assertDocumentViewAccess($documentId, $collectionId))
		{
			return null;
		}

		$userId = (int)$this->getCurrentUser()->getId();

		return (new CollaborationProvider())->loadForCollaboration($documentId, $userId);
	}

	public function loadPatchesAction(int $documentId): ?array
	{
		$collectionId = $this->resolveCollectionId($documentId);
		if ($collectionId === null)
		{
			return null;
		}

		if (!$this->assertDocumentViewAccess($documentId, $collectionId))
		{
			return null;
		}

		return (new CollaborationProvider())->loadPatches($documentId);
	}

	public function savePatchAction(int $documentId, string $patch, ?string $cursor = null): ?array
	{
		$collectionId = $this->resolveCollectionId($documentId);
		if ($collectionId === null)
		{
			return null;
		}

		if (!$this->assertDocumentEditAccess($documentId, $collectionId))
		{
			return null;
		}

		$userId = (int)$this->getCurrentUser()->getId();

		try
		{
			$result = (new SavePatchCommand($documentId, $userId, $patch, $cursor))->run();
		}
		catch (SystemException $e)
		{
			$this->addError(new Error(Loc::getMessage('NOTE_COLLABORATION_SYNC_SAVE_ERROR')));

			return null;
		}

		if (!$result->isSuccess())
		{
			// A refused patch is told apart from a failed one, and each refusal by its own cause. The
			// generic error is a "try again later", and the sender does exactly that: the queue goes back
			// into local storage and waits for the next attempt.
			$errors = $result->getErrorCollection();

			// For a document taken out of the collaborative format there is no next attempt worth making -
			// the answer is the same every time - and the sender needs to hear that while it still holds
			// the only copy of what was typed, so it can rescue it instead of queueing it for good. This
			// code is what it hangs that on, so it stays bound to the format and to nothing else.
			if ($errors->getErrorByCode(DocumentUpdateRepository::ERROR_DOCUMENT_NOT_EDITABLE) !== null)
			{
				$this->addError(new Error(
					Loc::getMessage('NOTE_DOCUMENT_NOT_EDITABLE'),
					DocumentUpdateRepository::ERROR_DOCUMENT_NOT_EDITABLE,
				));

				return null;
			}

			// The lifecycle states keep the phrases and codes they already have on the compaction and
			// materialization paths (addSyncError), so a client hears one and the same cause named the same
			// way whichever action ran into it.
			if ($errors->getErrorByCode(DocumentUpdateRepository::ERROR_DOCUMENT_ARCHIVED) !== null)
			{
				$this->addError(new Error(Loc::getMessage('NOTE_DOCUMENT_ARCHIVED')));

				return null;
			}

			if ($errors->getErrorByCode(DocumentUpdateRepository::ERROR_DOCUMENT_TRASHED) !== null)
			{
				$this->addError(new Error(Loc::getMessage('NOTE_DOCUMENT_TRASHED'), 'DOCUMENT_TRASHED'));

				return null;
			}

			// Only reachable through a race: the action resolves the collection first, and a document
			// missing by then has already been answered there with this same phrase.
			if ($errors->getErrorByCode(DocumentUpdateRepository::ERROR_DOCUMENT_NOT_FOUND) !== null)
			{
				$this->addError(new Error(Loc::getMessage('NOTE_COLLABORATION_SYNC_DOCUMENT_NOT_FOUND')));

				return null;
			}

			$this->addError(new Error(Loc::getMessage('NOTE_COLLABORATION_SYNC_SAVE_ERROR')));

			return null;
		}

		// [API-02] Additive fields; an older client ignores them. patchId lets the sender advance its
		// applied cursor (it never receives its own patch over pull); compactSuggested flags a journal
		// that has grown past the server backstop.
		$data = $result->getData();

		return [
			'success' => true,
			'compactSuggested' => (bool)($data['compactSuggested'] ?? false),
			'patchId' => (int)($data['patchId'] ?? 0),
			// [EVENT-01] Without the predecessor the sender has nothing to check its own write against:
			// it never receives its own patch over pull, so it would take the new id for proof that
			// nothing was missed. Having seen 8, missed somebody's 9 and saved 10, it would then report
			// cursor 10 for a text that lacks 9 — and no client could correct the projection afterwards,
			// since the forward-only guard refuses an equal cursor. Same field as in the pull payload.
			'prevPatchId' => (int)($data['prevPatchId'] ?? 0),
			// [EVENT-01] The id below which the journal of this document is empty. Only a zero prevPatchId
			// makes it matter, and only then is it filled: without it the sender reads "nothing precedes
			// me" as "everything below was compacted away", advances its cursor over patches it never
			// applied, and its next compaction writes that hole in as the settled state. Same field as in
			// the pull payload; 0 means "not stated".
			'journalBaseId' => (int)($data['journalBaseId'] ?? 0),
		];
	}

	public function compactAction(int $documentId, string $markdown, int $processedUpToId, ?string $yjsState = null): ?array
	{
		$collectionId = $this->resolveCollectionId($documentId);
		if ($collectionId === null)
		{
			return null;
		}

		if (!$this->assertDocumentEditAccess($documentId, $collectionId))
		{
			return null;
		}

		$userId = (int)$this->getCurrentUser()->getId();

		try
		{
			$result = (new CompactDocumentCommand($documentId, $userId, $markdown, $processedUpToId, $yjsState))->run();
		}
		catch (SystemException $e)
		{
			$this->addSyncError($e);

			return null;
		}

		$locked = $result->getData()['locked'] ?? false;
		if ($locked)
		{
			return ['locked' => true];
		}

		// Content-save reached: label the compact snapshot persist as edit_text.
		AnalyticsService::documentUpdated(AnalyticsDictionary::CHANGE_TYPE_EDIT_TEXT, $result->isSuccess(), AnalyticsDictionary::TYPE_BK);

		return ['success' => true];
	}

	/**
	 * [API-01] Cheap projection refresh separate from compaction: writes the fresh markdown, content
	 * date and cursor without touching the patch log, versions, events or analytics. A read-only client
	 * (no EDIT level) is denied; archive/recycle-bin block it the same way they block compactAction.
	 */
	public function materializeAction(int $documentId, string $markdown, int $uptoId): ?array
	{
		$collectionId = $this->resolveCollectionId($documentId);
		if ($collectionId === null)
		{
			return null;
		}

		if (!$this->assertDocumentEditAccess($documentId, $collectionId))
		{
			return null;
		}

		try
		{
			// No actor is passed: materialization refreshes the projection without claiming authorship -
			// UPDATED_BY stays with whoever last made a recorded change (see MaterializeDocumentCommand).
			$result = (new MaterializeDocumentCommand($documentId, $markdown, $uptoId))->run();
		}
		catch (SystemException $e)
		{
			$this->addSyncError($e);

			return null;
		}

		$data = $result->getData();
		$locked = $data['locked'] ?? false;
		if ($locked)
		{
			return ['locked' => true];
		}

		$applied = (bool)($data['applied'] ?? false);

		// [API-01] `success` stays for the bundles already running in browsers, which read only it.
		$response = [
			'success' => true,
			// applied=false → the forward-only guard refused this cursor, nothing was stored. The client
			// must not remember the text as materialized on that answer, or it would never resend it.
			'applied' => $applied,
		];

		if ($applied)
		{
			// Card preview of the text that has just been stored, computed by the same code the document
			// lists use, so the client can refresh a card without a list request of its own.
			$response['excerpt'] = (string)($data['excerpt'] ?? '');
		}

		return $response;
	}

	/**
	 * `rebuiltFromMarkdown` is the caller stating that this baseline was built from the markdown the load
	 * response had just served it, rather than from a Y.Doc it was already holding. It is what lets a
	 * document demoted by an overwrite come back into the collaborative format - see
	 * CollaborationEligibility. Untyped and read tolerantly: the flag arrives from an urlencoded request,
	 * where a plain false travels as the string "false".
	 *
	 * `markdownChecksum` names the text that claim is about - CRC-32 of its UTF-8 bytes as an unsigned
	 * decimal. Read as tolerantly and from the same request: anything that is not such a number is passed
	 * on as absent, and a claim with no checksum is not accepted (SaveYjsStateCommand).
	 */
	public function saveYjsStateAction(
		int $documentId,
		string $yjsState,
		$rebuiltFromMarkdown = false,
		$markdownChecksum = null,
	): ?array
	{
		$collectionId = $this->resolveCollectionId($documentId);
		if ($collectionId === null)
		{
			return null;
		}

		if (!$this->assertDocumentEditAccess($documentId, $collectionId))
		{
			return null;
		}

		$userId = (int)$this->getCurrentUser()->getId();

		try
		{
			$result = (new SaveYjsStateCommand(
				$documentId,
				$userId,
				$yjsState,
				$this->normalizeFlag($rebuiltFromMarkdown),
				$this->normalizeChecksum($markdownChecksum),
			))->run();
		}
		catch (SystemException $e)
		{
			$this->addSyncError($e);

			return null;
		}

		if (!$result->isSuccess())
		{
			$this->addError(new Error(Loc::getMessage('NOTE_COLLABORATION_SYNC_SAVE_ERROR')));

			return null;
		}

		$data = $result->getData();

		return [
			'success' => true,
			// applied=false → the state was not stored. With a yjsState next to it a concurrent client
			// won the genesis race and the late client rebuilds its Y.Doc onto that shared baseline;
			// without one the document has been demoted to plain markdown by an overwrite and is not
			// collaborative any more (see SaveYjsStateCommand), so there is no baseline to hand back.
			'applied' => (bool)($data['applied'] ?? true),
			'yjsState' => $data['yjsState'] ?? null,
		];
	}

	public function sendAwarenessAction(int $documentId, string $awareness): ?array
	{
		$collectionId = $this->resolveCollectionId($documentId);
		if ($collectionId === null)
		{
			return null;
		}

		if (!$this->assertDocumentViewAccess($documentId, $collectionId))
		{
			return null;
		}

		$userId = (int)$this->getCurrentUser()->getId();

		try
		{
			$data = Json::decode($awareness);
		}
		catch (\Exception)
		{
			$this->addError(new Error(Loc::getMessage('NOTE_COLLABORATION_SYNC_AWARENESS_ERROR')));

			return null;
		}

		// [P3.T2] `join` is the only awareness message guaranteed to reach the server
		// (heartbeat/cursor/etc. go client-to-client over BX.PULL) — track the view here.
		// Not gated by Configuration::isActivityEnabled() (SDD F2): the hook stays cheap
		// and keeps the view counter live even while the activity UI is switched off.
		if (($data['type'] ?? null) === 'join')
		{
			(new ViewTrackingService())->track($documentId, $userId);
		}

		(new PushNotificationService())->sendAwareness($documentId, $userId, $data);

		return ['success' => true];
	}

	/**
	 * Reports a failed compaction/materialization to the client by its real cause.
	 *
	 * AbstractCommand::run() wraps whatever execute() threw into a CommandException, which is itself a
	 * SystemException, so catching the domain exceptions around run() cannot match them. The cause is
	 * unwrapped here rather than guessed from the message: an archived or trashed document keeps its own
	 * message and code instead of collapsing into the generic sync error. The generic message speaks of
	 * saving for every action here: compaction and materialization are invisible to the user, who only
	 * ever asked for the text to be kept.
	 */
	private function addSyncError(\Throwable $error): void
	{
		$cause = $error instanceof CommandException ? ($error->getPrevious() ?? $error) : $error;

		if ($cause instanceof DocumentArchivedException)
		{
			$this->addError(new Error(Loc::getMessage('NOTE_DOCUMENT_ARCHIVED')));

			return;
		}

		if ($cause instanceof DocumentInRecycleBinException)
		{
			$this->addError(new Error(Loc::getMessage('NOTE_DOCUMENT_TRASHED'), 'DOCUMENT_TRASHED'));

			return;
		}

		$this->addError(new Error(Loc::getMessage('NOTE_COLLABORATION_SYNC_SAVE_ERROR')));
	}

	/**
	 * A flag off an urlencoded request is a string, and every string but the empty one is truthy - "false"
	 * included. Only the affirmative spellings count as set, and only a scalar counts as a flag at all: an
	 * array arrives truthy from `flag[]=0` and says nothing about what the caller meant.
	 */
	private function normalizeFlag(mixed $value): bool
	{
		if (is_string($value))
		{
			return in_array(mb_strtolower(trim($value)), ['1', 'true', 'y'], true);
		}

		return is_bool($value) || is_int($value) ? (bool)$value : false;
	}

	/**
	 * A CRC-32 off an urlencoded request: an unsigned decimal as a string, ten digits at most. Anything
	 * else - a signed or fractional number, a hex spelling, an array, an empty string - is no checksum and
	 * travels on as none, which costs the rebuild claim its acceptance instead of weighing it against a
	 * value that cannot match. Leading zeros are dropped rather than refused: they name the same number,
	 * and a plain string comparison downstream would not see it.
	 */
	private function normalizeChecksum(mixed $value): ?string
	{
		if (is_int($value))
		{
			return $value >= 0 ? (string)$value : null;
		}

		if (!is_string($value))
		{
			return null;
		}

		$trimmed = trim($value);

		return preg_match('/^\d{1,10}$/', $trimmed) === 1 ? (string)(int)$trimmed : null;
	}

	private function resolveCollectionId(int $documentId): ?int
	{
		$document = $this->getDocumentRepository()->getMetaById($documentId, ['ID', 'COLLECTION_ID']);
		if ($document === null)
		{
			$this->addError(new Error(Loc::getMessage('NOTE_COLLABORATION_SYNC_DOCUMENT_NOT_FOUND')));

			return null;
		}

		return $document->getCollectionId();
	}

	private function getDocumentRepository(): DocumentRepository
	{
		$this->documentRepository ??= new DocumentRepository();

		return $this->documentRepository;
	}

	private function assertDocumentViewAccess(int $documentId, int $collectionId): bool
	{
		if (DocumentAccessService::currentUserHasLevel($documentId, $collectionId, DocumentAccessService::LEVEL_VIEW))
		{
			return true;
		}

		$this->denyAccess();

		return false;
	}

	private function assertDocumentEditAccess(int $documentId, int $collectionId): bool
	{
		if (DocumentAccessService::currentUserHasLevel($documentId, $collectionId, DocumentAccessService::LEVEL_EDIT))
		{
			return true;
		}

		$this->denyAccess();

		return false;
	}

	private function denyAccess(): void
	{
		$this->addError(new Error((string)(Loc::getMessage('NOTE_COLLABORATION_SYNC_ACCESS_DENIED'))));
	}
}

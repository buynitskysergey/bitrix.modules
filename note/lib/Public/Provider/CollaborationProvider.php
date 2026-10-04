<?php

declare(strict_types=1);

namespace Bitrix\Note\Public\Provider;

use Bitrix\Main\Loader;
use Bitrix\Note\Internal\Integration\Pull\HistoryPullGateway;
use Bitrix\Note\Internal\Model\Document;
use Bitrix\Note\Internal\Model\DocumentTable;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Repository\DocumentUpdateRepository;
use Bitrix\Note\Internal\Service\Collaboration\CollaborationEligibility;
use Bitrix\Note\Internal\Service\Document\DocumentLastChangeResolver;
use Bitrix\Note\Internal\Service\User\AvatarUrl;
use Bitrix\Note\Internal\Service\User\IdentityColor;

class CollaborationProvider
{
	private DocumentRepository $documentRepository;
	private DocumentUpdateRepository $updateRepository;
	private DocumentLastChangeResolver $lastChangeResolver;

	public function __construct(
		?DocumentRepository $documentRepository = null,
		?DocumentUpdateRepository $updateRepository = null,
		?DocumentLastChangeResolver $lastChangeResolver = null,
	)
	{
		$this->documentRepository = $documentRepository ?? new DocumentRepository();
		$this->updateRepository = $updateRepository ?? new DocumentUpdateRepository();
		$this->lastChangeResolver = $lastChangeResolver ?? new DocumentLastChangeResolver();
	}

	/**
	 * $contentFormat and $materializedUptoId come from the document the caller has already loaded, so the
	 * eligibility rule costs no query here. A caller that has no format leaves both out - or passes null,
	 * which is the same thing - and the response carries no `canEnableCollaboration` at all: an absent key
	 * means "rule unknown" to the client, which then makes one attempt and takes the server's refusal as
	 * the final word.
	 *
	 * The same goes for `materializedUptoId`: absent means "no cursor was read", not "the cursor is zero".
	 * The client tells a queue of a replaced lineage from its own by comparing the cursor it was opened
	 * with against the one it holds now, and a missing value read as zero would make every ordinary open
	 * look like the start of a new lineage.
	 */
	public function buildCollaborationMeta(
		int $documentId,
		int $collectionId,
		int $userId,
		bool $canEdit,
		?string $contentFormat = null,
		?int $materializedUptoId = null,
	): ?array
	{
		if ($userId <= 0 || $collectionId <= 0 || $documentId <= 0)
		{
			return null;
		}

		$this->subscribeUserToDocumentTag($userId, $documentId, $collectionId);

		$presence = $this->resolveUserPresence($userId);

		// [EVENT-01] Head first, list second - see loadPatches().
		$lastPatchId = $this->updateRepository->getLastId($documentId);
		$patches = $this->updateRepository->getByDocumentId($documentId);

		$meta = [
			'readOnly' => !$canEdit,
			'currentUser' => [
				'id' => (string)$userId,
				'name' => $presence['name'],
				'color' => IdentityColor::forUser($userId),
				'avatar' => $presence['avatar'],
			],
			'patches' => $patches,
			'lastPatchId' => $lastPatchId,
		];

		if ($materializedUptoId !== null)
		{
			$meta['materializedUptoId'] = $materializedUptoId;
		}

		// A hint only as far as the caller's snapshot is fresh: read the document through the ORM cache and
		// a demotion committed a moment ago can still show up as true. The client then makes one attempt
		// and gets the authoritative refusal from SaveYjsStateCommand - the same place the rule is
		// enforced. A blank format is no format: it carries no rule to report, same as none at all.
		//
		// A demoted document gets no answer from here rather than a false one. Since the demotion can be
		// lifted by rebuilding the baseline from the document's own text, the answer depends on whether
		// there is text - and these callers have not read it. An absent key already means "rule unknown"
		// to the client, which then makes one attempt; a false would instead stop it from trying at all,
		// and a document the server would have promoted would stay unsaveable.
		if ($contentFormat !== null && $contentFormat !== '')
		{
			if (CollaborationEligibility::isEnabled($contentFormat, $materializedUptoId))
			{
				$meta['canEnableCollaboration'] = true;
			}
		}

		return $meta;
	}

	public function loadForCollaboration(int $documentId, int $userId): array
	{
		// [EVENT-01] Journal first (head, then list - see loadPatches()), content snapshot last, and the
		// snapshot read uncached. Compaction persists YJS_STATE and drains the journal in one transaction:
		// read the other way round, a client could get a pre-compaction snapshot next to a post-compaction
		// journal and lose exactly the window that was folded away. In this order the snapshot is never
		// older than the journal - a drained journal comes with the state that replaced it.
		$lastPatchId = $this->updateRepository->getLastId($documentId);
		$patches = $this->updateRepository->getByDocumentId($documentId);
		$document = $this->documentRepository->getCollaborationSnapshot($documentId);

		$this->subscribeUserToDocumentTag($userId, $documentId, (int)($document?->getCollectionId() ?? 0));

		// The activity-line chip is remounted on a content-overwrite reload (see the FE
		// #handleContentOverwritten), so this response must carry the fresh last-change or the
		// chip would fall back to its stale bootstrap prop and revert to the pre-overwrite time.
		$lastChange = $this->lastChangeResolver->resolve($documentId);

		$contentFormat = (string)($document?->getContentFormat() ?? DocumentTable::CONTENT_FORMAT_YJS);
		$yjsState = $document?->getYjsState();
		if ($document !== null
			&& $contentFormat === DocumentTable::CONTENT_FORMAT_YJS
			&& $yjsState !== null
			&& $yjsState !== ''
		)
		{
			$response = [
				'yjsState' => $yjsState,
				'contentFormat' => $contentFormat,
				'patches' => $patches,
				'lastPatchId' => $lastPatchId,
				'lastChange' => $lastChange,
			];
		}
		else
		{
			$response = [
				'markdown' => $document?->getMarkdown(),
				'contentFormat' => $contentFormat,
				'patches' => $patches,
				'lastPatchId' => $lastPatchId,
				'lastChange' => $lastChange,
			];
		}

		// Authoritative here: the snapshot above is read uncached, so a demotion committed a moment ago is
		// already visible, and this is the one path that also holds the text - so it can answer for a
		// demoted document too, which the bootstrap hint cannot. Reported in both branches: the client
		// needs the rule even when it is handed markdown, because that is exactly the shape a demoted
		// document comes back in. A missing document carries no rule to report: the absent key leaves the
		// client with "rule unknown".
		if ($document !== null)
		{
			$response['canEnableCollaboration'] = CollaborationEligibility::canBeCollaborative(
				$contentFormat,
				$document->getMaterializedUptoId(),
				$document->getMarkdownRaw(),
			);
			// The document's waterline, so the client can tell a state it built here from one built before
			// an overwrite it has not processed yet. Additive: a bundle already running in a browser does
			// not read the key. NULL is an answer of its own - never materialized - not a missing one: the
			// field rides along in the snapshot select (DocumentRepository::COLLABORATION_SELECT), because
			// sysGetValue does not lazy-load and an unselected column would read back as NULL just the same.
			//
			// Reported, not enforced: the server cannot tell a stale tab from a co-author whose cursor sits
			// legitimately below the line, so a refusal built on this value here would refuse ordinary
			// collaborative editing.
			$response['materializedUptoId'] = $document->getMaterializedUptoId();
		}

		return $response;
	}

	public function loadPatches(int $documentId): array
	{
		// [EVENT-01] The head is read BEFORE the list, never after. The two are separate queries, and a
		// patch inserted between them would land in lastPatchId while missing from patches - the client
		// falls back to the server's lastPatchId when the list is empty, so it would advance its applied
		// cursor over a patch it never saw, and the forward-only guard makes that irreversible. Read in
		// this order the head is at worst behind the list, which costs nothing: the client derives its
		// cursor from the patches it actually holds.
		$lastPatchId = $this->updateRepository->getLastId($documentId);
		$patches = $this->updateRepository->getByDocumentId($documentId);

		return [
			'patches' => $patches,
			'lastPatchId' => $lastPatchId,
		];
	}

	private function subscribeUserToDocumentTag(int $userId, int $documentId, int $collectionId = 0): void
	{
		if (!Loader::includeModule('pull'))
		{
			return;
		}

		$tags = [
			'NOTE_DOC_' . $documentId,
			'NOTE_DOC_AWARE_' . $documentId,
			// History sidebar live-updates channel — see HistoryPullGateway / EventLogService::record().
			HistoryPullGateway::watchTag($documentId),
			// ACL channel — capability pushes flow here. FE extendWatch only renews; server-side Add is required.
			'NOTE_DOC_' . $documentId . '_ACL',
		];
		if ($collectionId > 0)
		{
			// Collection cascade (archive/delete) and ACL flips fan out via these tags;
			// without server-side Add an editor opened on a bare template would never get them.
			$tags[] = 'NOTE_COLLECTION_' . $collectionId;
			$tags[] = 'NOTE_COLLECTION_' . $collectionId . '_ACL';
		}
		foreach ($tags as $tag)
		{
			\CPullWatch::Add($userId, $tag);
		}
	}

	private function resolveUserPresence(int $userId): array
	{
		$row = \Bitrix\Main\UserTable::query()
			->setSelect(['ID', 'NAME', 'LAST_NAME', 'SECOND_NAME', 'LOGIN', 'TITLE', 'EMAIL', 'PERSONAL_PHOTO'])
			->where('ID', $userId)
			->setCacheTtl(3600)
			->fetch()
		;

		// Format via CUser::FormatName with $bUseLogin=true so a nameless account (e.g. invited by
		// email, profile not filled) falls back to LOGIN (the email) instead of a bare "User #id" —
		// same path as the History/User AuthorResolver and UserMentionResolver.
		$name = 'User #' . $userId;
		if ($row)
		{
			$name = \CUser::FormatName(\CSite::GetNameFormat(), $row, true, false);
		}

		return [
			'name' => $name,
			'avatar' => $this->resolveAvatar((int)($row['PERSONAL_PHOTO'] ?? 0)),
		];
	}

	private function resolveAvatar(int $fileId): ?string
	{
		return AvatarUrl::forFile($fileId);
	}
}

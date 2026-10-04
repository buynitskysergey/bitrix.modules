<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Collaboration;

use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Note\Internal\Access\Service\DocumentAccessService;
use Bitrix\Pull\Event;

class PushNotificationService
{
	public const REALTIME_BATCH_THRESHOLD = 500;

	// Single pull command for every "your shared tree changed, refetch it" signal — both the ACL
	// cascade (EVENT-01) and plain tree changes inside an already granted subtree.
	public const COMMAND_ACCESS_CASCADE = 'documentAccessCascade';

	private const TAG_DOCUMENT_PREFIX = 'NOTE_DOC_';
	private const TAG_DOCUMENT_ACL_SUFFIX = '_ACL';
	private const TAG_AWARENESS_PREFIX = 'NOTE_DOC_AWARE_';
	private const TAG_COLLECTION_PREFIX = 'NOTE_COLLECTION_';
	private const TAG_COLLECTION_ACL_SUFFIX = '_ACL';
	private const TAG_GLOBAL = 'NOTE_GLOBAL';
	private const MODULE_ID = 'note';

	public function sendDocumentPatch(
		int $documentId,
		int $skipUserId,
		string $patch,
		?string $cursor = null,
		?int $patchId = null,
		?int $prevPatchId = null,
		?int $journalBaseId = null,
	): void
	{
		$params = [
			'documentId' => $documentId,
			'patch' => $patch,
			'userId' => $skipUserId,
		];

		if ($cursor !== null && $cursor !== '')
		{
			$params['cursor'] = $cursor;
		}

		// [EVENT-01] Patch continuity. The id alone cannot express it: b_note_document_updates.ID is a
		// table-wide auto-increment, so the patches of one document are numbered with gaps whenever
		// other documents are edited at the same time. prevPatchId names the previous patch OF THIS
		// DOCUMENT, so a receiver knows it missed nothing exactly when prevPatchId equals its own
		// cursor. All three keys are optional — an older client ignores them.
		if ($patchId !== null)
		{
			$params['patchId'] = $patchId;
		}

		if ($prevPatchId !== null)
		{
			$params['prevPatchId'] = $prevPatchId;
		}

		// [EVENT-01] Journal waterline: the id below which this document has no journal rows left. A zero
		// prevPatchId alone says only "nothing precedes me in the journal", which reads the same whether
		// the journal was cut below what the receiver had applied or below what it never received; taken
		// for the former, the receiver advances its cursor over a hole and later persists that gap as the
		// authoritative state. The waterline separates the two cases. Sent only where it can do that work
		// (see SavePatchCommand::readJournalBaseId), 0 otherwise.
		if ($journalBaseId !== null)
		{
			$params['journalBaseId'] = $journalBaseId;
		}

		$this->sendByTag(
			self::TAG_DOCUMENT_PREFIX . $documentId,
			'documentPatchReceived',
			$params,
			[$skipUserId],
		);
	}

	public function sendAwareness(int $documentId, int $skipUserId, array $data): void
	{
		$this->sendByTag(
			self::TAG_AWARENESS_PREFIX . $documentId,
			'documentAwareness',
			array_merge($data, ['documentId' => $documentId]),
			[$skipUserId],
		);
	}

	public function sendToDocument(int $documentId, string $command, array $params, ?int $initiatorUserId = null): void
	{
		$this->sendByTag(
			self::TAG_DOCUMENT_PREFIX . $documentId,
			$command,
			$params,
			$initiatorUserId !== null ? [$initiatorUserId] : [],
		);
	}

	public function sendToDocumentAcl(int $documentId, string $command, array $params, ?int $initiatorUserId = null): void
	{
		$this->sendByTag(
			self::TAG_DOCUMENT_PREFIX . $documentId . self::TAG_DOCUMENT_ACL_SUFFIX,
			$command,
			$params,
			$initiatorUserId !== null ? [$initiatorUserId] : [],
		);
	}

	public function sendToCollection(int $collectionId, string $command, array $params, ?int $initiatorUserId = null): void
	{
		$this->sendByTag(
			self::TAG_COLLECTION_PREFIX . $collectionId,
			$command,
			$params,
			$initiatorUserId !== null ? [$initiatorUserId] : [],
		);
	}

	public function sendToCollectionAcl(int $collectionId, string $command, array $params, ?int $initiatorUserId = null): void
	{
		$this->sendByTag(
			self::TAG_COLLECTION_PREFIX . $collectionId . self::TAG_COLLECTION_ACL_SUFFIX,
			$command,
			$params,
			$initiatorUserId !== null ? [$initiatorUserId] : [],
		);
	}

	/**
	 * $operationId names the one overwrite this push reports, so a tab that asked for it can recognise its
	 * own operation and let the rest through as somebody else's. Without it the tab has only the document
	 * id to correlate on, and a foreign overwrite whose push happens to arrive first would be taken for
	 * the answer to its own request and applied over the editing it has open.
	 *
	 * Absent for a REST overwrite, and rightly so: nobody in the tab asked for it, so for the tab it is a
	 * foreign write and must be handled as one. The key is left out rather than sent as null - an older
	 * bundle reads neither, and a present key says "this is somebody's named operation".
	 */
	public function sendDocumentContentOverwritten(
		int $documentId,
		int $byUserId,
		bool $overwrite,
		?string $operationId = null,
	): void
	{
		$params = [
			'documentId' => $documentId,
			'byUserId' => $byUserId,
			'overwrite' => $overwrite,
			'ts' => time(),
		];
		if ($operationId !== null)
		{
			$params['operationId'] = $operationId;
		}

		// No initiator skip: an REST overwrite is out-of-band, so even the initiator's own open
		// editor (a different session under the same user-id) must receive it and rebuild.
		$this->sendToDocument($documentId, 'documentContentOverwritten', $params);
	}

	public function sendGlobal(string $command, array $params, ?int $initiatorUserId = null): void
	{
		$this->sendByTag(
			self::TAG_GLOBAL,
			$command,
			$params,
			$initiatorUserId !== null ? [$initiatorUserId] : [],
		);
	}

	public function sendToUserChannel(int $userId, string $command, array $params): void
	{
		if (!Loader::includeModule('pull'))
		{
			return;
		}

		Event::add($userId, [
			'module_id' => self::MODULE_ID,
			'command' => $command,
			'params' => $params,
		]);
	}

	/**
	 * One event addressed to the whole audience instead of one call per recipient.
	 *
	 * Pull collapses identical payloads into a single message either way (it keys pending messages by
	 * a hash of their parameters and merges the user lists), but the per-call work — normalising the
	 * parameters, encoding them, hashing them — is paid once per call. An ACL cascade over a large
	 * audience pays it thousands of times for one and the same payload.
	 *
	 * @param int[] $userIds
	 */
	public function sendToUserChannels(array $userIds, string $command, array $params): void
	{
		if (!Loader::includeModule('pull'))
		{
			return;
		}

		$recipients = array_values(array_unique(array_filter(
			array_map(static fn($userId): int => (int)$userId, $userIds),
			static fn(int $userId): bool => $userId > 0,
		)));
		if (empty($recipients))
		{
			return;
		}

		Event::add($recipients, [
			'module_id' => self::MODULE_ID,
			'command' => $command,
			'params' => $params,
		]);
	}

	/**
	 * Collection-level event + optional NOTE_GLOBAL broadcast, single dispatchAfterCommit
	 * closure, threshold-aware payload (documentIds list under the threshold, requestRefetch
	 * flag above it).
	 *
	 * Per-document fan-out is intentionally absent: open editors listen on NOTE_COLLECTION_{cid}
	 * for documentArchive/documentDelete/collectionArchive/collectionDelete and decide locally
	 * (by documentIds match, or by re-fetching their own meta on requestRefetch).
	 *
	 * @param array<int|string> $documentIds  Subtree ids touched by the operation (used by sidebar + open editors to match their own id).
	 * @param array<string, mixed> $collectionPayloadExtra  Extra fields merged into the payload (next to collectionId / documentIds / requestRefetch).
	 * @param string|null $globalCommand  Optional NOTE_GLOBAL broadcast (e.g. collection lifecycle).
	 * @param array<string, mixed> $globalPayload  Payload for the global broadcast.
	 */
	public function emitDocumentCascade(
		int $collectionId,
		array $documentIds,
		string $collectionCommand,
		array $collectionPayloadExtra = [],
		?int $initiatorUserId = null,
		?string $globalCommand = null,
		array $globalPayload = [],
		int $threshold = self::REALTIME_BATCH_THRESHOLD,
	): void
	{
		$normalizedIds = array_values(array_map('intval', $documentIds));
		$requestRefetch = count($normalizedIds) > $threshold;

		$collectionPayload = $collectionPayloadExtra + ['collectionId' => $collectionId];
		if ($requestRefetch)
		{
			$collectionPayload['requestRefetch'] = true;
		}
		else
		{
			$collectionPayload['documentIds'] = $normalizedIds;
		}

		// The access cascade runs its own personal fan-out (it knows the exact affected subjects),
		// so mirroring it here would only resolve the same recipients twice. Everything else that
		// travels this path means "these documents left the tree" and is applied in place by the
		// recipient, under the same command name.
		//
		// The payload is rebuilt from the ids alone, NOT forwarded: collectionPayloadExtra can carry
		// collection-wide data (recycleBinMap on collectionDelete) about documents a grantee never
		// had access to. The ids they do not hold are ignored client-side; anything else is theirs
		// to not receive.
		if ($collectionCommand !== self::COMMAND_ACCESS_CASCADE)
		{
			$granteePayload = $requestRefetch
				? ['requestRefetch' => true]
				: ['documentIds' => $normalizedIds]
			;
			$this->notifyDocumentGrantees(
				$collectionId,
				$normalizedIds,
				$initiatorUserId,
				$collectionCommand,
				$granteePayload,
			);
		}

		$this->dispatchAfterCommit(function () use (
			$collectionId,
			$collectionCommand,
			$collectionPayload,
			$initiatorUserId,
			$globalCommand,
			$globalPayload,
		): void {
			$this->sendToCollection($collectionId, $collectionCommand, $collectionPayload, $initiatorUserId);

			if ($globalCommand !== null)
			{
				$this->sendGlobal($globalCommand, $globalPayload, $initiatorUserId);
			}
		});
	}

	/**
	 * Mirrors a document-tree change to the personal pull channel of every user who sees those
	 * documents only through a document-level grant (the "Shared with me" section).
	 *
	 * Such a user is not subscribed to NOTE_COLLECTION_{cid} — and must not be, the collection
	 * channel carries titles of documents they cannot see — so the collection cascade never
	 * reaches them and their tree would stay stale until a page reload.
	 *
	 * Recipients are resolved synchronously: by the time the deferred job runs, the ACL rows the
	 * lookup relies on may already be gone (revoke, hard delete).
	 *
	 * The default command is the coarse "refetch your section" cascade. Pass a concrete tree command
	 * instead whenever the change can be applied in place (rename, new child, removal): the payload
	 * is forwarded as-is plus a `sharedScope` marker, which routes it to the accessible-tree
	 * namespace on the client — the recipient has no collection tree to patch.
	 *
	 * @param array<int|string> $documentIds documents whose audience must be notified
	 * @param array<string, mixed> $params payload for a concrete tree command (ignored for the cascade)
	 */
	public function notifyDocumentGrantees(
		int $collectionId,
		array $documentIds,
		?int $skipUserId = null,
		string $command = self::COMMAND_ACCESS_CASCADE,
		array $params = [],
	): void
	{
		$normalizedIds = array_values(array_unique(array_filter(
			array_map(static fn($id): int => (int)$id, $documentIds),
			static fn(int $id): bool => $id > 0,
		)));
		if ($collectionId <= 0 || empty($normalizedIds))
		{
			return;
		}

		$userIds = DocumentAccessService::resolveGranteeUserIds($normalizedIds);
		if ($skipUserId !== null)
		{
			$userIds = array_values(array_filter(
				$userIds,
				static fn(int $userId): bool => $userId !== $skipUserId,
			));
		}
		if (empty($userIds))
		{
			return;
		}

		if ($command === self::COMMAND_ACCESS_CASCADE)
		{
			$payload = count($normalizedIds) > self::REALTIME_BATCH_THRESHOLD
				? ['collectionId' => $collectionId, 'requestRefetch' => true]
				: ['collectionId' => $collectionId, 'documentIds' => $normalizedIds];
		}
		else
		{
			$payload = $params + ['collectionId' => $collectionId, 'sharedScope' => true];
		}

		$this->dispatchAfterCommit(function () use ($userIds, $command, $payload): void {
			$this->sendToUserChannels($userIds, $command, $payload);
		});
	}

	/**
	 * Defers emission until after the HTTP response is sent. By that point
	 * Bitrix has either committed the surrounding transaction or aborted
	 * the request with an exception, so receivers never observe state the
	 * initiator later rolled back.
	 */
	public function dispatchAfterCommit(callable $emitter): void
	{
		Application::getInstance()->addBackgroundJob($emitter);
	}

	public function sendByTag(string $tag, string $command, array $params, array $skipUsers = []): void
	{
		if (!Loader::includeModule('pull'))
		{
			return;
		}

		\CPullWatch::AddToStack(
			$tag,
			[
				'module_id' => self::MODULE_ID,
				'command' => $command,
				'params' => $params,
				'skip_users' => $skipUsers,
			],
		);
	}
}

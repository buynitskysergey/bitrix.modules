<?php

namespace Bitrix\Sign\Operation;

use Bitrix\Sign\Item;
use Bitrix\Sign\Item\Api\Document\Signing\StopBatchRequest;
use Bitrix\Sign\Repository\DocumentRepository;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Type;
use Bitrix\Sign\Contract;

use Bitrix\Main;

/**
 * Stops a group of documents with a single service call; SigningStop stays the way of a single action.
 *
 * An item outcome belongs to a document, while the readability of the answer belongs to the whole
 * call: an answer that never came or could not be read leaves the outcome unknown for every document
 * of the group.
 *
 * A successful result means the group was processed, not that every document was stopped: the data
 * key `successfulDocumentUids` holds the documents that reached the target state, and a document
 * absent from it is a failure of this run.
 */
final class SigningStopBatch implements Contract\Operation
{
	public const SUCCESSFUL_DOCUMENT_UIDS = 'successfulDocumentUids';

	private readonly DocumentRepository $documentRepository;

	/** @var array<string, Item\Document> */
	private array $documentsByUid = [];

	/** @var array<string, true> uids whose trace was written by this run and may be taken back */
	private array $ownTraceUids = [];

	/**
	 * @param list<Item\Document> $documents
	 * @param int $userId initiator of the stop, written as the trace before the call while the
	 *                    document carries none
	 */
	public function __construct(
		private readonly array $documents,
		private readonly int $userId,
		?DocumentRepository $documentRepository = null,
	)
	{
		$this->documentRepository = $documentRepository ?? Container::instance()->getDocumentRepository();
	}

	public function launch(): Main\Result
	{
		$this->indexDocuments();

		$requestedUids = $this->writeStoppedByTrace();
		if ($requestedUids === [])
		{
			return $this->createResult([]);
		}

		$response = Container::instance()->getApiDocumentSigningService()
			->stopBatch(
				new StopBatchRequest($requestedUids)
			)
		;

		if (!$response->isSuccess())
		{
			// A transport error leaves the outcome of the whole group unknown: the service may have
			// stopped the documents anyway, and its callback resolves the initiator from stoppedById,
			// so the traces must stay.
			if (!Type\Api\TransportErrorCode::isPresentInErrors($response->getErrors()))
			{
				$this->rollbackStoppedByTrace($requestedUids);
			}

			return $this->createResult([])->addErrors($response->getErrors());
		}

		return $this->createResult(
			$this->applyItemOutcomes($requestedUids, $this->indexResults($response->results))
		);
	}

	private function indexDocuments(): void
	{
		foreach ($this->documents as $document)
		{
			// a document without a uid cannot be addressed by the service, and one without an id
			// cannot carry a trace, so neither belongs to the group
			if ($document->uid === null || $document->uid === '' || ($document->id ?? 0) <= 0)
			{
				continue;
			}

			$this->documentsByUid[$document->uid] = $document;
		}
	}

	/**
	 * The trace is never overwritten: a document already carrying an initiator keeps it, and the
	 * write of an empty one is conditional, so of two simultaneous stops only the first owns the
	 * trace. A document whose trace belongs to someone else is still asked about: the stop itself
	 * is wanted, only the attribution of it is not this run's to claim.
	 *
	 * @return list<string> uids of the documents the call is made for
	 */
	private function writeStoppedByTrace(): array
	{
		$requestedUids = [];
		foreach ($this->documentsByUid as $document)
		{
			$uid = (string)$document->uid;
			$requestedUids[] = $uid;

			if ($document->stoppedById !== null)
			{
				continue;
			}

			if (!$this->documentRepository->setStoppedByIdIfEmpty((int)$document->id, $this->userId))
			{
				// either another stop won the race or the write failed: either way the trace of
				// this run does not exist and must not be taken back later
				continue;
			}

			$document->stoppedById = $this->userId;
			$this->ownTraceUids[$uid] = true;
		}

		return $requestedUids;
	}

	/**
	 * Only a trace written by this run is taken back; an earlier initiator stays untouched. The
	 * conditional write is taken back by a conditional reset: an item write would neither empty the
	 * field nor tell whether the trace is still the one this run left there.
	 *
	 * @param list<string> $uids
	 */
	private function rollbackStoppedByTrace(array $uids): void
	{
		foreach ($uids as $uid)
		{
			$document = $this->documentsByUid[$uid] ?? null;
			if ($document === null || !isset($this->ownTraceUids[$uid]))
			{
				continue;
			}

			unset($this->ownTraceUids[$uid]);
			$this->documentRepository->resetStoppedById((int)$document->id, $this->userId);
			$document->stoppedById = null;
		}
	}

	/**
	 * @param Item\Api\Batch\ItemResult[] $results
	 * @return array<string, Item\Api\Batch\ItemResult>
	 */
	private function indexResults(array $results): array
	{
		$itemsByUid = [];
		foreach ($results as $item)
		{
			$itemsByUid[$item->documentUid] = $item;
		}

		return $itemsByUid;
	}

	/**
	 * @param list<string> $requestedUids
	 * @param array<string, Item\Api\Batch\ItemResult> $itemsByUid
	 * @return list<string> uids of the documents that reached the target state
	 */
	private function applyItemOutcomes(array $requestedUids, array $itemsByUid): array
	{
		$successfulUids = [];
		foreach ($requestedUids as $uid)
		{
			$item = $itemsByUid[$uid] ?? null;
			if ($item === null)
			{
				// The service said nothing about the document, so its outcome is unknown too and the
				// trace stays, exactly as after an answer that could not be read.
				continue;
			}

			if (!$item->isSuccess())
			{
				// a refusal is the only answer specific enough to tell the stop did not happen
				$this->rollbackStoppedByTrace([$uid]);

				continue;
			}

			// `already_done` means the stop happened before this call, so its initiator is not the
			// user of this run: a trace left here would attribute a legally meaningful action to
			// them. The trace goes first, because the initiator of the status change is read from
			// `stoppedById`.
			if ($item->status === Type\Api\BatchItemStatus::ALREADY_DONE)
			{
				$this->rollbackStoppedByTrace([$uid]);

				// The service answers `already_done` only for a document it holds as stopped, so the
				// local status is brought to the same state. Waiting for the callback of the earlier
				// call is exactly what leaves the document in progress forever once that callback is
				// lost - and a repeated run answers `already_done` again.
				// the trace is already rolled back, so the legal log would otherwise name the user of this
				// run as the one who stopped the document
				if (
					$this->documentsByUid[$uid]->status !== Type\DocumentStatus::STOPPED
					&& !$this->stopDocument($uid, initiatorIsUnknown: true)
				)
				{
					continue;
				}

				$successfulUids[] = $uid;

				continue;
			}

			if (!$this->stopDocument($uid))
			{
				continue;
			}

			$successfulUids[] = $uid;
		}

		return $successfulUids;
	}

	/**
	 * @param bool $initiatorIsUnknown the stop came from outside this run, so the legal log must not name
	 *   the user of this run as its initiator.
	 */
	private function stopDocument(string $uid, bool $initiatorIsUnknown = false): bool
	{
		$changeStatusResult = (new ChangeDocumentStatus(
			$this->documentsByUid[$uid],
			Type\DocumentStatus::STOPPED,
			initiatorIsUnknown: $initiatorIsUnknown,
		))->launch();

		return $changeStatusResult->isSuccess();
	}

	/**
	 * @param list<string> $successfulUids
	 */
	private function createResult(array $successfulUids): Main\Result
	{
		return (new Main\Result())->setData([
			self::SUCCESSFUL_DOCUMENT_UIDS => $successfulUids,
		]);
	}
}

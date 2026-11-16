<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Integration\Bizproc\EventHandler;

use Bitrix\Main\Event;
use Bitrix\Note\Internal\Integration\Bizproc\Document\NoteDocument;
use Bitrix\Note\Internal\Integration\Bizproc\NoteDocumentCreateTrigger;
use Bitrix\Note\Internal\Integration\Bizproc\NoteDocumentMoveTrigger;
use Bitrix\Note\Internal\Integration\Bizproc\WorkflowLauncher;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Enum\WriteSource;
use Bitrix\Note\Public\Event\OnDocumentLifecycleEvent;

/**
 * Decides whether a document event has to raise a process. Subscribes to the module's own
 * published lifecycle event instead of adding calls into the write paths.
 *
 * This class knows nothing about bizproc: it decides WHETHER to launch and WHAT to hand over, while
 * {@see WorkflowLauncher} knows HOW to call the engine.
 *
 * An error is swallowed here on purpose, and \Throwable is caught rather than \Exception: with debugging
 * off the event dispatcher rethrows, and both subscribers of this event share ONE background job — so an
 * escape would also take down the neighbour (the search-by-meaning synchronisation).
 */
class OnDocumentLifecycle
{
	private readonly WorkflowLauncher $launcher;
	private readonly DocumentRepository $repository;

	public function __construct(?WorkflowLauncher $launcher = null, ?DocumentRepository $repository = null)
	{
		$this->launcher = $launcher ?? new WorkflowLauncher();
		$this->repository = $repository ?? new DocumentRepository();
	}

	/**
	 * Entry point of the subscription: the platform calls a class and a method, so the seam of the test
	 * set is the constructor of an instance built here.
	 */
	public static function onDocumentLifecycle(Event $event): void
	{
		(new self())->handle($event);
	}

	public function handle(Event $event): void
	{
		try
		{
			$this->decide($event);
		}
		catch (\Throwable)
		{
		}
	}

	private function decide(Event $event): void
	{
		// The ADR order: the cheap question first. Otherwise every document write on a portal without
		// bizproc would pay for a re-read inside a background job.
		if (!$this->launcher->isEngineAvailable())
		{
			return;
		}

		$parameters = $event->getParameters();

		// Breaking the loop: a write this very subscriber caused must not start the same process again.
		if ((string)($parameters['writeSource'] ?? '') === WriteSource::Workflow->value)
		{
			return;
		}

		$reason = (string)($parameters['reason'] ?? '');
		$contract = match ($reason)
		{
			OnDocumentLifecycleEvent::CREATED => NoteDocumentCreateTrigger::class,
			OnDocumentLifecycleEvent::MOVED => NoteDocumentMoveTrigger::class,
			default => null,
		};
		if ($contract === null)
		{
			return;
		}

		// The root is the FIRST element of the list: the subtree walk starts with it, and both the
		// publisher of the event and the bulk move keep that order.
		$documentIds = (array)($parameters['documentIds'] ?? []);
		$documentId = (int)($documentIds[0] ?? 0);

		// A null here is a rolled-back creation: the event is not bound to a commit of the transaction.
		$document = $this->repository->getMetaById($documentId, ['ID', 'COLLECTION_ID', 'PARENT_ID'], false);
		if ($document === null)
		{
			return;
		}

		// The only guard against a rolled-back move: a rollback leaves the document existing in its old
		// place, so the re-read above does not catch it. The document must lie where the move put it.
		if ($reason === OnDocumentLifecycleEvent::MOVED && !$this->isAt($document, $parameters['to'] ?? null))
		{
			return;
		}

		$initiatorId = $parameters['initiatorId'] ?? null;

		// The result is not read: refusing to log a failed launch is a decision of the BRIEF, and logging
		// is the only thing that could be done with it.
		$this->launcher->launch(
			$contract::CODE,
			$documentId,
			$this->buildEventData($contract, $documentId, $parameters, $initiatorId),
			$initiatorId === null ? null : (int)$initiatorId,
		);
	}

	/**
	 * Parameters of the event as the node will read them back: the document under the key the standard
	 * trait uses, the date as a string of the culture format, and whoever caused the event.
	 *
	 * @param array<string, mixed> $parameters
	 * @return array<string, mixed>
	 */
	private function buildEventData(
		string $contract,
		int $documentId,
		array $parameters,
		mixed $initiatorId,
	): array
	{
		$actorKey = $contract === NoteDocumentCreateTrigger::class
			? NoteDocumentCreateTrigger::FIELD_CREATED_BY
			: NoteDocumentMoveTrigger::FIELD_MOVED_BY;

		return [
			$contract::FIELD_DOCUMENT => NoteDocument::complexDocumentId($documentId),
			// Only scalars and identifiers: the data goes through storage on the delayed start, and a
			// live object comes back as an empty array. A unix timestamp rather than a formatted date for
			// the same reason of distance: the start is delayed, so the request that reads the payload is
			// not the one that wrote it, and a date formatted by the culture of the writer is parsed by
			// the culture of the reader — on a portal with sites of different cultures that gives an empty
			// date or a date with day and month swapped. CBPHelper::makeTimestamp() takes an integer as it
			// is, before it tries any format at all.
			$contract::FIELD_EVENT_DATE => (new \Bitrix\Main\Type\DateTime())->getTimestamp(),
			$actorKey => $initiatorId === null ? null : (int)$initiatorId,
		];
	}

	private function isAt(\Bitrix\Note\Internal\Model\Document $document, mixed $location): bool
	{
		if (!is_array($location))
		{
			return false;
		}

		$parentId = $document->getParentId() === null ? null : (int)$document->getParentId();
		$expectedParentId = ($location['parentId'] ?? null) === null ? null : (int)$location['parentId'];

		return (int)$document->getCollectionId() === (int)($location['collectionId'] ?? 0)
			&& $parentId === $expectedParentId
		;
	}
}

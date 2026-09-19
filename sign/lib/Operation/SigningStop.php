<?php

namespace Bitrix\Sign\Operation;

use Bitrix\Sign\Item;
use Bitrix\Sign\Item\Api\Document\Signing\StopRequest;
use Bitrix\Sign\Repository\DocumentRepository;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Type;
use Bitrix\Sign\Contract;

use Bitrix\Main;

class SigningStop implements Contract\Operation
{
	private ?Item\Document $document = null;

	public function __construct(
		private string $uid,
		private ?int $userId = null,
		private ?DocumentRepository $documentRepository = null
	)
	{
		$this->documentRepository ??= Container::instance()->getDocumentRepository();
	}

	/**
	 * Skips the document read for callers that already have it loaded; the passed item is mutated.
	 */
	public static function createByDocument(
		Item\Document $document,
		?int $userId = null,
		?DocumentRepository $documentRepository = null,
	): self
	{
		$operation = new self((string)$document->uid, $userId, $documentRepository);
		$operation->document = $document;

		return $operation;
	}

	public function launch(): Main\Result
	{
		$result = new Main\Result();
		$document = $this->document ?? $this->documentRepository->getByUid($this->uid);
		if (!$document)
		{
			return $result->addError(new Main\Error('Document not found'));
		}
		$ownTrace = $this->writeStoppedByTrace($document);

		$signingStopResponse = Container::instance()->getApiDocumentSigningService()
			->stop(
				new StopRequest($this->uid)
			)
		;

		if (!$signingStopResponse->isSuccess())
		{
			// A transport error leaves the outcome unknown: the service may have stopped the document
			// anyway, and its callback resolves the initiator from stoppedById, so the trace must stay.
			if ($ownTrace && !Type\Api\TransportErrorCode::isPresentInErrors($signingStopResponse->getErrors()))
			{
				$this->rollbackStoppedByTrace($document);
			}

			return $result->addErrors($signingStopResponse->getErrors());
		}

		$changeStatusResult = (new ChangeDocumentStatus($document, Type\DocumentStatus::STOPPED))->launch();
		if (!$changeStatusResult->isSuccess())
		{
			return $changeStatusResult;
		}

		return $result;
	}

	/**
	 * The trace is never overwritten: a document already carrying an initiator keeps it, and the write
	 * of an empty one is conditional, so of two simultaneous stops only the first owns the trace. The
	 * stop itself is asked for either way, only the attribution of it is not this run's to claim.
	 *
	 * @return bool true only when this run wrote the trace and may take it back
	 */
	private function writeStoppedByTrace(Item\Document $document): bool
	{
		if ($this->userId === null || $document->stoppedById !== null || ($document->id ?? 0) <= 0)
		{
			return false;
		}

		if (!$this->documentRepository->setStoppedByIdIfEmpty((int)$document->id, $this->userId))
		{
			// either another stop won the race or the write failed: either way the trace of this run
			// does not exist and must not be taken back later
			return false;
		}

		$document->stoppedById = $this->userId;

		return true;
	}

	/**
	 * The conditional write is taken back by a conditional reset: an item write would neither empty
	 * the field nor tell whether the trace is still the one this run left there.
	 */
	private function rollbackStoppedByTrace(Item\Document $document): void
	{
		$this->documentRepository->resetStoppedById((int)$document->id, (int)$this->userId);
		$document->stoppedById = null;
	}
}

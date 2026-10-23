<?php

namespace Bitrix\Sign\Operation;

use Bitrix\Main;
use Bitrix\Sign\Contract\Operation;
use Bitrix\Sign\Item\Document;
use Bitrix\Sign\Operation\Result\ConfigureResult;
use Bitrix\Sign\Operation\Result\FillFieldsResult;
use Bitrix\Sign\Repository\DocumentRepository;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Service\Sign\Document\DirectorSigningGuardService;
use Bitrix\Sign\Service\Sign\DocumentAgentService;
use Bitrix\Sign\Service\Sign\PlaceholderBlockService;
use Bitrix\Sign\Type\DocumentStatus;

class ConfigureFillAndStart implements Operation
{
	private readonly Document $document;
	private readonly DocumentRepository $documentRepository;
	private readonly PlaceholderBlockService $placeholderBlockService;
	private readonly DirectorSigningGuardService $directorSigningGuardService;
	private readonly DocumentAgentService $documentAgentService;

	public function __construct(
		private readonly string $uid,
		?DocumentRepository $documentRepository = null,
		?PlaceholderBlockService $placeholderBlockService = null,
		?DirectorSigningGuardService $directorSigningGuardService = null,
		?DocumentAgentService $documentAgentService = null,
	)
	{
		$container = Container::instance();
		$this->documentRepository = $documentRepository ?? $container->getDocumentRepository();
		$this->placeholderBlockService = $placeholderBlockService ?? $container->getPlaceholderBlockService();
		$this->directorSigningGuardService = $directorSigningGuardService
			?? $container->getDirectorSigningGuardService()
		;
		$this->documentAgentService = $documentAgentService ?? $container->getDocumentAgentService();
	}

	public function launch(): Main\Result|ConfigureResult
	{
		$document = $this->documentRepository->getByUid($this->uid);
		if ($document === null)
		{
			return (new Main\Result())
				->addError(new Main\Error("Document with id `$this->uid` doesnt exist"))
			;
		}
		$this->document = $document;

		if ($this->document->status === DocumentStatus::SIGNING)
		{
			return new ConfigureResult(true);
		}

		// Also checked in ConfigureDocument; a document already in the READY status skips it entirely.
		$directorSigningResult = $this->directorSigningGuardService->check($this->document);
		if (!$directorSigningResult->isSuccess())
		{
			return $directorSigningResult;
		}

		if ($this->document->status !== DocumentStatus::READY)
		{
			$createBlocksResult = $this->placeholderBlockService->createBlocksForDocument($this->document);

			if (!$createBlocksResult->isSuccess())
			{
				return $createBlocksResult;
			}

			return $this->configure();
		}

		$fillResult = (new \Bitrix\Sign\Operation\SigningService\FillFields($this->document))->launch();
		if ($fillResult instanceof FillFieldsResult)
		{
			return $this->startOrProgress($fillResult);
		}

		return $fillResult;
	}

	private function configure(): Main\Result|ConfigureResult
	{
		$result = (new ConfigureDocument($this->document->uid))->launch();
		if (!$result->isSuccess())
		{
			if ($this->isDocumentNotReady($result))
			{
				return new ConfigureResult(false);
			}

			return $result;
		}

		return new ConfigureResult(false);
	}

	private function isDocumentNotReady(Main\Result $result): bool
	{
		foreach ($result->getErrors() as $error)
		{
			if ($error->getCode() === 'DOCUMENT_INCORRECT_STATE')
			{
				return true;
			}

			// The blank goes to the signing service in a background agent, so a document of a
			// freshly created batch can still be waiting for its turn. The sending is not broken by
			// it: the configure agent comes back to the document once the blank is there.
			if ($this->isWaitingForBlankUpload($error))
			{
				return true;
			}
		}

		return false;
	}

	private function isWaitingForBlankUpload(Main\Error $error): bool
	{
		if (
			$error->getCode() !== 'SIGN_DOCUMENT_INCORRECT_STATUS'
			|| ($error->getCustomData()['has'] ?? null) !== DocumentStatus::NEW
		)
		{
			return false;
		}

		// Waiting is justified only while somebody is still sending the blanks of this group. A
		// chain that gave up removes itself, and a document outside a group never had one: the
		// sending there happens inside the request, so `new` means a failure to report. Without
		// this the configure agent would reschedule itself for good on a blank the service refuses.
		return $this->documentAgentService->hasUploadGroupBlanksChain((int)$this->document->groupId);
	}

	private function startOrProgress(FillFieldsResult $fillResult): Main\Result|ConfigureResult
	{
		if (!$fillResult->completed)
		{
			return new ConfigureResult(false);
		}

		if (!$this->getSigningStartLock())
		{
			return new ConfigureResult(false);
		}

		$startResult = (new SigningStart($this->document->uid))->launch();
		$this->releaseSigningStartLock();
		if ($startResult->isSuccess())
		{
			return new ConfigureResult(true);
		}

		return $startResult;
	}

	private function getSigningStartLock(): bool
	{
		return Main\Application::getConnection()->lock($this->getLockName());
	}

	private function releaseSigningStartLock(): bool
	{
		return Main\Application::getConnection()->unlock($this->getLockName());
	}

	private function getLockName(): string
	{
		return "sign_signing_start_{$this->document->uid}";
	}
}

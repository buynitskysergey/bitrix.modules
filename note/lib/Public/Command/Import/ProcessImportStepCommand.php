<?php

declare(strict_types=1);

namespace Bitrix\Note\Public\Command\Import;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Note\Internal\Service\Import\ImportService;
use Bitrix\Note\Internal\Service\Import\Source\LandingSource;
use Bitrix\Note\Internal\Service\Import\Source\OutlineSource;
use Bitrix\Note\Internal\Service\Import\Source\SourceInterface;
use Bitrix\Note\Internal\Service\Import\Source\WikiSource;

class ProcessImportStepCommand extends AbstractCommand
{
	private array $option;
	private ?ImportService $importService;

	public function __construct(
		array $option,
		?ImportService $importService = null,
	)
	{
		$this->option = $option;
		$this->importService = $importService;
	}

	protected function execute(): Result
	{
		$source = $this->createSource();
		$service = $this->importService ?? new ImportService();

		$shouldContinue = $service->processStep($this->option, $source);

		$doneCount = (int)($this->option['doneCount'] ?? 0);

		return new ProcessImportStepResult($this->option, $doneCount, $shouldContinue);
	}

	private function createSource(): SourceInterface
	{
		$sourceType = $this->option['sourceType'] ?? '';
		$sourceUrl = $this->option['sourceUrl'] ?? '';
		$sourceToken = $this->option['sourceToken'] ?? '';
		$userId = (int)($this->option['userId'] ?? 0);

		return match ($sourceType)
		{
			'outline' => new OutlineSource($sourceUrl, $sourceToken),
			'landing' => new LandingSource($userId, collectionId: $this->currentCollectionId()),
			'wiki' => new WikiSource($userId),
			default => throw new \Bitrix\Main\SystemException('Unsupported source type: ' . $sourceType),
		};
	}

	/**
	 * The base this step of the conversion is at. A source that copies files of the portal needs it as
	 * the boundary of what it may copy ({@see LandingSource::downloadAttachment}), and the state of the
	 * conversion is where the base of the step is named - one base at a time, in the order the initiator
	 * selected them.
	 */
	private function currentCollectionId(): ?string
	{
		$collectionIds = (array)($this->option['collectionIds'] ?? []);
		$collectionIndex = (int)($this->option['collectionIndex'] ?? 0);
		$collectionId = $collectionIds[$collectionIndex] ?? null;

		return $collectionId !== null ? (string)$collectionId : null;
	}
}

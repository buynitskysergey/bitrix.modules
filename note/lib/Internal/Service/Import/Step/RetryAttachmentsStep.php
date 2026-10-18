<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Import\Step;

use Bitrix\Main\Localization\Loc;
use Bitrix\Note\Internal\Service\Document\DocumentService;
use Bitrix\Note\Internal\Service\Import\ImportFileService;
use Bitrix\Note\Internal\Service\Import\ImportLogger;
use Bitrix\Note\Internal\Service\Import\Source\SourceInterface;
use Bitrix\Note\Internal\Service\Import\Transformer\ImportMdTransformer;
use Bitrix\Note\Internal\Service\Import\Transformer\MdTransformerFactory;

class RetryAttachmentsStep implements StepInterface
{
	/**
	 * @var array<int, array{markdown: string}>
	 */
	private array $docCache = [];

	public function __construct(
		private readonly DocumentService $documentService,
		private readonly ImportFileService $fileService,
	)
	{
	}

	public function execute(array &$option, ?SourceInterface $source): void
	{
		$failed = $option['failedAttachments'] ?? [];
		unset($option['failedAttachments']);

		if (empty($failed))
		{
			$option['step'] = 'reconcile';

			return;
		}

		$userId = (int)$option['userId'];
		$mdTransformer = MdTransformerFactory::create($option['sourceType']);
		$retrySuccessByDoc = [];
		$retryCount = count($failed);
		$successCount = 0;

		$docIds = $this->documentIds($failed);
		$this->preloadDocuments($docIds);

		foreach ($failed as $entry)
		{
			$docId = (int)$entry['docId'];
			$attachmentId = $entry['attachmentId'];

			$downloadResult = $source->downloadAttachment($attachmentId);
			if (!$downloadResult->success)
			{
				ImportLogger::logError("Retry download failed: attachmentId={$attachmentId}, docId={$docId}");
				ImportLogger::addErrorDetail(
					$option,
					$attachmentId,
					Loc::getMessage('NOTE_IMPORT_ERROR_ATTACHMENT_DOWNLOAD_FAILED'),
				);

				continue;
			}

			// A landing-local payload carries the source file id and nothing else: the note-owned copy
			// is what turns it into a file the document may point at, so the retry repeats the copy the
			// download step failed at instead of persisting the payload and linking the landing row.
			// copyFromLanding() links the copy to the document itself - no linkFileToDocument() here.
			if (!empty($downloadResult->data['inLanding']))
			{
				$sourceFileId = (int)($downloadResult->data['fileId'] ?? 0);
				$newFileId = $this->fileService->copyFromLanding($sourceFileId, $docId, $userId);
				if ($newFileId === null)
				{
					ImportLogger::logError("Retry landing copy failed: sourceFileId={$sourceFileId}, docId={$docId}");
					ImportLogger::addErrorDetail(
						$option,
						(string)$attachmentId,
						Loc::getMessage('NOTE_IMPORT_ERROR_ATTACHMENT_SAVE_FAILED'),
					);

					continue;
				}

				$meta = $this->fileService->describeLocalFile($newFileId);
				$retrySuccessByDoc[$docId][$attachmentId] = [
					'fileId' => $newFileId,
					'name' => $meta['name'],
					'size' => $meta['size'],
					'mimeType' => $meta['mimeType'],
				];
				$option['doneAttachments'] = ($option['doneAttachments'] ?? 0) + 1;
				$successCount++;

				continue;
			}

			$fileName = $downloadResult->data['fileName'];
			$contentType = $downloadResult->data['contentType'];
			$size = $downloadResult->data['size'];

			$fileId = $this->fileService->persistAttachment($downloadResult->data);
			if ($fileId === null)
			{
				ImportLogger::logError("Retry save failed: attachmentId={$attachmentId}, docId={$docId}, fileName={$fileName}");
				ImportLogger::addErrorDetail(
					$option,
					$fileName,
					Loc::getMessage('NOTE_IMPORT_ERROR_ATTACHMENT_SAVE_FAILED'),
				);

				continue;
			}

			$this->fileService->linkFileToDocument($docId, $fileId, $userId);
			$retrySuccessByDoc[$docId][$attachmentId] = [
				'fileId' => $fileId,
				'name' => $fileName,
				'size' => $size,
				'mimeType' => $contentType,
			];
			$option['doneAttachments'] = ($option['doneAttachments'] ?? 0) + 1;
			$successCount++;
		}

		$this->rewriteDocuments($docIds, $retrySuccessByDoc, $mdTransformer);

		ImportLogger::logInfo("retryAttachments: {$successCount}/{$retryCount} recovered");
		$option['step'] = 'reconcile';
	}

	/**
	 * Writes the final markdown of every document this step looked at: what the retry recovered becomes
	 * an asset token, and whatever is still unresolved loses its internal placeholder. Documents whose
	 * retry brought nothing back are rewritten too - this is the last step of the attachment chain, so a
	 * placeholder left in the text now is a placeholder the reader gets.
	 *
	 * @param int[] $docIds
	 * @param array<int, array<string, array{fileId: int, name: string, size: int, mimeType: string}>> $recoveredByDoc
	 */
	private function rewriteDocuments(
		array $docIds,
		array $recoveredByDoc,
		ImportMdTransformer $mdTransformer,
	): void
	{
		foreach ($docIds as $docId)
		{
			$markdown = $this->docCache[$docId]['markdown'] ?? '';
			if ($markdown === '')
			{
				continue;
			}

			$updated = isset($recoveredByDoc[$docId])
				? $mdTransformer->transform($markdown, $docId, $recoveredByDoc[$docId])->markdown
				: $markdown
			;
			$updated = $mdTransformer->unwrapUnresolvedAttachments($updated);

			if ($updated !== $markdown)
			{
				$this->documentService->setMarkdown($docId, $updated);
			}
		}
	}

	/**
	 * @param array<int, array{docId: int|string, attachmentId: mixed}> $failed
	 * @return int[]
	 */
	private function documentIds(array $failed): array
	{
		$docIds = [];
		foreach ($failed as $entry)
		{
			$id = (int)$entry['docId'];
			if ($id > 0)
			{
				$docIds[$id] = true;
			}
		}

		return array_keys($docIds);
	}

	/**
	 * @param int[] $docIds
	 */
	private function preloadDocuments(array $docIds): void
	{
		if (empty($docIds))
		{
			return;
		}

		$rows = $this->documentService->findByIds($docIds, ['ID', 'MARKDOWN']);
		foreach ($rows as $id => $row)
		{
			$this->docCache[$id] = [
				'markdown' => is_string($row['MARKDOWN']) ? $row['MARKDOWN'] : '',
			];
		}
	}
}

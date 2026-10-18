<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Import\Step;

use Bitrix\Note\Internal\Service\Document\DocumentService;
use Bitrix\Note\Internal\Service\Import\ImportFileService;
use Bitrix\Note\Internal\Service\Import\ImportLogger;
use Bitrix\Note\Internal\Service\Import\Source\SourceInterface;
use Bitrix\Note\Internal\Service\Import\Transformer\MdTransformerFactory;

class DownloadAttachmentsStep implements StepInterface
{
	private const ATTACHMENT_FILE_LIMIT = 5;
	private const ATTACHMENT_BYTE_LIMIT = 20 * 1024 * 1024;

	public function __construct(
		private readonly DocumentService $documentService,
		private readonly ImportFileService $fileService,
	)
	{
	}

	public function execute(array &$option, ?SourceInterface $source): void
	{
		$importedDocIds = $option['importedDocIds'] ?? [];
		$docIndex = $option['attachmentDocIndex'] ?? 0;
		$userId = (int)$option['userId'];
		$mdTransformer = MdTransformerFactory::create($option['sourceType']);

		$filesInStep = 0;
		$bytesInStep = 0;

		while ($docIndex < count($importedDocIds))
		{
			$docId = $importedDocIds[$docIndex];
			$rows = $this->documentService->findByIds([$docId], ['ID', 'MARKDOWN']);
			$row = $rows[$docId] ?? null;
			if ($row === null)
			{
				$docIndex++;

				continue;
			}

			$markdown = $row['MARKDOWN'];
			if (!is_string($markdown) || $markdown === '')
			{
				$docIndex++;

				continue;
			}

			$attachmentIds = $mdTransformer->extractAttachmentIds($markdown);
			$attachmentIds = $this->filterOutFailed($attachmentIds, $docId, $option['failedAttachments'] ?? []);
			if (empty($attachmentIds))
			{
				$docIndex++;

				continue;
			}

			$attachmentFileMap = [];
			$currentFileIndex = 0;

			$interrupted = false;

			while ($currentFileIndex < count($attachmentIds))
			{
				if ($filesInStep >= self::ATTACHMENT_FILE_LIMIT || $bytesInStep >= self::ATTACHMENT_BYTE_LIMIT)
				{
					$interrupted = true;

					break;
				}

				$attachmentId = $attachmentIds[$currentFileIndex];
				$downloadResult = $source->downloadAttachment($attachmentId);

				if ($downloadResult->success && ($downloadResult->data['inLanding'] ?? false))
				{
					$sourceFileId = (int)($downloadResult->data['fileId'] ?? 0);
					$newFileId = $this->fileService->copyFromLanding($sourceFileId, $docId, $userId);
					if ($newFileId !== null)
					{
						$meta = $this->fileService->describeLocalFile($newFileId);
						$attachmentFileMap[$attachmentId] = [
							'fileId' => $newFileId,
							'name' => $meta['name'],
							'size' => $meta['size'],
							'mimeType' => $meta['mimeType'],
						];
						$option['doneAttachments'] = ($option['doneAttachments'] ?? 0) + 1;
						$bytesInStep += $meta['size'];
					}
					else
					{
						ImportLogger::logError("Landing attachment copy failed: sourceFileId={$sourceFileId}, docId={$docId}");
						$option['failedAttachments'][] = ['docId' => $docId, 'attachmentId' => $attachmentId];
					}
				}
				elseif ($downloadResult->success)
				{
					$fileName = $downloadResult->data['fileName'];
					$contentType = $downloadResult->data['contentType'];
					$size = $downloadResult->data['size'];

					$fileId = $this->fileService->persistAttachment($downloadResult->data);
					if ($fileId !== null)
					{
						$this->fileService->linkFileToDocument($docId, $fileId, $userId);
						$attachmentFileMap[$attachmentId] = [
							'fileId' => $fileId,
							'name' => $fileName,
							'size' => $size,
							'mimeType' => $contentType,
						];
						$option['doneAttachments'] = ($option['doneAttachments'] ?? 0) + 1;
					}
					else
					{
						ImportLogger::logError("Attachment save failed: attachmentId={$attachmentId}, docId={$docId}, fileName={$fileName}");
						$option['failedAttachments'][] = ['docId' => $docId, 'attachmentId' => $attachmentId];
					}

					$bytesInStep += $size;
				}
				elseif (isset($downloadResult->data['retryAfter']))
				{
					$interrupted = true;

					break;
				}
				else
				{
					ImportLogger::logError("Attachment download failed: attachmentId={$attachmentId}, docId={$docId}, error=" . ($downloadResult->error ?? 'unknown'));
					$option['failedAttachments'][] = ['docId' => $docId, 'attachmentId' => $attachmentId];
				}

				$filesInStep++;
				$currentFileIndex++;
			}

			if (!empty($attachmentFileMap))
			{
				$transformResult = $mdTransformer->transform($markdown, $docId, $attachmentFileMap);
				$this->documentService->setMarkdown($docId, $transformResult->markdown);
			}

			if ($interrupted)
			{
				$option['attachmentDocIndex'] = $docIndex;

				return;
			}

			$docIndex++;
		}

		$option['attachmentDocIndex'] = $docIndex;

		$failedCount = count($option['failedAttachments'] ?? []);
		if ($failedCount > 0)
		{
			ImportLogger::logInfo("downloadAttachments: {$failedCount} failed, scheduling retry");
			$option['step'] = 'retryAttachments';
		}
		else
		{
			$option['step'] = 'reconcile';
		}
	}

	/**
	 * @param string[] $attachmentIds
	 * @param array<int, array{docId: int|string, attachmentId: mixed}> $failedAttachments
	 * @return string[]
	 */
	private function filterOutFailed(array $attachmentIds, int $docId, array $failedAttachments): array
	{
		if (empty($failedAttachments))
		{
			return $attachmentIds;
		}

		$failedForDoc = [];
		foreach ($failedAttachments as $entry)
		{
			if ((int)($entry['docId'] ?? 0) === $docId)
			{
				$failedForDoc[(string)($entry['attachmentId'] ?? '')] = true;
			}
		}

		if (empty($failedForDoc))
		{
			return $attachmentIds;
		}

		return array_values(array_filter(
			$attachmentIds,
			static fn (string $id): bool => !isset($failedForDoc[$id]),
		));
	}
}

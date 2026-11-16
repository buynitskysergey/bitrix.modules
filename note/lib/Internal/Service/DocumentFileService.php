<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service;

use Bitrix\Main\IO\Path;
use Bitrix\Main\UI\Viewer\ItemAttributes;
use Bitrix\Note\Internal\Access\Service\DocumentAccessService;
use Bitrix\Note\Internal\Exceptions\FileTooLargeException;
use Bitrix\Note\Internal\Exceptions\FileTypeNotAllowedException;
use Bitrix\Note\Internal\Repository\DocumentFileLinkRepository;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Service\File\ImagePreviewService;
use Bitrix\Note\Internal\Util\IdNormalizer;

class DocumentFileService
{
	private const NOTE_MODULE_ID = 'note';
	private const NOTE_FILE_SUBDIR_PREFIX = 'note/editor';

	// Conservative whitelist for the write API: images we already preview natively
	// + common documents. Extensions enforced before MIME (CFile picks MIME by
	// extension after SaveFile, so the extension is the source of truth here).
	private const ALLOWED_EXTENSIONS = [
		'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg',
		'pdf', 'txt', 'md', 'csv',
		'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
		'mp4', 'webm', 'mov',
	];

	public function assertValidUpload(string $binary, string $fileName): void
	{
		$this->assertValidNoteFileSize(strlen($binary));
		$this->assertValidNoteFileExtension($fileName);
	}

	public function assertValidNoteFileSize(int $bytes): void
	{
		$max = self::getMaxNoteFileSize();
		if ($max > 0 && $bytes > $max)
		{
			throw new FileTooLargeException();
		}
	}

	public static function getMaxNoteFileSize(): int
	{
		// Reuse main.max_file_size (in KB). Falls back to a safe 25 MiB cap so
		// REST never accepts unbounded uploads even when the option is unset.
		$kb = (int)\Bitrix\Main\Config\Option::get('main', 'max_file_size', 0);
		if ($kb > 0)
		{
			return $kb * 1024;
		}

		return 25 * 1024 * 1024;
	}

	public function assertValidNoteFileExtension(string $fileName): void
	{
		$ext = strtolower(Path::getExtension($fileName));
		if ($ext === '' || !in_array($ext, self::ALLOWED_EXTENSIONS, true))
		{
			throw new FileTypeNotAllowedException();
		}
	}

	public function extractFileIds(array $markdown): array
	{
		$ids = [];
		$this->collectFileIds($markdown, $ids);

		return IdNormalizer::normalize($ids);
	}

	public function getValidatedNoteFile(int $fileId): ?array
	{
		if ($fileId <= 0)
		{
			return null;
		}

		$fileData = \CFile::GetFileArray($fileId);
		if (!is_array($fileData))
		{
			return null;
		}

		if (!$this->isNoteFileData($fileData))
		{
			return null;
		}

		return $fileData;
	}

	public function getValidatedNoteFileIds(array $fileIds): array
	{
		$normalizedFileIds = IdNormalizer::normalize($fileIds);
		if (empty($normalizedFileIds))
		{
			return [];
		}

		$validFileIds = [];
		$rows = \CFile::GetList(arFilter: ['@ID' => $normalizedFileIds]);
		while ($row = $rows->Fetch())
		{
			if (!is_array($row))
			{
				continue;
			}

			$fileId = (int)($row['ID'] ?? 0);
			if ($fileId <= 0)
			{
				continue;
			}

			if (!$this->isNoteFileData($row))
			{
				continue;
			}

			$validFileIds[$fileId] = true;
		}

		return array_values(array_filter(
			$normalizedFileIds,
			static fn(int $fileId): bool => isset($validFileIds[$fileId]),
		));
	}

	/**
	 * Batch sibling of getValidatedNoteFile: one CFile::GetList for the whole set instead of a
	 * per-file GetFileArray. Returns the raw b_file rows (FILE_SIZE / ORIGINAL_NAME included) keyed
	 * by id, keeping only note-owned files.
	 *
	 * @param int[] $fileIds
	 * @return array<int, array<string, mixed>>
	 */
	public function getValidatedNoteFilesMap(array $fileIds): array
	{
		$normalizedFileIds = IdNormalizer::normalize($fileIds);
		if (empty($normalizedFileIds))
		{
			return [];
		}

		$map = [];
		$rows = \CFile::GetList(arFilter: ['@ID' => $normalizedFileIds]);
		while ($row = $rows->Fetch())
		{
			if (!is_array($row))
			{
				continue;
			}

			$fileId = (int)($row['ID'] ?? 0);
			if ($fileId <= 0 || !$this->isNoteFileData($row))
			{
				continue;
			}

			$map[$fileId] = $row;
		}

		return $map;
	}

	public function isNoteOwnedFile(int $fileId): bool
	{
		return $this->getValidatedNoteFile($fileId) !== null;
	}

	/**
	 * Builds the client payload for a note file (URLs, name, size, mime, viewer attrs).
	 * Single source of truth shared by upload / resolve / adopt. Returns null when the
	 * file has no resolvable show URL (deleted/unknown).
	 *
	 * The addresses may be supplied by the caller, and one caller does: the public circuit serves the
	 * same file through its own token-less actions. Everything else about the payload - the
	 * name, the size, the viewer attributes - is identical there, and building a second copy of it
	 * would mean a client parsing two shapes of one thing.
	 *
	 * The dimensions are those of b_file as they are, 0 where it has none. A picture large enough to
	 * have a reduced copy also gets the address of the copy and its largest side.
	 *
	 * @param array<string, mixed> $fallback used only where the row of b_file carries nothing
	 * @param array<string, mixed>|null $fileData the row of b_file the caller has already read
	 */
	public function buildFilePayload(
		int $fileId,
		array $fallback = [],
		?string $showUrl = null,
		?string $downloadUrl = null,
		?array $fileData = null,
		?string $previewUrl = null,
	): ?array
	{
		// The row of b_file may come from the caller: one that read a whole set at once already holds
		// it, and reading it again per file is exactly what the batch reader exists to avoid. The
		// fallback stays what it was - values used only where the row says nothing.
		$fileData ??= \CFile::GetFileArray($fileId) ?: [];
		// The address of the copy defaults to the ordinary circuit only with the show address: a caller
		// that serves the file elsewhere and names no copy gets none rather than a link of another circuit.
		$isOrdinaryCircuit = $showUrl === null;
		$showUrl ??= NoteFileUrlService::createShowUrl($fileId);
		if ($showUrl === '')
		{
			return null;
		}

		$downloadUrl = ($downloadUrl === null || $downloadUrl === '') ? $showUrl : $downloadUrl;

		$fileName = (string)($fileData['ORIGINAL_NAME'] ?? $fallback['name'] ?? '');
		// The row in hand is the row the viewer attributes are built from: asking by id reads b_file
		// again, once per file, which is the very thing a batch reader exists to avoid.
		$viewerAttributes = ($fileData === []
			? ItemAttributes::tryBuildByFileId($fileId, $showUrl)
			: ItemAttributes::tryBuildByFileData($fileData, $showUrl)
		)
			->setTitle($fileName)
		;

		$payload = [
			'id' => $fileId,
			'fileId' => $fileId,
			'name' => $fileName,
			'size' => (int)($fileData['FILE_SIZE'] ?? $fallback['size'] ?? 0),
			'type' => (string)($fileData['CONTENT_TYPE'] ?? $fallback['type'] ?? ''),
			'downloadUrl' => $downloadUrl,
			'showUrl' => $showUrl,
			'viewerAttrs' => $viewerAttributes->toDataSet(),
			'width' => (int)($fileData['WIDTH'] ?? 0),
			'height' => (int)($fileData['HEIGHT'] ?? 0),
		];

		if ((new ImagePreviewService())->needsPreview($fileData))
		{
			$previewUrl ??= $isOrdinaryCircuit ? NoteFileUrlService::createShowUrl($fileId, preview: true) : null;
			if ($previewUrl !== null)
			{
				$payload['previewUrl'] = $previewUrl;
				$payload['previewWidth'] = ImagePreviewService::MAX_SIDE;
			}
		}

		return $payload;
	}

	/**
	 * Resolves a set of fileIds for a target document, cloning files that belong to a
	 * different document into the target so cross-document copy/paste keeps working.
	 *
	 * Gate: VIEW on the source document (callers gate EDIT on the target). A file already
	 * linked to the target is resolved in place; a file linked elsewhere is physically
	 * copied (\CFile::CopyFile), linked to the target, and reported with originalFileId so
	 * the client can remap the node's id.
	 *
	 * @return array{files: array<int, array>, failed: array<int, array{fileId:int, reason:string}>}
	 */
	public function adoptFiles(int $targetDocumentId, array $fileIds, int $userId): array
	{
		$files = [];
		$failed = [];

		$normalizedFileIds = IdNormalizer::normalize($fileIds);
		if ($targetDocumentId <= 0 || $userId <= 0 || empty($normalizedFileIds))
		{
			return ['files' => $files, 'failed' => $failed];
		}

		$linkRepository = new DocumentFileLinkRepository();
		$documentRepository = new DocumentRepository();
		$sourceViewCache = [];

		// One query resolves every owning document up front: a file links to a single
		// document (the link repository enforces this), so this map decides all three branches —
		// already-linked-here, borrowed-from-elsewhere, or orphan.
		$linksByFileId = $linkRepository->getLinksByFileIds($normalizedFileIds);

		foreach ($normalizedFileIds as $fileId)
		{
			if ($this->getValidatedNoteFile($fileId) === null)
			{
				$failed[] = ['fileId' => $fileId, 'reason' => 'file_source'];

				continue;
			}

			$sourceDocumentId = (int)($linksByFileId[$fileId] ?? 0);

			if ($sourceDocumentId === $targetDocumentId)
			{
				$payload = $this->buildFilePayload($fileId);
				if ($payload === null)
				{
					$failed[] = ['fileId' => $fileId, 'reason' => 'not_found'];

					continue;
				}
				$payload['originalFileId'] = $fileId;
				$files[] = $payload;

				continue;
			}

			if ($sourceDocumentId <= 0)
			{
				$failed[] = ['fileId' => $fileId, 'reason' => 'not_linked'];

				continue;
			}

			if (!($sourceViewCache[$sourceDocumentId] ??= $this->canViewDocument($sourceDocumentId, $documentRepository)))
			{
				$failed[] = ['fileId' => $fileId, 'reason' => 'access'];

				continue;
			}

			$newFileId = (int)\CFile::CopyFile($fileId);
			if ($newFileId <= 0)
			{
				$failed[] = ['fileId' => $fileId, 'reason' => 'copy'];

				continue;
			}

			$linkResult = $linkRepository->link($targetDocumentId, $newFileId, $userId);
			if (!$linkResult->isSuccess())
			{
				\CFile::Delete($newFileId);
				$failed[] = ['fileId' => $fileId, 'reason' => 'link'];

				continue;
			}

			$payload = $this->buildFilePayload($newFileId);
			if ($payload === null)
			{
				$failed[] = ['fileId' => $fileId, 'reason' => 'not_found'];

				continue;
			}
			$payload['originalFileId'] = $fileId;
			$files[] = $payload;
		}

		return ['files' => $files, 'failed' => $failed];
	}

	private function canViewDocument(int $documentId, DocumentRepository $documentRepository): bool
	{
		if ($documentId <= 0)
		{
			return false;
		}

		$document = $documentRepository->getById($documentId);
		if ($document === null)
		{
			return false;
		}

		return DocumentAccessService::currentUserCanViewDocumentContent(
			$documentId,
			(int)$document->getCollectionId(),
		);
	}

	private function isNoteFileData(array $fileData): bool
	{
		$moduleId = strtolower((string)($fileData['MODULE_ID'] ?? ''));

		return $moduleId === self::NOTE_MODULE_ID;
	}

	private function collectFileIds(array $node, array &$ids): void
	{
		$attrs = $node['attrs'] ?? null;
		if (is_array($attrs) && isset($attrs['fileId']))
		{
			$ids[] = (int)$attrs['fileId'];
		}

		$content = $node['content'] ?? null;
		if (!is_array($content))
		{
			return;
		}

		foreach ($content as $child)
		{
			if (is_array($child))
			{
				$this->collectFileIds($child, $ids);
			}
		}
	}
}

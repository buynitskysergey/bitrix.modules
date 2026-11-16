<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Rest\V3\Controller;

use Bitrix\Main\FileTable;
use Bitrix\Main\IO;
use Bitrix\Main\SystemException;
use Bitrix\Note\Infrastructure\Rest\V3\Access\DocumentIntent;
use Bitrix\Note\Infrastructure\Rest\V3\Access\DocumentStateResolver;
use Bitrix\Note\Infrastructure\Rest\V3\Controller\ActionFilter\NoteRestAccess;
use Bitrix\Note\Infrastructure\Rest\V3\Dto\FileCursorDto;
use Bitrix\Note\Infrastructure\Rest\V3\Dto\FileItemDto;
use Bitrix\Note\Infrastructure\Rest\V3\Exceptions\FileTooLargeException;
use Bitrix\Note\Infrastructure\Rest\V3\Exceptions\FileTypeNotAllowedException;
use Bitrix\Note\Infrastructure\Rest\V3\Request\AddFileRequest;
use Bitrix\Note\Infrastructure\Rest\V3\Request\GetFileRequest;
use Bitrix\Note\Infrastructure\Rest\V3\Request\ListFilesRequest;
use Bitrix\Note\Infrastructure\Rest\V3\Response\FileListResponse;
use Bitrix\Note\Internal\Exceptions\FileTooLargeException as DomainFileTooLargeException;
use Bitrix\Note\Internal\Exceptions\FileTypeNotAllowedException as DomainFileTypeNotAllowedException;
use Bitrix\Note\Internal\Repository\DocumentFileLinkRepository;
use Bitrix\Note\Internal\Service\Analytics\AnalyticsDictionary;
use Bitrix\Note\Internal\Service\Analytics\AnalyticsService;
use Bitrix\Note\Internal\Service\DocumentFileService;
use Bitrix\Rest\V3\Attribute\Description;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Attribute\Idempotent;
use Bitrix\Rest\V3\Attribute\Title;
use Bitrix\Rest\V3\Controller\RestController;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Exception\EntityNotFoundException;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;
use Bitrix\Main\Error;
use Bitrix\Rest\V3\Interaction\Response\GetResponse;

#[DtoType(FileItemDto::class)]
class File extends RestController
{
	use CurrentUserAware;

	private ?DocumentStateResolver $stateResolver = null;

	protected function getDefaultPreFilters(): array
	{
		return [
			...parent::getDefaultPreFilters(),
			new NoteRestAccess(),
		];
	}

	#[Idempotent]
	#[Title('Attach a file to a document')]
	#[Description(
		'Uploads a file and links it to a document. The content is passed as base64. The answer '
		. 'carries `assetMarkdown` - the fragment to paste into the document text so that the file '
		. 'shows up in it; an image becomes a picture, anything else becomes a link. Attaching is a '
		. 'write on the document, so an archived or trashed one is out of reach.'
	)]
	public function addAction(AddFileRequest $request): GetResponse
	{
		// Attaching a file is a write on the document, so an archived or trashed one is out of reach -
		// the same rule the document methods answer by.
		$this->getStateResolver()->resolve(
			$request->documentId,
			$this->getCurrentUserId(),
			DocumentIntent::Write,
		);

		$binary = base64_decode($request->fileContent, strict: true);
		if ($binary === false || $binary === '')
		{
			throw new RequestValidationException([new Error('invalid base64 payload', 'fileContent')]);
		}

		$fileService = new DocumentFileService();
		try
		{
			$fileService->assertValidUpload($binary, $request->fileName);
		}
		catch (DomainFileTooLargeException)
		{
			throw new FileTooLargeException(DocumentFileService::getMaxNoteFileSize());
		}
		catch (DomainFileTypeNotAllowedException)
		{
			throw new FileTypeNotAllowedException();
		}

		$mime = $this->detectMime($binary, $request->fileName);

		// Write to bxtemp first — SaveFile reads through cloud-storage handlers
		// that require an on-disk tmp_name, not just an in-memory 'content' key.
		$tmpName = \CTempFile::GetFileName(IO\Path::getName($request->fileName));
		\CheckDirPath($tmpName);
		IO\File::putFileContents($tmpName, $binary);

		$fileArray = [
			'name' => IO\Path::getName($request->fileName),
			'type' => $mime,
			'tmp_name' => $tmpName,
			'size' => strlen($binary),
			'MODULE_ID' => 'note',
		];

		$fileId = (int)\CFile::SaveFile($fileArray, 'note/editor');
		if ($fileId <= 0)
		{
			// The request was fine and the storage was not: answering "invalid input" would send the
			// caller looking for a mistake in their own payload.
			throw new SystemException('Failed to persist the file');
		}

		// A file nobody attached is a file nobody can reach: `file.get` answers about it through the link
		// and would report the id we have just returned as missing. Better to fail the call than to hand
		// back an item that leads nowhere.
		$link = (new DocumentFileLinkRepository())->link(
			$request->documentId,
			$fileId,
			$this->getCurrentUserId(),
		);
		if (!$link->isSuccess())
		{
			\CFile::Delete($fileId);

			throw new SystemException('Failed to attach the file to the document');
		}

		// Analytics size unit is megabytes with 2 decimals — same formula as the bk path
		// (EditorUploaderController::onUploadComplete). Best-effort: core swallows failures.
		$sizeMb = round(strlen($binary) / (1024 * 1024), 2);
		AnalyticsService::fileAdded($sizeMb, true, AnalyticsDictionary::TYPE_REST);

		return new GetResponse($this->buildFileDto($fileId, $request->documentId));
	}

	#[Title('One attached file by its id')]
	#[Description(
		'Reads the record of a file attached to a document: its name, size, type and the markdown '
		. 'fragment that embeds it. The binary itself is not returned. Both the file id and the '
		. 'document it hangs on are required - a file that belongs to another document is answered as '
		. 'a missing one.'
	)]
	public function getAction(GetFileRequest $request): GetResponse
	{
		// Reading a file follows the document: an archived or trashed document the caller may open is
		// still readable, one they may not see answers as missing.
		$this->getStateResolver()->resolve(
			$request->documentId,
			$this->getCurrentUserId(),
			DocumentIntent::Read,
		);

		if (!(new DocumentFileLinkRepository())->isLinked($request->documentId, $request->id))
		{
			throw new EntityNotFoundException($request->id);
		}

		return new GetResponse($this->buildFileDto($request->id, $request->documentId));
	}

	// Title and description live in FileListMethodProvider: the envelope of a list method is
	// hand-written there anyway, and two sources of the same text drift apart.
	public function listAction(ListFilesRequest $request): FileListResponse
	{
		// Reading the attachments is a read of the document: the same question, asked the same way
		// as by `note.file.get`.
		$this->getStateResolver()->resolve(
			$request->documentId,
			$this->getCurrentUserId(),
			DocumentIntent::Read,
		);

		$limit = $request->getLimit();
		$links = (new DocumentFileLinkRepository())->listByDocument(
			$request->documentId,
			$limit + 1,
			$request->getAfterFileId(),
		);

		$hasMore = count($links) > $limit;
		$page = $hasMore ? array_slice($links, 0, $limit) : $links;

		$files = $this->readFileRows(array_map(static fn(array $link): int => (int)$link['FILE_ID'], $page));

		$items = new DtoCollection(FileItemDto::class);
		foreach ($page as $link)
		{
			// A link whose file row is gone is skipped rather than answered as a missing entity: one
			// dangling row would otherwise make the whole list of the document unreadable.
			$file = $files[(int)$link['FILE_ID']] ?? null;
			if ($file === null)
			{
				continue;
			}

			$dto = $this->fileDto($file, $request->documentId);
			$dto->createdAt = $link['CREATED_AT'];
			$items->add($dto);
		}

		// The cursor follows the last row of the page and not the last item answered, so that a
		// skipped dangling link is not read again on the next page.
		return new FileListResponse($items, $hasMore ? $this->buildCursor($page) : null);
	}

	private function buildCursor(array $page): FileCursorDto
	{
		$dto = new FileCursorDto();
		$dto->id = $page[array_key_last($page)]['FILE_ID'];

		return $dto;
	}

	private function buildFileDto(int $fileId, int $documentId): FileItemDto
	{
		$dto = $this->findFileDto($fileId, $documentId);
		if ($dto === null)
		{
			throw new EntityNotFoundException($fileId);
		}

		return $dto;
	}

	private function findFileDto(int $fileId, int $documentId): ?FileItemDto
	{
		$file = $this->readFileRows([$fileId])[$fileId] ?? null;

		return $file === null ? null : $this->fileDto($file, $documentId);
	}

	/**
	 * The rows behind a page of attachments, in one read. The answer describes the files and never
	 * links to them, so the columns are all it takes - and \CFile::GetFileArray() per row cost a
	 * bucket of the file cache, or a query on a cold one, for every attachment of the page.
	 *
	 * @param int[] $fileIds
	 * @return array<int, array<string, mixed>> rows by file id, the missing ones simply absent
	 */
	private function readFileRows(array $fileIds): array
	{
		$ids = array_values(array_unique(array_filter($fileIds, static fn(int $id): bool => $id > 0)));
		if ($ids === [])
		{
			return [];
		}

		$rows = FileTable::query()
			->setSelect(['ID', 'ORIGINAL_NAME', 'FILE_NAME', 'FILE_SIZE', 'CONTENT_TYPE'])
			->whereIn('ID', $ids)
			->fetchAll()
		;

		$byId = [];
		foreach ($rows as $row)
		{
			$byId[(int)$row['ID']] = $row;
		}

		return $byId;
	}

	/**
	 * @param array<string, mixed> $file
	 */
	private function fileDto(array $file, int $documentId): FileItemDto
	{
		$fileId = (int)$file['ID'];
		$mime = (string)($file['CONTENT_TYPE'] ?? '');
		$assetType = $this->resolveAssetType($mime);

		$dto = new FileItemDto();
		$dto->id = $fileId;
		$dto->documentId = $documentId;
		$dto->name = (string)($file['ORIGINAL_NAME'] ?? '') ?: (string)($file['FILE_NAME'] ?? '');
		$dto->size = (int)($file['FILE_SIZE'] ?? 0);
		$dto->mimeType = $mime;
		$dto->assetType = $assetType;
		$dto->assetMarkdown = "[[{$assetType} fileId={$fileId}]]";

		return $dto;
	}

	private function detectMime(string $binary, string $fileName): string
	{
		$mime = '';
		if (function_exists('finfo_open'))
		{
			$finfo = finfo_open(FILEINFO_MIME_TYPE);
			if ($finfo !== false)
			{
				$detected = finfo_buffer($finfo, $binary);
				finfo_close($finfo);
				if (is_string($detected) && $detected !== '')
				{
					$mime = $detected;
				}
			}
		}

		if ($mime === '')
		{
			$mime = 'application/octet-stream';
		}

		return $mime;
	}

	private function resolveAssetType(string $mime): string
	{
		if (str_starts_with($mime, 'image/'))
		{
			return 'image';
		}

		if (str_starts_with($mime, 'video/'))
		{
			return 'video';
		}

		return 'file';
	}


	private function getStateResolver(): DocumentStateResolver
	{
		return $this->stateResolver ??= new DocumentStateResolver();
	}
}

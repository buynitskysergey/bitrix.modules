<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Rest\V3\Controller;

use Bitrix\Main\IO;
use Bitrix\Main\SystemException;
use Bitrix\Note\Infrastructure\Rest\V3\Access\DocumentIntent;
use Bitrix\Note\Infrastructure\Rest\V3\Access\DocumentStateResolver;
use Bitrix\Note\Infrastructure\Rest\V3\Controller\ActionFilter\NoteRestAccess;
use Bitrix\Note\Infrastructure\Rest\V3\Dto\FileItemDto;
use Bitrix\Note\Infrastructure\Rest\V3\Exceptions\FileTooLargeException;
use Bitrix\Note\Infrastructure\Rest\V3\Exceptions\FileTypeNotAllowedException;
use Bitrix\Note\Infrastructure\Rest\V3\Request\AddFileRequest;
use Bitrix\Note\Infrastructure\Rest\V3\Request\GetFileRequest;
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

	private function buildFileDto(int $fileId, int $documentId): FileItemDto
	{
		$fileArray = \CFile::GetFileArray($fileId);
		if (!is_array($fileArray))
		{
			throw new EntityNotFoundException($fileId);
		}

		$mime = (string)($fileArray['CONTENT_TYPE'] ?? '');
		$assetType = $this->resolveAssetType($mime);

		$dto = new FileItemDto();
		$dto->id = $fileId;
		$dto->documentId = $documentId;
		$dto->name = (string)($fileArray['ORIGINAL_NAME'] ?? $fileArray['FILE_NAME'] ?? '');
		$dto->size = (int)($fileArray['FILE_SIZE'] ?? 0);
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

<?php
declare(strict_types=1);

namespace Bitrix\Disk\FilePicker;

use Bitrix\Disk\Analytics\Enum\DocumentTypeEnum;
use Bitrix\Disk\File;
use Bitrix\Disk\TypeFile;

final class FileTypeClassifier
{
	private const SPREADSHEET_EXTENSIONS = [
		'csv',
		'fods',
		'ods',
		'ots',
		'xls',
		'xlam',
		'xlsb',
		'xlsm',
		'xlsx',
		'xlt',
		'xltm',
		'xltx',
	];

	private const PRESENTATION_EXTENSIONS = [
		'fodp',
		'odp',
		'otp',
		'pot',
		'potm',
		'potx',
		'ppam',
		'pps',
		'ppsm',
		'ppsx',
		'ppt',
		'pptm',
		'pptx',
	];

	private const KNOWN_DOCUMENT_EXTENSIONS = [
		'fodt',
		'html',
		'htm',
		'xml',
		'fb2',
		'djvu',
		'epub',
		'msg',
		'eml',
	];

	public function classify(File $file): string
	{
		$extension = mb_strtolower((string)$file->getExtension());
		$typeFile = (int)$file->getTypeFile();
		$documentType = DocumentTypeEnum::getByExtension($extension);

		return match (true)
		{
			$typeFile === TypeFile::BOARD || $typeFile === TypeFile::FLIPCHART => Filter::TYPE_BOARD,
			$typeFile === TypeFile::IMAGE || $typeFile === TypeFile::VECTOR_IMAGE => Filter::TYPE_IMAGE,
			$typeFile === TypeFile::AUDIO => Filter::TYPE_AUDIO,
			$typeFile === TypeFile::VIDEO => Filter::TYPE_VIDEO,
			$documentType === DocumentTypeEnum::Sheet
				|| \in_array($extension, self::SPREADSHEET_EXTENSIONS, true) => Filter::TYPE_SPREADSHEET,
			$documentType === DocumentTypeEnum::Pres
				|| \in_array($extension, self::PRESENTATION_EXTENSIONS, true) => Filter::TYPE_PRESENTATION,
			$documentType === DocumentTypeEnum::Doc
				|| $typeFile === TypeFile::DOCUMENT
				|| $typeFile === TypeFile::PDF
				|| ($typeFile === TypeFile::KNOWN && $this->isKnownDocumentExtension($extension)) => Filter::TYPE_DOCUMENT,
			default => Filter::TYPE_OTHER,
		};
	}

	public function isImage(File $file): bool
	{
		return $this->classify($file) === Filter::TYPE_IMAGE;
	}

	private function isKnownDocumentExtension(string $extension): bool
	{
		return \in_array($extension, self::KNOWN_DOCUMENT_EXTENSIONS, true);
	}
}

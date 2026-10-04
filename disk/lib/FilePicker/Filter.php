<?php
declare(strict_types=1);

namespace Bitrix\Disk\FilePicker;

use Bitrix\Disk\BaseObject;
use Bitrix\Disk\File;
use Bitrix\Disk\Folder;
use Bitrix\Disk\Internals\ObjectTable;
use Bitrix\Disk\TypeFile;
use Bitrix\Main\Result;

final class Filter
{
	public const OBJECT_TYPE_ALL = 'all';
	public const OBJECT_TYPE_FILES = 'files';
	public const OBJECT_TYPE_FOLDERS = 'folders';

	public const TYPE_DOCUMENT = 'document';
	public const TYPE_SPREADSHEET = 'spreadsheet';
	public const TYPE_PRESENTATION = 'presentation';
	public const TYPE_BOARD = 'board';
	public const TYPE_IMAGE = 'image';
	public const TYPE_AUDIO = 'audio';
	public const TYPE_VIDEO = 'video';
	public const TYPE_OTHER = 'other';

	private const OBJECT_TYPES = [
		self::OBJECT_TYPE_ALL,
		self::OBJECT_TYPE_FILES,
		self::OBJECT_TYPE_FOLDERS,
	];

	private const FILE_TYPES = [
		self::TYPE_DOCUMENT,
		self::TYPE_SPREADSHEET,
		self::TYPE_PRESENTATION,
		self::TYPE_BOARD,
		self::TYPE_IMAGE,
		self::TYPE_AUDIO,
		self::TYPE_VIDEO,
		self::TYPE_OTHER,
	];

	private const TYPE_FILE_BY_ALIAS = [
		self::TYPE_DOCUMENT => [TypeFile::DOCUMENT, TypeFile::PDF, TypeFile::KNOWN, TypeFile::UNKNOWN],
		self::TYPE_SPREADSHEET => [TypeFile::DOCUMENT, TypeFile::KNOWN, TypeFile::UNKNOWN],
		self::TYPE_PRESENTATION => [TypeFile::DOCUMENT, TypeFile::UNKNOWN],
		self::TYPE_BOARD => [TypeFile::BOARD],
		self::TYPE_IMAGE => [TypeFile::IMAGE, TypeFile::VECTOR_IMAGE],
		self::TYPE_AUDIO => [TypeFile::AUDIO],
		self::TYPE_VIDEO => [TypeFile::VIDEO],
		self::TYPE_OTHER => [TypeFile::ARCHIVE, TypeFile::SCRIPT, TypeFile::UNKNOWN, TypeFile::KNOWN],
	];

	private const RUNTIME_FILE_TYPE_ALIASES = [
		self::TYPE_DOCUMENT,
		self::TYPE_SPREADSHEET,
		self::TYPE_PRESENTATION,
		self::TYPE_OTHER,
	];

	private function __construct(
		private readonly string $objectTypeFilter,
		private readonly array $allowedFileTypes,
		private readonly array $fileTypeFilters,
		private readonly array $effectiveFileTypes,
	)
	{
	}

	public static function create(
		string $objectTypeFilter = self::OBJECT_TYPE_ALL,
		array $allowedFileTypes = [],
		array $fileTypeFilters = [],
	): Result
	{
		$result = new Result();

		$objectTypeFilter = mb_strtolower($objectTypeFilter);
		if (!\in_array($objectTypeFilter, self::OBJECT_TYPES, true))
		{
			return $result->addError(Error::create(Error::INVALID_FILTER));
		}

		$allowedFileTypes = self::normalizeFileTypes($allowedFileTypes);
		$fileTypeFilters = self::normalizeFileTypes($fileTypeFilters);
		if ($allowedFileTypes === null || $fileTypeFilters === null)
		{
			return $result->addError(Error::create(Error::INVALID_FILTER));
		}

		if ($objectTypeFilter === self::OBJECT_TYPE_FOLDERS && !empty($fileTypeFilters))
		{
			return $result->addError(Error::create(Error::INVALID_FILTER));
		}

		$upperBound = $allowedFileTypes ?: self::FILE_TYPES;
		$effectiveFileTypes = $fileTypeFilters
			? array_values(array_intersect($upperBound, $fileTypeFilters))
			: $upperBound
		;

		$result->setData([
			'filter' => new self(
				$objectTypeFilter,
				$allowedFileTypes,
				$fileTypeFilters,
				$effectiveFileTypes,
			),
		]);

		return $result;
	}

	public function shouldReturnObject(BaseObject $object, FileTypeClassifier $classifier): bool
	{
		if ($object instanceof Folder)
		{
			return $this->objectTypeFilter !== self::OBJECT_TYPE_FILES;
		}

		if (!($object instanceof File) || $this->objectTypeFilter === self::OBJECT_TYPE_FOLDERS)
		{
			return false;
		}

		return \in_array($classifier->classify($object), $this->effectiveFileTypes, true);
	}

	public function getObjectTypeFilter(): string
	{
		return $this->objectTypeFilter;
	}

	public function getEffectiveFileTypes(): array
	{
		return $this->effectiveFileTypes;
	}

	public function getAllowedFileTypes(): array
	{
		return $this->allowedFileTypes;
	}

	public function getFileTypeFilters(): array
	{
		return $this->fileTypeFilters;
	}

	public function getObjectQueryFilter(): array
	{
		if ($this->objectTypeFilter === self::OBJECT_TYPE_FOLDERS)
		{
			return [
				'=TYPE' => ObjectTable::TYPE_FOLDER,
			];
		}

		if (!$this->hasFileTypeLimit())
		{
			return $this->objectTypeFilter === self::OBJECT_TYPE_FILES
				? ['=TYPE' => ObjectTable::TYPE_FILE]
				: []
			;
		}

		$fileFilter = $this->getFileQueryFilter();

		if ($this->objectTypeFilter === self::OBJECT_TYPE_FILES)
		{
			return $fileFilter;
		}

		return [
			[
				'LOGIC' => 'OR',
				[
					'=TYPE' => ObjectTable::TYPE_FOLDER,
				],
				$fileFilter,
			],
		];
	}

	public function needsRuntimeFileTypeFiltering(): bool
	{
		if (!$this->hasFileTypeLimit() || empty($this->effectiveFileTypes))
		{
			return false;
		}

		return !empty(array_intersect($this->effectiveFileTypes, self::RUNTIME_FILE_TYPE_ALIASES));
	}

	private function getFileQueryFilter(): array
	{
		if (!$this->hasFileTypeLimit())
		{
			return [
				'=TYPE' => ObjectTable::TYPE_FILE,
			];
		}

		$aliasFilters = [];
		foreach ($this->effectiveFileTypes as $fileType)
		{
			$aliasFilter = $this->getAliasQueryFilter($fileType);
			if (!empty($aliasFilter))
			{
				$aliasFilters[] = $aliasFilter;
			}
		}

		$fileFilter = [
			'=TYPE' => ObjectTable::TYPE_FILE,
		];
		if (empty($aliasFilters))
		{
			$fileFilter['=ID'] = 0;

			return $fileFilter;
		}

		if (\count($aliasFilters) === 1)
		{
			return array_merge($fileFilter, $aliasFilters[0]);
		}

		$fileFilter[] = [
			'LOGIC' => 'OR',
			...$aliasFilters,
		];

		return $fileFilter;
	}

	private function getAliasQueryFilter(string $fileType): array
	{
		$typeFileValues = self::TYPE_FILE_BY_ALIAS[$fileType] ?? [];
		if (empty($typeFileValues))
		{
			return [];
		}

		return [
			'@TYPE_FILE' => $typeFileValues,
		];
	}

	private function hasFileTypeLimit(): bool
	{
		return !empty($this->allowedFileTypes) || !empty($this->fileTypeFilters);
	}

	private static function normalizeFileTypes(array $fileTypes): ?array
	{
		$normalized = [];
		foreach ($fileTypes as $fileType)
		{
			if (!\is_string($fileType))
			{
				return null;
			}

			$fileType = mb_strtolower(trim($fileType));
			if ($fileType === '')
			{
				continue;
			}

			if (!\in_array($fileType, self::FILE_TYPES, true))
			{
				return null;
			}

			$normalized[$fileType] = $fileType;
		}

		return array_values($normalized);
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Item\File;

use Bitrix\Crm\V2\Internal\Service\Item\File\Value\FileFieldWriteRequest;
use Bitrix\Crm\V2\Internal\Service\Item\File\Value\FileUploadInput;
use Bitrix\Crm\V2\Public\Entity\File;
use Bitrix\Crm\V2\Public\Entity\Item\Item;
use Bitrix\Main\ArgumentException;

final class FileFinalStateApplier
{
	/**
	 * @param FileFieldWriteRequest[] $requests
	 * @param array<string, array<int, int>> $uploadedFileIds
	 */
	public function apply(Item $item, array $requests, array $uploadedFileIds): void
	{
		$finalValues = $this->computeFinalValues($requests, $uploadedFileIds);

		foreach ($finalValues as $fieldName => $value)
		{
			$item->setCustomField($fieldName, $value);
		}
	}

	/**
	 * @param FileFieldWriteRequest[] $requests
	 * @param array<string, array<int, int>> $uploadedFileIds
	 *
	 * @return array<string, null|File|File[]>
	 */
	private function computeFinalValues(array $requests, array $uploadedFileIds): array
	{
		$finalValues = [];
		$expectedUploadIndexes = [];

		foreach ($requests as $request)
		{
			if (!$request instanceof FileFieldWriteRequest || $request->fieldName === '')
			{
				throw new ArgumentException('Invalid file field write request.');
			}
			if (array_key_exists($request->fieldName, $finalValues))
			{
				throw new ArgumentException("Duplicate file field write request: {$request->fieldName}.");
			}

			$finalValues[$request->fieldName] = $request->multiple
				? $this->computeMultipleValue($request, $uploadedFileIds, $expectedUploadIndexes)
				: $this->computeSingleValue($request, $uploadedFileIds, $expectedUploadIndexes)
			;
		}

		$this->validateUploadResults($uploadedFileIds, $expectedUploadIndexes);

		return $finalValues;
	}

	/**
	 * @param array<string, array<int, int>> $uploadedFileIds
	 * @param array<string, array<int, true>> $expectedUploadIndexes
	 */
	private function computeSingleValue(
		FileFieldWriteRequest $request,
		array $uploadedFileIds,
		array &$expectedUploadIndexes,
	): ?File
	{
		if ($request->value === null)
		{
			return null;
		}
		if (is_int($request->value))
		{
			$this->validatePositiveFileId($request->fieldName, 0, $request->value);

			return File::fromId($request->value);
		}
		if (!$request->value instanceof FileUploadInput)
		{
			throw new ArgumentException("Invalid value for single file field: {$request->fieldName}.");
		}

		return $this->createUploadedFile(
			$request->fieldName,
			0,
			$uploadedFileIds,
			$expectedUploadIndexes,
		);
	}

	/**
	 * @param array<string, array<int, int>> $uploadedFileIds
	 * @param array<string, array<int, true>> $expectedUploadIndexes
	 *
	 * @return File[]
	 */
	private function computeMultipleValue(
		FileFieldWriteRequest $request,
		array $uploadedFileIds,
		array &$expectedUploadIndexes,
	): array
	{
		if (!is_array($request->value))
		{
			throw new ArgumentException("Invalid value for multiple file field: {$request->fieldName}.");
		}

		$files = [];

		foreach ($request->value as $inputIndex => $value)
		{
			if (!is_int($inputIndex))
			{
				throw new ArgumentException("Invalid input index for file field: {$request->fieldName}.");
			}
			if (is_int($value))
			{
				$this->validatePositiveFileId($request->fieldName, $inputIndex, $value);
				$files[] = File::fromId($value);

				continue;
			}
			if (!$value instanceof FileUploadInput)
			{
				throw new ArgumentException("Invalid item in multiple file field: {$request->fieldName}.");
			}

			$files[] = $this->createUploadedFile(
				$request->fieldName,
				$inputIndex,
				$uploadedFileIds,
				$expectedUploadIndexes,
			);
		}

		return $files;
	}

	/**
	 * @param array<string, array<int, int>> $uploadedFileIds
	 * @param array<string, array<int, true>> $expectedUploadIndexes
	 */
	private function createUploadedFile(
		string $fieldName,
		int $inputIndex,
		array $uploadedFileIds,
		array &$expectedUploadIndexes,
	): File
	{
		$expectedUploadIndexes[$fieldName][$inputIndex] = true;

		if (!array_key_exists($fieldName, $uploadedFileIds))
		{
			throw new ArgumentException("Missing upload result for file field: {$fieldName}.");
		}

		$fieldResults = $uploadedFileIds[$fieldName];
		if (!is_array($fieldResults))
		{
			throw new ArgumentException("Invalid upload result map for file field: {$fieldName}.");
		}
		if (!array_key_exists($inputIndex, $fieldResults))
		{
			throw new ArgumentException(
				"Missing upload result for file field {$fieldName} at index {$inputIndex}.",
			);
		}

		$fileId = $fieldResults[$inputIndex];
		if (!is_int($fileId))
		{
			throw new ArgumentException(
				"Invalid upload result for file field {$fieldName} at index {$inputIndex}.",
			);
		}

		$this->validatePositiveFileId($fieldName, $inputIndex, $fileId);

		return File::fromId($fileId);
	}

	/**
	 * @param array<string, array<int, int>> $uploadedFileIds
	 * @param array<string, array<int, true>> $expectedUploadIndexes
	 */
	private function validateUploadResults(array $uploadedFileIds, array $expectedUploadIndexes): void
	{
		foreach ($uploadedFileIds as $fieldName => $fieldResults)
		{
			if (!is_string($fieldName) || !is_array($fieldResults))
			{
				throw new ArgumentException('Invalid upload results map.');
			}
			if (!array_key_exists($fieldName, $expectedUploadIndexes))
			{
				throw new ArgumentException("Unexpected upload result for file field: {$fieldName}.");
			}

			foreach ($fieldResults as $inputIndex => $fileId)
			{
				if (!is_int($inputIndex) || !is_int($fileId))
				{
					throw new ArgumentException("Invalid upload result for file field: {$fieldName}.");
				}

				$this->validatePositiveFileId($fieldName, $inputIndex, $fileId);

				if (!isset($expectedUploadIndexes[$fieldName][$inputIndex]))
				{
					throw new ArgumentException(
						"Unexpected upload result for file field {$fieldName} at index {$inputIndex}.",
					);
				}
			}
		}
	}

	private function validatePositiveFileId(string $fieldName, int $inputIndex, int $fileId): void
	{
		if ($fileId <= 0)
		{
			throw new ArgumentException(
				"File id for field {$fieldName} at index {$inputIndex} must be positive.",
			);
		}
	}
}

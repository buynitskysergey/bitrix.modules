<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\Mail\LargeAttachment;

use Bitrix\Mail\Helper\Config\Feature;
use Bitrix\Mail\Public\Service\LargeAttachment\Dto\SendContractResult;
use Bitrix\Mail\Public\Service\LargeAttachment\SendContractValidator;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;

/**
 * Prepares CRM mail attachment maps after validating large attachment contracts.
 */
final class SendPreparation
{
	public const STORAGE_ELEMENT_IDS = 'storageElementIds';
	public const RAW_FILES = 'rawFiles';
	public const ATTACH_TO_FILE_IDS = 'attachToFileIds';
	public const TEMPLATE_STORAGE_ELEMENT_IDS = 'templateStorageElementIds';
	public const TEMPLATE_ATTACH_TO_FILE_IDS = 'templateAttachToFileIds';
	public const CONFIRMED_FILE_IDS = 'confirmedFileIds';
	public const ERROR_INVALID_SEND_CONTRACT = 'MAIL_LA_INVALID_SEND_CONTRACT';
	public const ERROR_INVALID_SEND_RESULT = 'MAIL_LA_INVALID_SEND_RESULT';

	private readonly ?SendContractValidator $validator;
	private readonly ?bool $featureEnabled;

	public function __construct(
		?SendContractValidator $validator = null,
		?bool $featureEnabled = null,
	)
	{
		$this->validator = $validator;
		$this->featureEnabled = $featureEnabled;
	}

	public static function isAvailable(): bool
	{
		return self::hasRequiredMailApi()
			&& Feature::isLargeAttachmentDiskUploadAvailable()
		;
	}

	public static function getMaxFileCount(): ?int
	{
		return self::isAvailable() ? SendContractValidator::MAX_FILE_COUNT : null;
	}

	/**
	 * Validates the send contract and removes only confirmed files from ordinary MIME maps.
	 *
	 * @param array<int, array{token?: mixed, fileIds?: mixed}> $contracts
	 * @param mixed[] $allowedFileIds
	 * @param array{
	 *     storageElementIds?: array,
	 *     rawFiles?: array,
	 *     attachToFileIds?: array,
	 *     templateStorageElementIds?: array,
	 *     templateAttachToFileIds?: array
	 * } $files
	 */
	public function prepare(
		int $userId,
		array $contracts,
		array $allowedFileIds,
		string $messageBody,
		array $files,
	): Result
	{
		if (
			!self::hasRequiredMailApi()
			|| ($this->featureEnabled ?? Feature::isLargeAttachmentDiskUploadAvailable()) === false
		)
		{
			$result = new Result();
			$result->setData($files + [self::CONFIRMED_FILE_IDS => []]);

			return $result;
		}

		$validator = $this->validator ?? new SendContractValidator();
		$validationResult = $validator->validate(
			$userId,
			$contracts,
			$allowedFileIds,
			$messageBody,
		);
		if (!$validationResult->isSuccess())
		{
			return (new Result())->addErrors($validationResult->getErrors());
		}

		$sendContract = $validationResult->getData()[SendContractValidator::RESULT_KEY] ?? null;
		if (!$sendContract instanceof SendContractResult)
		{
			return (new Result())->addError(new Error(
				'Large attachment validator returned an invalid result.',
				self::ERROR_INVALID_SEND_RESULT,
			));
		}

		$confirmedFileIds = array_fill_keys($sendContract->fileIds, true);
		$storageElementIds = $this->filterFileIds(
			(array)($files[self::STORAGE_ELEMENT_IDS] ?? []),
			$confirmedFileIds,
		);
		$rawFiles = $this->filterMapByKey(
			(array)($files[self::RAW_FILES] ?? []),
			$confirmedFileIds,
		);
		$originalAttachToFileIds = (array)($files[self::ATTACH_TO_FILE_IDS] ?? []);
		$attachToFileIds = array_intersect_key(
			$originalAttachToFileIds,
			$rawFiles,
		);

		$templateAttachToFileIds = (array)($files[self::TEMPLATE_ATTACH_TO_FILE_IDS] ?? []);
		$excludedTemplateFileIds = $this->getRelatedTemplateFileIds(
			$confirmedFileIds,
			$originalAttachToFileIds,
			$templateAttachToFileIds,
		);
		$templateStorageElementIds = $this->filterTemplateFileIds(
			(array)($files[self::TEMPLATE_STORAGE_ELEMENT_IDS] ?? []),
			$confirmedFileIds + $excludedTemplateFileIds,
		);
		$templateFileIds = array_fill_keys(
			array_map('intval', array_values($templateStorageElementIds)),
			true,
		);
		$templateAttachToFileIds = array_intersect_key(
			$templateAttachToFileIds,
			$templateFileIds,
		);

		$result = new Result();
		$result->setData([
			self::STORAGE_ELEMENT_IDS => $storageElementIds,
			self::RAW_FILES => $rawFiles,
			self::ATTACH_TO_FILE_IDS => $attachToFileIds,
			self::TEMPLATE_STORAGE_ELEMENT_IDS => $templateStorageElementIds,
			self::TEMPLATE_ATTACH_TO_FILE_IDS => $templateAttachToFileIds,
			self::CONFIRMED_FILE_IDS => $sendContract->fileIds,
		]);

		return $result;
	}

	private static function hasRequiredMailApi(): bool
	{
		return Loader::includeModule('mail')
			&& class_exists(SendContractValidator::class)
		;
	}

	/**
	 * @param array<int|string, mixed> $fileIds
	 * @param array<int, true> $excludedFileIds
	 */
	private function filterFileIds(array $fileIds, array $excludedFileIds): array
	{
		return array_values(array_filter(
			$fileIds,
			static fn(mixed $fileId): bool => !isset($excludedFileIds[(int)$fileId]),
		));
	}

	/**
	 * @param array<int|string, mixed> $map
	 * @param array<int, true> $excludedFileIds
	 */
	private function filterMapByKey(array $map, array $excludedFileIds): array
	{
		foreach ($map as $fileId => $value)
		{
			if (isset($excludedFileIds[(int)$fileId]))
			{
				unset($map[$fileId]);
			}
		}

		return $map;
	}

	/**
	 * @param array<int, true> $confirmedFileIds
	 * @param array<int|string, mixed> $attachToFileIds
	 * @param array<int|string, mixed> $templateAttachToFileIds
	 *
	 * @return array<int, true>
	 */
	private function getRelatedTemplateFileIds(
		array $confirmedFileIds,
		array $attachToFileIds,
		array $templateAttachToFileIds,
	): array
	{
		$excludedAttachmentIds = [];
		foreach ($attachToFileIds as $fileId => $attachmentId)
		{
			if (
				isset($confirmedFileIds[(int)$fileId])
				&& (is_int($attachmentId) || is_string($attachmentId))
			)
			{
				$excludedAttachmentIds[(string)$attachmentId] = true;
			}
		}

		$templateFileIds = [];
		foreach ($templateAttachToFileIds as $templateFileId => $attachmentId)
		{
			if (
				(is_int($attachmentId) || is_string($attachmentId))
				&& isset($excludedAttachmentIds[(string)$attachmentId])
			)
			{
				$templateFileIds[(int)$templateFileId] = true;
			}
		}

		return $templateFileIds;
	}

	/**
	 * @param array<int|string, mixed> $fileIds
	 * @param array<int, true> $excludedFileIds
	 */
	private function filterTemplateFileIds(array $fileIds, array $excludedFileIds): array
	{
		foreach ($fileIds as $sourceFileId => $templateFileId)
		{
			if (
				isset($excludedFileIds[(int)$sourceFileId])
				|| isset($excludedFileIds[(int)$templateFileId])
			)
			{
				unset($fileIds[$sourceFileId]);
			}
		}

		return $fileIds;
	}
}

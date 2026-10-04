<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Item\File;

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\FileUploader;
use Bitrix\Crm\V2\Internal\Service\Item\File\Value\FileFieldWriteRequest;
use Bitrix\Crm\V2\Internal\Service\Item\Operation\PreparedItemOperation;
use Bitrix\Crm\V2\Public\Command\Item\AbstractItemCommand;
use Bitrix\Main\Result;

/**
 * @internal
 */
final class FileCustomFieldWriteService
{
	private readonly FileFieldWriteService $service;

	public function __construct(
		?callable $prepare = null,
		?callable $checkAccess = null,
		?callable $launch = null,
		?callable $checkFileAccess = null,
		?callable $processField = null,
		?callable $canUpload = null,
		?callable $upload = null,
		?callable $makePersistent = null,
		?callable $removePending = null,
		?callable $applyFinalState = null,
		?callable $sync = null,
		?callable $observerFactory = null,
		?callable $actualFileIdsLoader = null,
		?FileUploader $fileUploader = null,
		?Container $container = null,
		?callable $uploadMany = null,
		?callable $makePersistentMany = null,
	)
	{
		$this->service = new FileFieldWriteService(
			prepare: $prepare,
			checkAccess: $checkAccess,
			launch: $launch,
			checkFileAccess: $checkFileAccess,
			processField: $processField,
			canUpload: $canUpload,
			upload: $upload,
			makePersistent: $makePersistent,
			removePending: $removePending,
			applyFinalState: $applyFinalState,
			sync: $sync,
			observerFactory: $observerFactory,
			actualFileIdsLoader: $actualFileIdsLoader,
			fileUploader: $fileUploader,
			container: $container,
			uploadMany: $uploadMany,
			makePersistentMany: $makePersistentMany,
		);
	}

	/**
	 * @param list<FileFieldWriteRequest> $requests
	 */
	public function execute(
		AbstractItemCommand $command,
		array $requests,
		?PreparedItemOperation $prepared = null,
	): Result
	{
		return $this->service->execute($command, $requests, $prepared);
	}
}

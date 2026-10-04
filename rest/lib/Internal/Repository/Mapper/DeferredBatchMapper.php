<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Repository\Mapper;

use Bitrix\Main\Type\DateTime;
use Bitrix\Rest\Internal\Entity\DeferredBatch;
use Bitrix\Rest\Internal\Entity\DeferredBatch\Status;

class DeferredBatchMapper
{
	public function convertFromArray(array $row): DeferredBatch
	{
		$status = isset($row['STATUS'])
			? Status::from($row['STATUS'])
			: Status::Pending;

		$createdAt = $row['CREATED_AT'] instanceof DateTime
			? $row['CREATED_AT']
			: (isset($row['CREATED_AT']) ? new DateTime($row['CREATED_AT']) : null);

		$updatedAt = $row['UPDATED_AT'] instanceof DateTime
			? $row['UPDATED_AT']
			: (isset($row['UPDATED_AT']) ? new DateTime($row['UPDATED_AT']) : null);

		return new DeferredBatch(
			id: isset($row['ID']) ? (int)$row['ID'] : null,
			userId: isset($row['USER_ID']) ? (int)$row['USER_ID'] : null,
			status: $status,
			params: $row['REQUEST_PARAMS'] ?? null,
			commands: $row['COMMANDS'] ?? null,
			scopes: $row['SCOPES'] ?? null,
			resultFileId: isset($row['RESULT_FILE_ID']) ? (int)$row['RESULT_FILE_ID'] : null,
			errorMessage: $row['ERROR_MESSAGE'] ?? null,
			createdAt: $createdAt,
			updatedAt: $updatedAt,
		);
	}
}

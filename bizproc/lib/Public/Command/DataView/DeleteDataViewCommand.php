<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Command\DataView;

use Bitrix\Bizproc\Internal\Exception\ErrorBuilder;
use Bitrix\Bizproc\Internal\Exception\Exception;
use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Result;

final class DeleteDataViewCommand extends AbstractCommand
{
	public function __construct(
		public readonly int $storageTypeId,
	)
	{
	}

	public function toArray(): array
	{
		return [
			'storageTypeId' => $this->storageTypeId,
		];
	}

	public static function mapFromArray(array $props): self
	{
		return new self(
			storageTypeId: (int)($props['storageTypeId'] ?? 0),
		);
	}

	protected function execute(): Result
	{
		$result = new Result();
		try
		{
			(new DeleteDataViewCommandHandler())($this);
		}
		catch (Exception $exception)
		{
			$result->addError(ErrorBuilder::buildFromException($exception));
		}

		return $result;
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Command\DataView;

use Bitrix\Bizproc\Internal\Exception\ErrorBuilder;
use Bitrix\Bizproc\Internal\Exception\Exception;
use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Result;

final class RecomputeDataViewCommand extends AbstractCommand
{
	public function __construct(
		public readonly int $storageTypeId,
		public readonly ?int $actorId = null,
	)
	{
	}

	public function toArray(): array
	{
		return [
			'storageTypeId' => $this->storageTypeId,
			'actorId' => $this->actorId,
		];
	}

	public static function mapFromArray(array $props): self
	{
		return new self(
			storageTypeId: (int)($props['storageTypeId'] ?? 0),
			actorId: isset($props['actorId']) ? (int)$props['actorId'] : null,
		);
	}

	protected function execute(): Result
	{
		$result = new Result();
		try
		{
			$result->setData(['rowsCount' => (new RecomputeDataViewCommandHandler())($this)]);
		}
		catch (Exception $exception)
		{
			$result->addError(ErrorBuilder::buildFromException($exception));
		}

		return $result;
	}
}

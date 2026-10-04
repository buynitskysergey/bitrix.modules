<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Command\DataView;

use Bitrix\Bizproc\Internal\Exception\ErrorBuilder;
use Bitrix\Bizproc\Internal\Exception\Exception;
use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Result;

final class SaveDataViewCommand extends AbstractCommand
{
	public function __construct(
		public readonly int $actorId,
		public readonly string $title,
		public readonly ?string $code,
		public readonly ?int $storageTypeId,
		public readonly array $definition,
		public readonly ?string $description = null,
		public readonly ?int $ownerTemplateId = null,
		public readonly ?string $ownerActivityName = null,
	)
	{
	}

	public function toArray(): array
	{
		return [
			'actorId' => $this->actorId,
			'title' => $this->title,
			'code' => $this->code,
			'storageTypeId' => $this->storageTypeId,
			'definition' => $this->definition,
			'description' => $this->description,
			'ownerTemplateId' => $this->ownerTemplateId,
			'ownerActivityName' => $this->ownerActivityName,
		];
	}

	public static function mapFromArray(array $props): self
	{
		return new self(
			actorId: (int)($props['actorId'] ?? 0),
			title: (string)($props['title'] ?? ''),
			code: isset($props['code']) ? (string)$props['code'] : null,
			storageTypeId: isset($props['storageTypeId']) ? (int)$props['storageTypeId'] : null,
			definition: (array)($props['definition'] ?? []),
			description: isset($props['description']) ? (string)$props['description'] : null,
			ownerTemplateId: isset($props['ownerTemplateId']) ? (int)$props['ownerTemplateId'] : null,
			ownerActivityName: isset($props['ownerActivityName']) ? (string)$props['ownerActivityName'] : null,
		);
	}

	protected function execute(): Result
	{
		$result = new Result();
		try
		{
			$result->setData((new SaveDataViewCommandHandler())($this));
		}
		catch (Exception $exception)
		{
			$result->addError(ErrorBuilder::buildFromException($exception));
		}

		return $result;
	}
}

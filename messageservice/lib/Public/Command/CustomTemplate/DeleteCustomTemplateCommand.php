<?php

declare(strict_types=1);

namespace Bitrix\MessageService\Public\Command\CustomTemplate;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Result;
use Bitrix\Main\Validation\Rule\PositiveNumber;

final class DeleteCustomTemplateCommand extends AbstractCommand
{
	public function __construct(
		#[PositiveNumber]
		public readonly int $templateId,
		#[PositiveNumber]
		public readonly int $userId,
	) {}

	protected function execute(): Result
	{
		return ServiceLocator::getInstance()->get(DeleteCustomTemplateCommandHandler::class)($this);
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\MessageService\Public\Command\CustomTemplate;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Result;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Main\Validation\Rule\Recursive\Validatable;
use Bitrix\MessageService\Public\Type\CustomTemplate\CustomTemplate;

final class UpdateCustomTemplateCommand extends AbstractCommand
{
	public function __construct(
		#[PositiveNumber]
		public readonly int $templateId,
		#[Validatable]
		public readonly CustomTemplate $template,
		#[PositiveNumber]
		public readonly int $userId,
	) {}

	protected function execute(): Result
	{
		return ServiceLocator::getInstance()->get(UpdateCustomTemplateCommandHandler::class)($this);
	}
}

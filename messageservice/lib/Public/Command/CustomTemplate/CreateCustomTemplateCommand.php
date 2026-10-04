<?php

declare(strict_types=1);

namespace Bitrix\MessageService\Public\Command\CustomTemplate;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Result;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Main\Validation\Rule\Recursive\Validatable;
use Bitrix\MessageService\Public\Type\CustomTemplate\CustomTemplate;
use Bitrix\MessageService\Public\Type\CustomTemplate\TemplateBinding;

final class CreateCustomTemplateCommand extends AbstractCommand
{
	public function __construct(
		#[Validatable]
		public readonly TemplateBinding $binding,
		#[Validatable]
		public readonly CustomTemplate $template,
		#[PositiveNumber]
		public readonly int $userId,
		public readonly OnTitleDuplicate $strategy = OnTitleDuplicate::Reject,
	) {}

	protected function execute(): Result
	{
		return ServiceLocator::getInstance()->get(CreateCustomTemplateCommandHandler::class)($this);
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\MessageService\Public\Command\CustomTemplate;

use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\MessageService\Internal\Repository\CustomTemplateRepository;

final class DeleteCustomTemplateCommandHandler
{
	public function __construct(private readonly CustomTemplateRepository $repository)
	{
	}

	public function __invoke(DeleteCustomTemplateCommand $command): Result
	{
		$result = new Result();

		if ($this->repository->getBindingById($command->templateId) === null)
		{
			$result->addError(
				new Error(
					Loc::getMessage('MSGSVC_CT_ERROR_NOT_FOUND'),
					'CUSTOM_TEMPLATE_NOT_FOUND'
				)
			);

			return $result;
		}

		$this->repository->delete($command->templateId);

		return $result;
	}
}

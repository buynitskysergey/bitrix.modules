<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Command\WorkflowTemplate\UpdateWorkflowTemplate;

use Bitrix\Bizproc\Api\Enum\ErrorMessage;
use Bitrix\Bizproc\Internal\Container;
use Bitrix\Bizproc\Internal\Repository\WorkflowTemplate\WorkflowTemplateRepository;
use Bitrix\Main\ORM\Data\UpdateResult;

class UpdateWorkflowTemplateCommandHandler
{
	private WorkflowTemplateRepository $repository;

	public function __construct()
	{
		$this->repository = Container::getWorkflowTemplateRepository();
	}

	public function __invoke(UpdateWorkflowTemplateCommand $command): UpdateResult
	{
		$result = $this->repository->updateTemplate($command->templateId, $command->data);

		// Updating a missing template yields a successful UpdateResult with 0 affected rows. Zero affected
		// rows can also mean a genuine no-op update on an existing row, so re-read to tell them apart and
		// refuse only when the template is really gone.
		if (
			$command->templateId > 0
			&& $result->isSuccess()
			&& $result->getAffectedRowsCount() === 0
			&& !$this->repository->exists($command->templateId)
		)
		{
			$result->addError(
				ErrorMessage::TEMPLATE_NOT_FOUND->getCodedError(['#ID#' => $command->templateId]),
			);
		}

		return $result;
	}
}

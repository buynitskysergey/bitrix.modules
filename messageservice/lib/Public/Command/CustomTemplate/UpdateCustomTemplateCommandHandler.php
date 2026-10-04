<?php

declare(strict_types=1);

namespace Bitrix\MessageService\Public\Command\CustomTemplate;

use Bitrix\Main\DB\DuplicateEntryException;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\MessageService\Internal\Entity\CustomTemplate as TemplateEntity;
use Bitrix\MessageService\Internal\Repository\CustomTemplateRepository;
use Bitrix\MessageService\Public\Dto\CustomTemplate\CustomTemplateDetails;
use Bitrix\MessageService\Public\Exception\CustomTemplateInvariantException;
use Bitrix\MessageService\Public\Service\CustomTemplate\CustomTemplateZoneRegistry;
use Bitrix\MessageService\Public\Type\CustomTemplate\TemplateBinding;

final class UpdateCustomTemplateCommandHandler
{
	public function __construct(
		private readonly CustomTemplateRepository $repository,
		private readonly CustomTemplateZoneRegistry $zoneRegistry,
	)
	{
	}

	public function __invoke(UpdateCustomTemplateCommand $command): CustomTemplateResult
	{
		$result = new CustomTemplateResult();

		$entity = $this->repository->getById($command->templateId);
		if ($entity === null)
		{
			$result->addError(
				new Error(
					Loc::getMessage('MSGSVC_CT_ERROR_NOT_FOUND'),
					'CUSTOM_TEMPLATE_NOT_FOUND'
				)
			);

			return $result;
		}

		$entity
			->setTitle($command->template->title)
			->setBody($command->template->body)
			->setModifiedBy($command->userId);
		// DATE_MODIFY is set by the repository on save when ID is present.

		try
		{
			$this->repository->save($entity);
		}
		catch (DuplicateEntryException)
		{
			$result->addError(
				new Error(
					Loc::getMessage('MSGSVC_CT_ERROR_TITLE_DUPLICATE'),
					'CUSTOM_TEMPLATE_TITLE_DUPLICATE'
				)
			);

			return $result;
		}

		$result->setTemplate($this->buildDetails($entity));

		return $result;
	}

	/**
	 * Build {@see CustomTemplateDetails} from the in-memory entity after save —
	 * mirrors {@see CreateCustomTemplateCommandHandler::buildDetails()} so that
	 * the post-save provider lookup is eliminated.
	 */
	private function buildDetails(TemplateEntity $entity): CustomTemplateDetails
	{
		$binding = $entity->getBinding();
		$description = $this->zoneRegistry->get($binding->zone)?->describeBinding(
			new TemplateBinding($binding->zone, $binding->scene, $binding->targetId),
		);

		$dateCreate = $entity->getDateCreate();
		if ($dateCreate === null)
		{
			throw CustomTemplateInvariantException::missingField($entity->getId(), 'DATE_CREATE');
		}
		$authorId = $entity->getAuthorId();
		if ($authorId === null)
		{
			throw CustomTemplateInvariantException::missingField($entity->getId(), 'AUTHOR_ID');
		}

		return new CustomTemplateDetails(
			id: (int)$entity->getId(),
			zoneId: $binding->zone,
			sceneId: $binding->scene,
			targetId: $binding->targetId,
			title: $entity->getTitle(),
			body: $entity->getBody(),
			sceneLabel: $description?->sceneLabel ?? '',
			targetLabel: $description?->targetLabel ?? '',
			dateCreate: $dateCreate,
			authorId: $authorId,
			dateModify: $entity->getDateModify(),
			modifiedBy: $entity->getModifiedBy(),
		);
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\MessageService\Public\Command\CustomTemplate;

use Bitrix\Main\Config\Option;
use Bitrix\Main\DB\DuplicateEntryException;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Type\DateTime;
use Bitrix\MessageService\Internal\Entity\CustomTemplate as TemplateEntity;
use Bitrix\MessageService\Internal\Repository\CustomTemplateRepository;
use Bitrix\MessageService\Internal\Service\CustomTemplate\NameConflictResolutionException;
use Bitrix\MessageService\Internal\Service\CustomTemplate\NameConflictResolver;
use Bitrix\MessageService\Internal\ValueObject\CustomTemplateBinding as InternalTemplateBinding;
use Bitrix\MessageService\Public\Dto\CustomTemplate\CustomTemplateDetails;
use Bitrix\MessageService\Public\Exception\CustomTemplateInvariantException;
use Bitrix\MessageService\Public\Service\CustomTemplate\CustomTemplateZoneRegistry;
use Bitrix\MessageService\Public\Type\CustomTemplate\TemplateBinding;

final class CreateCustomTemplateCommandHandler
{
	public function __construct(
		private readonly CustomTemplateRepository $repository,
		private readonly CustomTemplateZoneRegistry $zoneRegistry,
		private readonly NameConflictResolver $resolver,
	)
	{
	}

	public function __invoke(CreateCustomTemplateCommand $command): CustomTemplateResult
	{
		$binding = $command->binding;
		$body = $command->template->body;
		$userId = $command->userId;
		$repository = $this->repository;

		$result = new CustomTemplateResult();

		if ($this->zoneRegistry->get($binding->zone) === null)
		{
			$result->addError(new Error('Unknown zone: ' . $binding->zone, 'ZONE_NOT_REGISTERED'));

			return $result;
		}

		$limit = max(1, (int)Option::get('messageservice', 'custom_template_zone_limit', 500));
		// Soft, configurable anti-abuse cap (option custom_template_zone_limit): a rare
		// off-by-one overshoot under concurrent creation does not break any invariant,
		// so a transactional lock is intentionally avoided.
		if ($repository->countInZone($binding->zone) >= $limit)
		{
			$result->addError(
				new Error(
					Loc::getMessage('MSGSVC_CT_ERROR_ZONE_LIMIT', ['#LIMIT#' => $limit]),
					'CUSTOM_TEMPLATE_ZONE_LIMIT',
				)
			);

			return $result;
		}

		$persist = static function (string $title) use ($repository, $binding, $body, $userId): TemplateEntity {
			$entity = new TemplateEntity(
				new InternalTemplateBinding(
					$binding->zone,
					$binding->scene,
					$binding->targetId,
				),
				$title,
				$body,
			);
			$entity->setAuthorId($userId);
			$entity->setDateCreate(new DateTime());
			$repository->save($entity);

			return $entity;
		};

		try
		{
			$entity = $command->strategy === OnTitleDuplicate::AutoSuffix
				? $this->resolver->tryWithAutoSuffix($persist, $command->template->title)
				: $persist($command->template->title);
		}
		catch (DuplicateEntryException | NameConflictResolutionException)
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
	 * Build {@see CustomTemplateDetails} from an in-memory entity instead of
	 * round-tripping through provider->getById(): the entity is fully populated
	 * right after save(), so the only external lookup needed is the zone-specific
	 * context description (label rendering).
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

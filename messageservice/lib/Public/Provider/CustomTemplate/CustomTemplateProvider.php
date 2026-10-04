<?php

declare(strict_types=1);

namespace Bitrix\MessageService\Public\Provider\CustomTemplate;

use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Provider\Params\GridParams;
use Bitrix\Main\Type\DateTime;
use Bitrix\MessageService\Internal\Entity\CustomTemplate as TemplateEntity;
use Bitrix\MessageService\Internal\Entity\CustomTemplateCollection;
use Bitrix\MessageService\Internal\Repository\CustomTemplateRepository;
use Bitrix\MessageService\Internal\ValueObject\CustomTemplateBinding as InternalTemplateBinding;
use Bitrix\MessageService\Public\Dto\CustomTemplate\CustomTemplateDetails;
use Bitrix\MessageService\Public\Dto\CustomTemplate\CustomTemplateListItem;
use Bitrix\MessageService\Public\Dto\CustomTemplate\CustomTemplateSelectorItem;
use Bitrix\MessageService\Public\Exception\CustomTemplateInvariantException;
use Bitrix\MessageService\Public\Service\CustomTemplate\CustomTemplateZoneRegistry;
use Bitrix\MessageService\Public\Type\CustomTemplate\ReadableScope;
use Bitrix\MessageService\Public\Type\CustomTemplate\TemplateBinding;

class CustomTemplateProvider
{
	public function __construct(
		private readonly CustomTemplateRepository $repository,
		private readonly CustomTemplateZoneRegistry $zoneRegistry,
	)
	{
	}

	public function getById(int $id): ?CustomTemplateDetails
	{
		$entity = $this->repository->getById($id);
		if (!$entity)
		{
			return null;
		}

		return $this->buildDetails($entity);
	}

	/**
	 * Resolve the public binding of a template without exposing its content.
	 * Used by controllers as a read-side precheck before the access check —
	 * it does not replace the not-found contract of the command handlers.
	 * Reads only ZONE/SCENE/TARGET_ID, so the template BODY is never loaded.
	 */
	public function getBindingById(int $id): ?TemplateBinding
	{
		$binding = $this->repository->getBindingById($id);
		if ($binding === null)
		{
			return null;
		}

		return new TemplateBinding($binding->zone, $binding->scene, $binding->targetId);
	}

	/**
	 * Batch counterpart to {@see self::getById()} — one DB SELECT for the whole id set
	 * instead of N. Returns a map keyed by template id (entries for missing/unknown
	 * ids are omitted, mirroring {@see self::getById()} returning null).
	 *
	 * @param int[] $ids
	 * @return array<int, CustomTemplateDetails>
	 */
	public function getByIds(array $ids): array
	{
		if ($ids === [])
		{
			return [];
		}

		$result = [];
		foreach ($this->repository->getByIds($ids) as $entity)
		{
			$details = $this->buildDetails($entity);
			$result[$details->id] = $details;
		}

		return $result;
	}

	/** @return CustomTemplateSelectorItem[] */
	public function getForSelector(TemplateBinding $current, int $limit = 10, ?string $titleLike = null): array
	{
		$zone = $this->zoneRegistry->get($current->zone);
		if ($zone === null)
		{
			return [];
		}

		$internalBinding = new InternalTemplateBinding($current->zone, $current->scene, $current->targetId);

		// Gate the zone-wide reads (top-up + search) on the permission-side scope at the query
		// level so unreadable bindings never consume the limit ahead of readable rows. The
		// current-binding preload is ungated — the editor opens on it, so its templates are
		// always shown.
		$scope = $zone->getReadableScope((int)CurrentUser::get()->getId());

		$entities = $titleLike !== null && $titleLike !== ''
			? $this->repository->searchZoneForSelector($internalBinding, $titleLike, $limit, $scope)
			: $this->collectPreloadWithTopUp($internalBinding, $limit, $scope)
		;

		$items = [];
		foreach ($entities as $entity)
		{
			$item = $this->buildSelectorItem($entity, $current);
			$items[] = $zone->fillSelectorItem($item, $current);
		}

		return $items;
	}

	/**
	 * Preload current-binding templates first (ungated), then top up with the rest of the zone
	 * (ID DESC, excluding the current binding) until the limit is reached. The top-up is gated
	 * to the readable scope so the precise ordering beyond this composition is left to the
	 * selector's built-in recent mechanics.
	 */
	private function collectPreloadWithTopUp(
		InternalTemplateBinding $binding,
		int $limit,
		ReadableScope $scope,
	): CustomTemplateCollection
	{
		$collection = $this->repository->getCurrentBindingForSelector($binding, $limit);

		$remaining = $limit - $collection->count();
		if ($remaining <= 0)
		{
			return $collection;
		}

		foreach ($this->repository->topUpZoneForSelector($binding, $remaining, $scope) as $entity)
		{
			$collection->add($entity);
		}

		return $collection;
	}

	private function buildSelectorItem(TemplateEntity $entity, TemplateBinding $current): CustomTemplateSelectorItem
	{
		$itemBinding = $this->toPublicBinding($entity);

		return new CustomTemplateSelectorItem(
			id: (int)$entity->getId(),
			title: $entity->getTitle(),
			body: $entity->getBody(),
			bodyPreview: $this->preview($entity->getBody()),
			binding: $itemBinding,
			isForeign: !$this->isSameBinding($itemBinding, $current),
		);
	}

	private function toPublicBinding(TemplateEntity $entity): TemplateBinding
	{
		$binding = $entity->getBinding();

		return new TemplateBinding($binding->zone, $binding->scene, $binding->targetId);
	}

	private function isSameBinding(TemplateBinding $a, TemplateBinding $b): bool
	{
		return $a->zone === $b->zone && $a->scene === $b->scene && $a->targetId === $b->targetId;
	}

	/** @return CustomTemplateListItem[] */
	public function getForGrid(GridParams $params): array
	{
		$entities = $this->repository->getForGrid(
			$params->getLimit(),
			$params->getOffset(),
			$params->filter,
			$params->getSort(),
		);

		$items = [];
		$descriptionMemo = [];
		foreach ($entities as $entity)
		{
			$binding = $entity->getBinding();
			$descriptionKey = $binding->zone . "\0" . $binding->scene . "\0" . $binding->targetId;
			$description = $descriptionMemo[$descriptionKey] ??= $this->zoneRegistry->get($binding->zone)?->describeBinding(
				new TemplateBinding($binding->zone, $binding->scene, $binding->targetId),
			);
			$items[] = new CustomTemplateListItem(
				id: (int)$entity->getId(),
				title: $entity->getTitle(),
				body: $entity->getBody(),
				bodyPreview: $this->preview($entity->getBody()),
				scene: $binding->scene,
				targetId: $binding->targetId,
				sceneLabel: $description?->sceneLabel ?? $binding->scene,
				targetLabel: $description?->targetLabel ?? $binding->targetId,
				// DATE_CREATE/AUTHOR_ID are schema-required and always present in practice; unlike
				// buildDetails() the list path does not raise the invariant so a single corrupt row
				// never takes down the whole grid — it degrades to the system author / now instead.
				authorId: $entity->getAuthorId() ?? 0,
				dateCreate: $entity->getDateCreate() ?? new DateTime(),
				modifiedBy: $entity->getModifiedBy(),
				dateModify: $entity->getDateModify(),
			);
		}

		return $items;
	}

	public function getCountForGrid(GridParams $params): int
	{
		return $this->repository->getCountForGrid($params->filter);
	}

	private function preview(string $body): string
	{
		$stripped = (string)preg_replace_callback(
			'#\[placeholder\b[^\]]*\](.*?)\[/placeholder\]#us',
			static fn(array $matches): string => '{{' . trim((string)$matches[1]) . '}}',
			$body,
		);
		$stripped = trim($stripped);

		return mb_strlen($stripped) > 120 ? mb_substr($stripped, 0, 120) . '…' : $stripped;
	}

	/**
	 * Convert a fully-loaded {@see TemplateEntity} into a public-facing DTO,
	 * folding in the zone-specific context labels.
	 *
	 * Shared by {@see self::getById()} and {@see self::getByIds()} so the
	 * batch path produces identical DTOs to the single-id path.
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

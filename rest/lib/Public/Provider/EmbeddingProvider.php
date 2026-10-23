<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Provider;

use Bitrix\Main\Application;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Rest\Internal\Access\User\Model\RestUserModel;
use Bitrix\Rest\Internal\Entity\Application\Embedding\Embedding;
use Bitrix\Rest\Internal\Entity\Application\Embedding\EmbeddingCollection;
use Bitrix\Rest\Internal\Entity\Application\Embedding\EmbeddingLanguage;
use Bitrix\Rest\Internal\Entity\Application\Embedding\EmbeddingLanguageCollection;
use Bitrix\Rest\Internal\Repository\EmbeddingRepository;
use Bitrix\Rest\Internal\Service\Embedding\EmbeddingOwnerResolver;
use Bitrix\Rest\Lang;
use Bitrix\Rest\PlacementTable;
use Bitrix\Rest\Public\Dto\Embedding\EmbeddingOwnerDto;
use Bitrix\Rest\Public\Provider\Dto\Embedding\EmbeddingDto;
use Bitrix\Rest\Public\Provider\Dto\Embedding\EmbeddingOpenDto;
use Bitrix\Rest\Public\Provider\Dto\Embedding\EmbeddingViewDto;

class EmbeddingProvider
{
	private EmbeddingRepository $repository;
	private EmbeddingOwnerProviderRegistry $registry;

	public function __construct(
		?EmbeddingRepository $repository = null,
		?EmbeddingOwnerProviderRegistry $registry = null,
	)
	{
		$this->repository = $repository ?? ServiceLocator::getInstance()->get(EmbeddingRepository::class);
		$this->registry = $registry ?? new EmbeddingOwnerProviderRegistry();
	}

	public function getListByClientId(string $clientId, int $limit = 50, int $offset = 0, ?array $userIds = null): EmbeddingCollection
	{
		return $this->repository->getListByClientId($clientId, $limit, $offset, $userIds);
	}

	public function getById(int $id): ?EmbeddingDto
	{
		$embedding = $this->repository->getById($id);

		return $embedding === null ? null : $this->makeEmbeddingDto($embedding);
	}

	public function getByOwner(EmbeddingOwnerDto $owner): array
	{
		$embeddings = $this->repository->getByOwner(
			$owner->moduleId,
			$owner->entityType,
			$owner->entityId,
		);

		return $this->makeProviderEmbeddings($embeddings);
	}

	public function getListByPlacement(string $placement, int $userId): array
	{
		if ($userId <= 0)
		{
			return [];
		}

		$user = RestUserModel::createFromId($userId);
		$isAdmin = $user->isAdmin();

		if (!$isAdmin && $user->getData() === null)
		{
			return [];
		}

		$rows = $isAdmin
			? $this->repository->getByPlacementUnrestricted($placement, $userId)
			: $this->repository->getByPlacementForSubject($placement, $userId);

		$groups = $this->groupByOwner($rows);
		if (empty($groups))
		{
			return [];
		}

		$views = [];
		foreach ($groups as $moduleId => $groupsByEntityType)
		{
			foreach ($groupsByEntityType as $entityType => $group)
			{
				$views += $this->resolveGroupViews((string)$moduleId, (string)$entityType, $group, $userId);
			}
		}

		ksort($views, SORT_NUMERIC);

		return $views;
	}

	/**
	 * @return array<string, array<string, array<int, array{0: Embedding, 1: EmbeddingDto}>>>
	 */
	private function groupByOwner(EmbeddingCollection $rows): array
	{
		$groups = [];
		foreach ($rows as $embedding)
		{
			$dto = $this->makeEmbeddingDto($embedding);
			if ($dto === null)
			{
				continue;
			}

			$groups[$dto->moduleId][$dto->entityType][$dto->id] = [$embedding, $dto];
		}

		return $groups;
	}

	private function resolveGroupViews(string $moduleId, string $entityType, array $group, int $userId): array
	{
		try
		{
			$provider = $this->registry->resolve($moduleId, $entityType);
			if ($provider === null)
			{
				return [];
			}

			$providerEmbeddings = array_values(
				array_map(static fn(array $pair): EmbeddingDto => $pair[1], $group),
			);

			$openItems = [];
			foreach ($provider->resolveEmbeddings($providerEmbeddings, $userId) as $openItem)
			{
				$openItems[$openItem->embeddingId] = $openItem;
			}
			if (empty($openItems))
			{
				return [];
			}

			$views = [];
			foreach ($group as $id => [$embedding, $embeddingDto])
			{
				if (!isset($openItems[$id]))
				{
					continue;
				}

				$views[$id] = $this->makeView($embedding, $embeddingDto, $openItems[$id]);
			}

			return $views;
		}
		catch (\Throwable $exception)
		{
			$this->logGroupFailure($moduleId, $entityType, count($group), $exception);

			return [];
		}
	}

	private function makeProviderEmbeddings(EmbeddingCollection $embeddings): array
	{
		$result = [];
		foreach ($embeddings as $embedding)
		{
			$embeddingDto = $this->makeEmbeddingDto($embedding);
			if ($embeddingDto !== null)
			{
				$result[] = $embeddingDto;
			}
		}

		return $result;
	}

	private function makeEmbeddingDto(Embedding $embedding): ?EmbeddingDto
	{
		$id = $embedding->getId();
		$owner = $this->resolveOwner($embedding);
		if ($id === null || $owner === null)
		{
			return null;
		}

		return new EmbeddingDto(
			id: $id,
			moduleId: $owner->moduleId,
			entityType: $owner->entityType,
			entityId: $owner->entityId,
			placement: (string)$embedding->getPlacement(),
			handler: (string)$embedding->getHandler(),
			userId: (int)$embedding->getUserId(),
		);
	}

	private function resolveOwner(Embedding $embedding): ?EmbeddingOwnerDto
	{
		$owner = EmbeddingOwnerResolver::resolveForEmbedding($embedding);
		if ($owner === null)
		{
			return null;
		}

		return new EmbeddingOwnerDto(
			moduleId: $owner['MODULE_ID'],
			entityType: $owner['ENTITY_TYPE'],
			entityId: $owner['ENTITY_ID'],
		);
	}

	private function makeView(
		Embedding $embedding,
		EmbeddingDto $embeddingDto,
		EmbeddingOpenDto $openItem,
	): EmbeddingViewDto
	{
		return new EmbeddingViewDto(
			id: $embeddingDto->id,
			title: $this->resolveTitle($embedding, $embeddingDto->id),
			description: $this->resolveText(
				$embedding,
				static fn(EmbeddingLanguage $language): ?string => $language->getDescription(),
				$embedding->getDescription(),
			),
			groupName: $this->resolveText(
				$embedding,
				static fn(EmbeddingLanguage $language): ?string => $language->getGroupName(),
				$embedding->getGroupName(),
			),
			icon: $this->resolveIcon($embedding->getIconId()),
			moduleId: $embeddingDto->moduleId,
			entityType: $embeddingDto->entityType,
			entityId: $embeddingDto->entityId,
			url: $openItem->url,
			payload: $openItem->payload,
		);
	}

	private function resolveTitle(Embedding $embedding, int $id): string
	{
		$title = $this->resolveText(
			$embedding,
			static fn(EmbeddingLanguage $language): ?string => $language->getTitle(),
			$embedding->getTitle(),
		);

		return empty($title) ? (string)PlacementTable::getDefaultTitle($id) : $title;
	}

	private function resolveText(Embedding $embedding, callable $read, ?string $column): string
	{
		$localized = $this->getLocalizedText($embedding->getLanguages(), $read);

		return (string)(empty($localized) ? $column : $localized);
	}

	private function getLocalizedText(EmbeddingLanguageCollection $languages, callable $read): ?string
	{
		$values = [];
		foreach ($languages as $language)
		{
			$values[$language->getLanguageId()] = $read($language);
		}

		if (empty($values))
		{
			return null;
		}

		foreach (Lang::listLanguage() as $languageId)
		{
			if (array_key_exists($languageId, $values))
			{
				return $values[$languageId];
			}
		}

		return reset($values);
	}

	private function resolveIcon(?int $iconId): ?array
	{
		if ($iconId === null || $iconId <= 0)
		{
			return null;
		}

		$file = \CFile::GetFileArray($iconId);

		return is_array($file) ? array_change_key_case($file, CASE_LOWER) : null;
	}

	private function logGroupFailure(string $moduleId, string $entityType, int $rowCount, \Throwable $exception): void
	{
		$message = sprintf(
			'Embedding owner provider failed: moduleId=%s, entityType=%s, rows=%d',
			$moduleId,
			$entityType,
			$rowCount,
		);

		Application::getInstance()->getExceptionHandler()->writeToLog(
			new \RuntimeException($message, previous: $exception),
		);
	}
}

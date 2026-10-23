<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Repository;

use Bitrix\Main;
use Bitrix\Main\Repository\Exception\PersistenceException;
use Bitrix\Rest\EO_Placement_Collection;
use Bitrix\Rest\Internal\Entity\Application\Embedding\Embedding;
use Bitrix\Rest\Internal\Entity\Application\Embedding\EmbeddingCollection;
use Bitrix\Rest\Internal\Exception\ArgumentException;
use Bitrix\Rest\Internal\Model\EmbeddingAccessTable;
use Bitrix\Rest\Internal\Repository\Mapper\EmbeddingMapper;
use Bitrix\Rest\Internal\Service\Embedding\EmbeddingAccessCodeNormalizer;
use Bitrix\Rest\Internal\Service\Embedding\EmbeddingLanguageDto;
use Bitrix\Rest\PlacementLangTable;
use Bitrix\Rest\PlacementTable;

class EmbeddingRepository implements Main\Repository\RepositoryInterface
{
	private const ERROR_NOT_A_SUBJECT = 'Access codes belong to a subject with a positive id';

	private EmbeddingMapper $mapper;

	public function __construct(?EmbeddingMapper $mapper = null)
	{
		$this->mapper = $mapper ?? Main\DI\ServiceLocator::getInstance()->get(EmbeddingMapper::class);
	}

	public function hasDuplicate(
		string $moduleId,
		string $entityType,
		string $entityId,
		string $placement,
		string $handler,
	): bool
	{
		return PlacementTable::query()
			->setSelect(['ID'])
			->where($this->getOwnerFilter($moduleId, $entityType, $entityId))
			->where('PLACEMENT', $placement)
			->where('PLACEMENT_HANDLER', $handler)
			->setLimit(1)
			->fetch() !== false
		;
	}

	public function countForLimit(
		string $moduleId,
		string $entityType,
		string $entityId,
		string $placement,
		int $targetUserId,
		bool $userMode,
	): int
	{
		$query = PlacementTable::query()
			->setSelect(['ID'])
			->where($this->getOwnerFilter($moduleId, $entityType, $entityId))
			->where('PLACEMENT', $placement)
		;

		if ($userMode)
		{
			$query->whereIn('USER_ID', [PlacementTable::DEFAULT_USER_ID_VALUE, $targetUserId]);
		}

		return (int)$query->queryCountTotal();
	}

	/**
	 * @param list<EmbeddingLanguageDto> $languages
	 *
	 * @throws PersistenceException
	 */
	public function create(
		string $moduleId,
		string $entityType,
		string $entityId,
		string $placement,
		string $handler,
		int $targetUserId,
		array $options,
		array $languages,
		?int $iconId = null,
	): int
	{
		try
		{
			$fields = [
				'USER_ID' => $targetUserId,
				'PLACEMENT' => $placement,
				'PLACEMENT_HANDLER' => $handler,
				'OPTIONS' => $options,
				'MODULE_ID' => $moduleId,
				'ENTITY_TYPE' => $entityType,
				'ENTITY_ID' => $entityId,
			];

			if ($iconId !== null)
			{
				$fields['ICON_ID'] = $iconId;
			}

			$addResult = PlacementTable::add($fields);
			if (!$addResult->isSuccess())
			{
				throw new PersistenceException(implode(', ', $addResult->getErrorMessages()));
			}

			$embeddingId = (int)$addResult->getId();
			$this->insertLanguages($embeddingId, $languages);

			return $embeddingId;
		}
		catch (PersistenceException $exception)
		{
			throw $exception;
		}
		catch (\Throwable $exception)
		{
			throw new PersistenceException($exception->getMessage(), previous: $exception);
		}
	}

	public function deleteById(int $embeddingId): bool
	{
		try
		{
			$embedding = PlacementTable::query()
				->setSelect(['ID'])
				->where('ID', $embeddingId)
				->fetch()
			;
			if ($embedding === false)
			{
				return false;
			}

			$result = PlacementTable::delete($embeddingId);
			if (!$result->isSuccess())
			{
				throw new PersistenceException(implode(', ', $result->getErrorMessages()));
			}

			return true;
		}
		catch (PersistenceException $exception)
		{
			throw $exception;
		}
		catch (\Throwable $exception)
		{
			throw new PersistenceException($exception->getMessage(), previous: $exception);
		}
	}

	public function getListByClientId(string $clientId, int $limit = 50, int $offset = 0, ?array $userIds = null): EmbeddingCollection
	{
		$query = PlacementTable::query()
			->setSelect(['*'])
			->where('REST_APP.CLIENT_ID', $clientId)
			->setOrder(['ID' => 'ASC'])
			->setLimit($limit)
			->setOffset($offset)
		;

		if ($userIds !== null)
		{
			$query->whereIn('USER_ID', $userIds);
		}

		/**
		 * @var EO_Placement_Collection $ormCollection
		 */
		$ormCollection = $query->fetchCollection();

		$ormCollection->fillLangAll();

		return $this->mapper->convertCollectionFromOrm($ormCollection);
	}

	public function getById(int $id): ?Embedding
	{
		$ormEntity = PlacementTable::query()
			->setSelect(['*'])
			->where('ID', $id)
			->fetchObject()
		;

		return $ormEntity === null ? null : $this->mapper->convertFromOrm($ormEntity);
	}

	public function getByOwner(string $moduleId, string $entityType, string $entityId): EmbeddingCollection
	{
		$ormCollection = PlacementTable::query()
			->setSelect(['*'])
			->where($this->getOwnerFilter($moduleId, $entityType, $entityId))
			->setOrder(['ID' => 'ASC'])
			->fetchCollection()
		;

		return $this->mapper->convertCollectionFromOrm($ormCollection);
	}

	public function getByPlacementForSubject(string $placement, int $userId): EmbeddingCollection
	{
		return $this->fetchByPlacement($placement, $userId, $this->getAccessFilter($userId));
	}

	public function getByPlacementUnrestricted(string $placement, int $userId): EmbeddingCollection
	{
		return $this->fetchByPlacement($placement, $userId, null);
	}

	private function fetchByPlacement(
		string $placement,
		int $userId,
		?Main\ORM\Query\Filter\ConditionTree $accessFilter,
	): EmbeddingCollection
	{
		$query = PlacementTable::query()
			->setSelect(['*'])
			->where('PLACEMENT', $placement)
			->whereIn('USER_ID', $this->getVisibleUserIds($userId))
			->setOrder(['ID' => 'ASC'])
		;

		if ($accessFilter !== null)
		{
			$query->where($accessFilter);
		}

		$ormCollection = $query->fetchCollection();

		$ormCollection->fillLangAll();

		return $this->mapper->convertCollectionFromOrm($ormCollection);
	}

	private function getAccessFilter(int $userId): Main\ORM\Query\Filter\ConditionTree
	{
		if ($userId <= 0)
		{
			throw new ArgumentException(self::ERROR_NOT_A_SUBJECT, 'userId');
		}

		$codes = \CAccess::GetUserCodesArray($userId);
		if (empty($codes))
		{
			throw new ArgumentException(self::ERROR_NOT_A_SUBJECT, 'userId');
		}

		$codes[] = EmbeddingAccessCodeNormalizer::ACCESS_CODE_AUTHORIZED;

		$visibleEmbeddings = EmbeddingAccessTable::query()
			->setSelect(['EMBEDDING_ID'])
			->whereIn('ACCESS_CODE', $codes)
		;

		return Main\ORM\Query\Query::filter()->whereIn('ID', $visibleEmbeddings);
	}

	private function getVisibleUserIds(int $userId): array
	{
		$userIds = [PlacementTable::DEFAULT_USER_ID_VALUE];
		if ($userId > 0)
		{
			$userIds[] = $userId;
		}

		return $userIds;
	}

	private function getOwnerFilter(
		string $moduleId,
		string $entityType,
		string $entityId,
	): Main\ORM\Query\Filter\ConditionTree
	{
		return Main\ORM\Query\Query::filter()
			->where('MODULE_ID', $moduleId)
			->where('ENTITY_TYPE', $entityType)
			->where('ENTITY_ID', $entityId)
		;
	}

	/**
	 * @param list<EmbeddingLanguageDto> $languages
	 *
	 * @throws PersistenceException
	 */
	private function insertLanguages(int $embeddingId, array $languages): void
	{
		foreach ($languages as $language)
		{
			$result = PlacementLangTable::add([
				'PLACEMENT_ID' => $embeddingId,
				'LANGUAGE_ID' => $language->languageId,
				'TITLE' => trim($language->title ?? ''),
				'DESCRIPTION' => trim($language->description ?? ''),
				'GROUP_NAME' => trim($language->groupName ?? ''),
			]);

			if (!$result->isSuccess())
			{
				throw new PersistenceException(implode(', ', $result->getErrorMessages()));
			}
		}
	}
}

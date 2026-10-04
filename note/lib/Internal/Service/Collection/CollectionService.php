<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Collection;

use Bitrix\Main\Application;
use Bitrix\Main\SystemException;
use Bitrix\Note\Internal\Access\Service\CollectionAccessService;
use Bitrix\Note\Internal\Model\Collection;
use Bitrix\Note\Internal\Model\CollectionTable;
use Bitrix\Note\Internal\Repository\CollectionRepository;
use Bitrix\Note\Internal\Service\Document\MainDocumentService;
use Bitrix\Note\Internal\Service\User\SystemUser;

class CollectionService
{
	public function __construct(
		private readonly CollectionRepository $repository = new CollectionRepository(),
		private readonly MainDocumentService $mainDocumentService = new MainDocumentService(),
	)
	{
	}

	/**
	 * Creates a new collection.
	 *
	 * Default behaviour (no policyLevel/permissions): installs the standard
	 * "moderator + globally-blocked" ACL pair via createDefaultAccess().
	 *
	 * Atomic behaviour (when $policyLevel or $permissions is supplied): wraps
	 * collection insert + ACL replacement in a single transaction. If ACL
	 * application fails (e.g. permissions list contains no moderator), the
	 * collection insert is rolled back.
	 *
	 * @throws SystemException on persistence failure
	 */
	public function create(
		string $name,
		int $userId,
		int $position = 0,
		string|int|null $policyLevel = null,
		?array $permissions = null,
	): Collection
	{
		if ($policyLevel === null && $permissions === null)
		{
			$connection = Application::getConnection();
			$connection->startTransaction();
			try
			{
				$saved = $this->persistCollection($name, $userId, $position);
				CollectionAccessService::createDefaultAccess((int)$saved->getId(), $userId);
				$this->ensureMainDocument((int)$saved->getId(), $userId);

				$connection->commitTransaction();

				return $saved;
			}
			catch (SystemException $e)
			{
				try
				{
					$connection->rollbackTransaction();
				}
				catch (\Throwable)
				{
				}

				throw $e;
			}
			catch (\Throwable $e)
			{
				try
				{
					$connection->rollbackTransaction();
				}
				catch (\Throwable)
				{
				}

				throw new SystemException($e->getMessage());
			}
		}

		$connection = Application::getConnection();
		$connection->startTransaction();
		try
		{
			$saved = $this->persistCollection($name, $userId, $position);

			$aclResult = CollectionAccessService::replaceCollectionPermissions(
				(int)$saved->getId(),
				$permissions ?? [],
				$userId,
				$policyLevel ?? CollectionAccessService::LEVEL_NONE,
			);
			if (!$aclResult->isSuccess())
			{
				$connection->rollbackTransaction();

				throw new SystemException($this->buildSaveErrorMessage(
					$aclResult->getErrorMessages(),
					'Unable to install collection permissions.',
				));
			}

			$this->ensureMainDocument((int)$saved->getId(), $userId);

			$connection->commitTransaction();

			return $saved;
		}
		catch (SystemException $e)
		{
			throw $e;
		}
		catch (\Throwable $e)
		{
			try
			{
				$connection->rollbackTransaction();
			}
			catch (\Throwable)
			{
			}

			throw new SystemException($e->getMessage());
		}
	}

	private function persistCollection(string $name, int $userId, int $position): Collection
	{
		$resolvedPosition = $position > 0
			? $position
			: $this->repository->getMaxPosition() + CollectionRepository::POSITION_GAP
		;

		$collection = CollectionTable::createObject()
			->setName($name)
			->setCreatedBy($userId)
			->setPosition($resolvedPosition)
			->setUpdatedBy($userId)
		;

		$saveResult = $this->repository->save($collection);
		if (!$saveResult->isSuccess())
		{
			throw new SystemException($this->buildSaveErrorMessage(
				$saveResult->getErrorMessages(),
				'Unable to save collection.'
			));
		}

		$saved = $saveResult->getData()['collection'] ?? null;
		if (!$saved instanceof Collection)
		{
			throw new SystemException('Unable to save collection.');
		}

		return $saved;
	}

	/**
	 * Updates an existing collection. Returns null if collection not found.
	 *
	 * @throws SystemException on persistence failure
	 */
	public function update(int $id, string $name, int $userId): ?Collection
	{
		$collection = $this->repository->getById($id);
		if ($collection === null)
		{
			return null;
		}

		$collection->setName($name);
		$collection->setUpdatedBy($userId);

		$saveResult = $this->repository->save($collection);
		if (!$saveResult->isSuccess())
		{
			throw new SystemException($this->buildSaveErrorMessage(
				$saveResult->getErrorMessages(),
				'Unable to save collection.'
			));
		}

		$saved = $saveResult->getData()['collection'] ?? null;
		if (!$saved instanceof Collection)
		{
			throw new SystemException('Unable to save collection.');
		}

		return $saved;
	}

	/**
	 * System-level collection creation without a user actor or default ACL.
	 * CREATED_BY / UPDATED_BY are set to SystemUser::ID. The caller is
	 * responsible for installing access policy via
	 * CollectionAccessService::installSystemPolicy().
	 *
	 * Use only for module bootstrap (welcome content). Do NOT call from
	 * user-facing code — use create() instead.
	 *
	 * Returns the new collection id or null on failure.
	 */
	public function createAsSystem(string $name, int $position = 0): ?int
	{
		$resolvedPosition = $position > 0
			? $position
			: $this->repository->getMaxPosition() + CollectionRepository::POSITION_GAP
		;

		$collection = CollectionTable::createObject()
			->setName($name)
			->setCreatedBy(SystemUser::ID)
			->setPosition($resolvedPosition)
			->setUpdatedBy(SystemUser::ID)
		;

		$saveResult = $this->repository->save($collection);
		if (!$saveResult->isSuccess())
		{
			return null;
		}

		$saved = $saveResult->getData()['collection'] ?? null;
		if (!$saved instanceof Collection)
		{
			return null;
		}

		$id = (int)$saved->getId();
		if ($id <= 0)
		{
			return null;
		}

		// Eager main document, atomic with the collection: the caller (WelcomeContentInstaller)
		// wraps this in a transaction and rolls back on null, so no orphan collection remains.
		if ($this->mainDocumentService->ensureMainDocument($id, SystemUser::ID) === null)
		{
			return null;
		}

		return $id;
	}

	/**
	 * Creates the collection's main document in the caller's transaction. Failure aborts
	 * the whole create so a collection is never left without its main document.
	 *
	 * @throws SystemException
	 */
	private function ensureMainDocument(int $collectionId, int $authorId): void
	{
		if ($this->mainDocumentService->ensureMainDocument($collectionId, $authorId) === null)
		{
			throw new SystemException('Unable to create the collection main document.');
		}
	}

	private function buildSaveErrorMessage(array $errorMessages, string $defaultMessage): string
	{
		return empty($errorMessages) ? $defaultMessage : implode(', ', $errorMessages);
	}
}

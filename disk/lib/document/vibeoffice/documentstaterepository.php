<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice;

use Bitrix\Disk\Document\Models\DocumentSession;
use Bitrix\Disk\ExternalLink;
use Bitrix\Disk\File;
use Bitrix\Disk\Version;
use Bitrix\Main\Application;
use Bitrix\Main\Diag\LoggerFactory;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\UuidGenerator;

class DocumentStateRepository
{
	private const LOCK_TIMEOUT = 5;

	/** Diagnostic channel of the document state; `.settings.php` redirects it by id. */
	private const LOGGER_ID = 'disk.vibeoffice.state';

	private static array $heldLocks = [];

	public function __construct(private readonly ?Configuration $configuration = null)
	{
	}

	public function getByKey(string $hostDocKey): ?array
	{
		return Models\DocumentStateTable::query()
			->setSelect(['*'])
			->where('HOST_DOC_KEY', $hostDocKey)
			->fetch() ?: null
		;
	}

	public function getSnapshot(string $hostDocKey, int $snapshotSeq): ?array
	{
		return Models\SavedSnapshotTable::query()
			->setSelect(['*'])
			->where('HOST_DOC_KEY', $hostDocKey)
			->where('SNAPSHOT_SEQ', $snapshotSeq)
			->fetch() ?: null
		;
	}

	/**
	 * Lock order: document named lock, transaction, optional object row, state row, session/ledger rows.
	 * The callback must not perform network I/O. Nested transactions leave the caller's commit intact.
	 * The state row stays locked until the outer commit, including after the named lock is released.
	 *
	 * @param callable(array, ?File): Result $mutation
	 */
	public function withLockedDocument(string $hostDocKey, callable $mutation, bool $lockObject = false): Result
	{
		return $this->runLocked(
			self::getDocumentLockName($hostDocKey),
			function () use ($hostDocKey, $mutation, $lockObject): Result {
				$file = null;
				if ($lockObject)
				{
					$state = $this->getByKey($hostDocKey);
					$file = $state ? $this->loadLockedFile((int)$state['OBJECT_ID']) : null;
					if (!$file)
					{
						return $this->failure('Vibeoffice document was not found.', 'vibeoffice_document_not_found');
					}
				}

				$query = Models\DocumentStateTable::query()
					->setSelect(['*'])
					->where('HOST_DOC_KEY', $hostDocKey)
				;
				$state = Application::getConnection()->query($query->getQuery() . ' FOR UPDATE')->fetch();
				if (!$state)
				{
					return $this->failure('Vibeoffice document was not found.', 'vibeoffice_document_not_found');
				}

				return $mutation($state, $file);
			},
		);
	}

	public static function getDocumentLockName(string $hostDocKey): string
	{
		return 'disk_vo_document_' . md5($hostDocKey);
	}

	public static function isDocumentLocked(string $hostDocKey): bool
	{
		$lockId = spl_object_id(Application::getConnection()) . ':' . self::getDocumentLockName($hostDocKey);

		return isset(self::$heldLocks[$lockId]);
	}

	/** The optional source preserves a shared local hash across a user fork, including legacy mode. */
	public function bindNewGeneration(DocumentSession $session, ?DocumentSession $source = null): Result
	{
		$file = File::loadById((int)$session->getObjectId());
		if (!$file || !$session->getId())
		{
			return $this->failure('Vibeoffice source file was not found.', 'vibeoffice_source_not_found');
		}

		try
		{
			$scope = HostDocumentKey::createScope($session, $file);
		}
		catch (\Throwable)
		{
			return $this->failure('Invalid Vibeoffice document scope.', 'vibeoffice_scope_invalid');
		}

		return $this->runLocked(
			'disk_vo_scope_' . md5(HostDocumentKey::scopeIdentity($scope)),
			function () use ($session, $source, $scope): Result {
				$file = $this->loadLockedFile($scope['OBJECT_ID']);
				if (!$file)
				{
					return $this->failure('Vibeoffice source file was not found.', 'vibeoffice_source_not_found');
				}

				$session = DocumentSession::loadById((int)$session->getId());
				if (!$file || !$session || HostDocumentKey::createScope($session, $file) !== $scope)
				{
					return $this->failure('Vibeoffice document scope changed.', 'vibeoffice_scope_invalid');
				}

				if (!$this->hasValidExternalLink($session, $file))
				{
					return $this->failure('Invalid Vibeoffice external link.', 'vibeoffice_scope_invalid');
				}

				$metadata = DocumentSessionManager::extractVoMetadata($session);
				$pinnedKey = $metadata[HostDocumentKey::CONTEXT_KEY] ?? null;
				if (is_string($pinnedKey) && $pinnedKey !== '')
				{
					$state = $this->findStateForUpdate($pinnedKey);
					if (!$state || !$this->matchesScope($state, $scope))
					{
						return $this->failure('Invalid Vibeoffice document binding.', 'vibeoffice_binding_invalid');
					}

					return $this->boundResult($session, $state);
				}

				$content = $this->readContent($session, $file);
				if ($content === null)
				{
					return $this->failure('Vibeoffice source version was not found.', 'vibeoffice_source_not_found');
				}

				$generation = HostDocumentKey::contentGeneration(
					$scope,
					$content['EXPECTED_FILE_ID'],
					$content['EXPECTED_CONTENT_VERSION'],
				);
				$mode = HostDocumentKey::MODE_LEGACY;
				$key = $session->getExternalHash();
				if ($source !== null)
				{
					if (
						$source->getExternalHash() !== $session->getExternalHash()
						|| HostDocumentKey::createScope($source, $file) !== $scope
					)
					{
						return $this->failure('Invalid Vibeoffice fork source.', 'vibeoffice_binding_invalid');
					}

					$key = HostDocumentKey::resolve($source);
					$mode = HostDocumentKey::isStable($key) ? HostDocumentKey::MODE_STABLE : $mode;
				}
				elseif (($this->configuration ?? new Configuration())->isStableDocumentKeyEnabled())
				{
					$mode = HostDocumentKey::MODE_STABLE;
					$state = $this->findLatestStable($scope);
					if ($state && $this->matchesContent($state, $content) && !(int)$state['CONFLICTED'])
					{
						$key = $state['HOST_DOC_KEY'];
					}
					else
					{
						if ($session->isEdit())
						{
							$generation = hash('sha256', $generation . ':' . UuidGenerator::generateV4());
						}
						$key = HostDocumentKey::create($scope, $generation);
					}
				}

				$state = $this->findStateForUpdate($key);
				if ($state !== null && !$this->matchesScope($state, $scope))
				{
					return $this->failure('Invalid Vibeoffice document binding.', 'vibeoffice_binding_invalid');
				}
				if ($state === null)
				{
					if ($source !== null && $mode === HostDocumentKey::MODE_STABLE)
					{
						return $this->failure('Vibeoffice fork state was not found.', 'vibeoffice_binding_invalid');
					}

					$add = Models\DocumentStateTable::add($scope + $content + [
						'HOST_DOC_KEY' => $key,
						'KEY_MODE' => $mode,
						'CONTENT_GENERATION' => $generation,
					]);
					if (!$add->isSuccess())
					{
						return (new Result())->addErrors($add->getErrors());
					}
					$state = $this->findStateForUpdate($key);
				}

				$metadata = [HostDocumentKey::CONTEXT_KEY => $key];
				if (!$session->isVersion())
				{
					$metadata[DocumentSessionManager::CONTEXT_VO_CONTENT_VERSION_KEY] =
						$content['EXPECTED_CONTENT_VERSION'];
					$metadata['contentFileId'] = $content['EXPECTED_FILE_ID'];
				}
				if (!(new DocumentSessionManager())->persistSessionMetadata($session, $metadata))
				{
					return $this->failure('Could not pin the Vibeoffice document key.', 'vibeoffice_binding_failed');
				}

				return $this->boundResult(DocumentSession::loadById((int)$session->getId()), $state);
			},
		);
	}

	/** Create and pin a local generation atomically, including direct model fork/clone paths. */
	public function createSession(callable $factory, ?DocumentSession $source = null): Result
	{
		return $this->runTransaction(function () use ($factory, $source): Result {
			$session = $factory();

			return $session instanceof DocumentSession
				? $this->bindNewGeneration($session, $source)
				: $this->failure('Could not create a Vibeoffice session.', 'vibeoffice_session_failed');
		});
	}

	public function ensureSessionState(DocumentSession $session): Result
	{
		return $this->bindNewGeneration($session, $session);
	}

	public function isCurrentGeneration(DocumentSession $session, File $freshFile): bool
	{
		if ($session->isVersion())
		{
			return true;
		}

		$state = $this->getByKey(HostDocumentKey::resolve($session));
		if ($state)
		{
			return !(int)$state['CONFLICTED'] && $this->matchesContent($state, $this->liveContent($freshFile));
		}

		return !$session->isView() || !$session->isOutdatedByFileContent();
	}

	private function findLatestStable(array $scope): ?array
	{
		$query = Models\DocumentStateTable::query()->setSelect(['*']);
		foreach ($scope as $field => $value)
		{
			$query->where($field, $value);
		}

		$query
			->where('KEY_MODE', HostDocumentKey::MODE_STABLE)
			->setOrder(['ID' => 'DESC'])
			->setLimit(1);

		return Application::getConnection()->query($query->getQuery() . ' FOR UPDATE')->fetch() ?: null;
	}

	private function findStateForUpdate(string $key): ?array
	{
		$query = Models\DocumentStateTable::query()->setSelect(['*'])->where('HOST_DOC_KEY', $key);

		return Application::getConnection()->query($query->getQuery() . ' FOR UPDATE')->fetch() ?: null;
	}

	private function matchesScope(array $state, array $scope): bool
	{
		foreach ($scope as $field => $value)
		{
			if ((int)$state[$field] !== $value)
			{
				return false;
			}
		}

		return true;
	}

	private function matchesContent(array $state, array $content): bool
	{
		return (int)$state['EXPECTED_FILE_ID'] === $content['EXPECTED_FILE_ID']
			&& (int)$state['EXPECTED_CONTENT_VERSION'] === $content['EXPECTED_CONTENT_VERSION']
			&& ((int)$state['SESSION_TYPE'] === DocumentSession::TYPE_VIEW || (string)$state['EXPECTED_ETAG'] === $content['EXPECTED_ETAG']);
	}

	private function readContent(DocumentSession $session, File $file): ?array
	{
		if (!$session->isVersion())
		{
			return $this->liveContent($file);
		}

		$version = Version::loadById((int)$session->getVersionId());
		if (!$version || (int)$version->getObjectId() !== (int)$file->getRealObjectId())
		{
			return null;
		}

		return [
			'EXPECTED_FILE_ID' => (int)$version->getFileId(),
			'EXPECTED_CONTENT_VERSION' => (int)$version->getGlobalContentVersion(),
			'EXPECTED_ETAG' => '',
		];
	}

	private function liveContent(File $file): array
	{
		return [
			'EXPECTED_FILE_ID' => (int)$file->getFileId(),
			'EXPECTED_CONTENT_VERSION' => (int)$file->getGlobalContentVersion(),
			'EXPECTED_ETAG' => (string)$file->getEtag(),
		];
	}

	private function hasValidExternalLink(DocumentSession $session, File $file): bool
	{
		if (!$session->getExternalLinkId())
		{
			return true;
		}

		$link = ExternalLink::loadById($session->getExternalLinkId());

		return $link
			&& (int)$link->getObject()?->getRealObjectId() === (int)$file->getRealObjectId()
			&& (int)$link->getVersionId() === (int)$session->getVersionId();
	}

	private function loadLockedFile(int $objectId): ?File
	{
		$query = \Bitrix\Disk\Internals\ObjectTable::query()->setSelect(['*'])->where('ID', $objectId);
		$row = Application::getConnection()->query($query->getQuery() . ' FOR UPDATE')->fetch();

		return $row ? File::buildFromArray($row) : null;
	}

	private function boundResult(DocumentSession $session, array $state): Result
	{
		return (new Result())->setData([
			'hostDocKey' => $state['HOST_DOC_KEY'],
			'state' => $state,
			'session' => $session,
		]);
	}

	private function runLocked(string $lockName, callable $mutation): Result
	{
		$connection = Application::getConnection();
		$lockId = spl_object_id($connection) . ':' . $lockName;
		if (!isset(self::$heldLocks[$lockId]) && !$connection->lock($lockName, self::LOCK_TIMEOUT))
		{
			return $this->failure('Could not lock the Vibeoffice document.', 'vibeoffice_lock_timeout');
		}
		self::$heldLocks[$lockId] = (self::$heldLocks[$lockId] ?? 0) + 1;

		try
		{
			return $this->runTransaction($mutation);
		}
		finally
		{
			if (--self::$heldLocks[$lockId] === 0)
			{
				unset(self::$heldLocks[$lockId]);
				$connection->unlock($lockName);
			}
		}
	}

	private function runTransaction(callable $mutation): Result
	{
		$connection = Application::getConnection();
		$transactionStarted = false;
		$savepoint = 'disk_vo_' . bin2hex(random_bytes(8));
		try
		{
			$connection->startTransaction();
			$transactionStarted = true;
			$connection->queryExecute('SAVEPOINT ' . $savepoint);
			$result = $mutation();
			if (!$result->isSuccess())
			{
				$connection->queryExecute('ROLLBACK TO SAVEPOINT ' . $savepoint);
			}
			$connection->queryExecute('RELEASE SAVEPOINT ' . $savepoint);
			$connection->commitTransaction();
			$transactionStarted = false;

			return $result;
		}
		catch (\Throwable $exception)
		{
			if ($transactionStarted)
			{
				// Core nested rollback throws after restoring its savepoint; balance our own scope instead.
				$connection->queryExecute('ROLLBACK TO SAVEPOINT ' . $savepoint);
				$connection->queryExecute('RELEASE SAVEPOINT ' . $savepoint);
				$connection->commitTransaction();
			}
			$this->logStateException($exception);

			return $this->failure('Could not persist Vibeoffice state.', 'vibeoffice_state_failed');
		}
	}

	/**
	 * Keeps a rolled-back state mutation diagnosable: deadlocks and lock timeouts otherwise leave
	 * only the generic error. The record carries neither the document key nor a session hash.
	 */
	private function logStateException(\Throwable $exception): void
	{
		(new LoggerFactory())
			->createById(self::LOGGER_ID, isCheckEnabledFromRegistry: false)
			?->error('Vibeoffice state: {exception}: {message}.', [
				'exception' => $exception::class,
				'message' => $exception->getMessage(),
			])
		;
	}

	private function failure(string $message, string $code): Result
	{
		return (new Result())->addError(new Error($message, $code));
	}
}

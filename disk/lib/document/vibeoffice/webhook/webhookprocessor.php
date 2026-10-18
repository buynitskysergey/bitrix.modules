<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice\Webhook;

use Bitrix\Disk\Document\Models\DocumentSession;
use Bitrix\Disk\Document\Models\DocumentSessionTable;
use Bitrix\Disk\Document\Vibeoffice\Configuration;
use Bitrix\Disk\Document\Vibeoffice\DocumentSessionManager;
use Bitrix\Disk\Document\Vibeoffice\DocumentStateRepository;
use Bitrix\Disk\Document\Vibeoffice\Models\PlatformSessionTable;
use Bitrix\Disk\Document\Vibeoffice\SavedDocumentWriterInterface;
use Bitrix\Disk\Document\Vibeoffice\SavedDocumentWriter;
use Bitrix\Disk\Document\Vibeoffice\SessionRegistry;
use Bitrix\Disk\Document\Vibeoffice\TerminalSessionFinalizer;
use Bitrix\Disk\Document\Vibeoffice\TerminalSessionFinalizerInterface;
use Bitrix\Disk\Driver;
use Bitrix\Disk\File;
use Bitrix\Disk\SystemUser;
use Bitrix\Main\Application;
use Bitrix\Main\Data\Storage\PersistentStorageInterface;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Diag\LoggerFactory;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Security\Random;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\MimeType;
use Bitrix\Main\Web\Uri;
use Closure;
use Throwable;

/**
 * Dispatches verified vibeoffice webhooks.
 *
 * The HMAC signature, timestamp window and content type are already enforced by the
 * controller prefilters. This processor adds idempotency by `X-VO-Delivery` and dispatches
 * by `X-VO-Event`.
 *
 * Pinned document state uses the durable saved writer and exact UUID finalization.
 * Historical sessions without pinned state retain the legacy upload/finalization path.
 */
final class WebhookProcessor
{
	public const EVENT_SAVED = 'document.saved';
	public const EVENT_FAILOVER = 'document.failover';
	public const EVENT_SESSION_CLOSED = 'document.session-closed';

	/** Stages of a `document.saved` that is applied in several steps. */
	private const SAVED_STAGE_VERSION_APPLIED = 'version-applied';
	private const SAVED_STAGE_SIDE_EFFECTS_APPLIED = 'side-effects-applied';

	/** Persistent storage key prefix of the per-delivery saved-stage checkpoint. */
	private const SAVED_STAGE_KEY_PREFIX = 'disk_vo_saved_delivery_stage_';

	/** Legacy checkpoint lifetime; durable deliveries do not use this cache. */
	private const SAVED_STAGE_TTL = 24 * 3600;

	/** Terminal sources that finish editing and deactivate local sessions. */
	private const TERMINAL_SOURCES = ['save', 'forgotten'];

	/** Named application lock prefix that serialises processing of one delivery. */
	private const LOCK_NAME_PREFIX = 'disk_vo_delivery_';

	/** Seconds to wait for the per-delivery lock before giving up (concurrent retry in flight). */
	private const LOCK_TIMEOUT = 15;

	/** Diagnostic channel of the asynchronous delivery path; `.settings.php` redirects it by id. */
	private const LOGGER_ID = 'disk.vibeoffice.webhook';

	private TerminalSessionFinalizerInterface $terminalSessionFinalizer;
	private Closure $deliveryMarkerWriter;
	private Closure $savedVersionUploader;
	private Closure $savedSideEffectsHandler;
	private SessionRegistry $registry;
	private WebhookScopeResolver $scopeResolver;
	private readonly SavedDocumentWriterInterface $savedDocumentWriter;

	/**
	 * @param callable(array): Result|null $deliveryMarkerWriter Internal ORM failure seam for tests.
	 * @param callable(array, string): Result|null $savedVersionUploader Internal saved-version seam for tests.
	 * @param callable(array): Result|null $savedSideEffectsHandler Internal saved side-effects seam for tests.
	 */
	public function __construct(
		?TerminalSessionFinalizerInterface $terminalSessionFinalizer = null,
		?callable $deliveryMarkerWriter = null,
		?callable $savedVersionUploader = null,
		?callable $savedSideEffectsHandler = null,
		?SavedDocumentWriterInterface $savedDocumentWriter = null,
		?DocumentStateRepository $documentStateRepository = null,
	)
	{
		$documentStateRepository ??= new DocumentStateRepository();
		$this->savedDocumentWriter = $savedDocumentWriter ?? new SavedDocumentWriter(repository: $documentStateRepository);
		$this->registry = new SessionRegistry($documentStateRepository);
		$this->scopeResolver = new WebhookScopeResolver($documentStateRepository, $this->registry);
		$this->terminalSessionFinalizer = $terminalSessionFinalizer ?? new TerminalSessionFinalizer(repository: $documentStateRepository);
		$this->deliveryMarkerWriter = Closure::fromCallable(
			$deliveryMarkerWriter
			?? static function (array $fields): Result {
				$id = $fields['ID'] ?? null;
				unset($fields['ID']);

				return $id === null ? DeliveryTable::add($fields) : DeliveryTable::update((int)$id, $fields);
			}
		);
		$this->savedVersionUploader = Closure::fromCallable(
			$savedVersionUploader
			?? fn(array $payload, string $externalHash): Result => $this->uploadSavedVersion($payload, $externalHash)
		);
		$this->savedSideEffectsHandler = Closure::fromCallable(
			$savedSideEffectsHandler
			?? fn(array $data): Result => $this->dispatchSavedSideEffects($data)
		);
	}

	public function process(int $deliveryId, string $event, array $payload): Result
	{
		$result = $this->dispatch($deliveryId, $event, $payload);
		if (!WebhookEventPolicy::isPermanentFailure($result))
		{
			return $result;
		}
		$reasons = array_map(
			static fn(Error $error): string => $error->getCode() . ': ' . $error->getMessage(),
			$result->getErrors(),
		);

		return $this->acknowledgePermanent(
			"Vibeoffice webhook: permanent failure; event={$event}; delivery={$deliveryId}; "
			. implode(' ', $reasons)
		);
	}

	private function dispatch(int $deliveryId, string $event, array $payload): Result
	{
		if ($deliveryId <= 0)
		{
			return (new Result())->addError(new Error('Invalid delivery ID.', 'validation'));
		}
		$connection = Application::getConnection();
		$lockName = self::LOCK_NAME_PREFIX . $deliveryId;
		if (!$connection->lock($lockName, self::LOCK_TIMEOUT))
		{
			return (new Result())->addError(new Error('Could not acquire the delivery lock.', 'lock_timeout'));
		}

		try
		{
			$delivery = $this->getDelivery($deliveryId);
			if ($delivery && ($delivery['EVENT'] !== $event || (
				$delivery['HOST_DOC_KEY'] !== null && $delivery['HOST_DOC_KEY'] !== ($payload['host_doc_key'] ?? null)
			)))
			{
				return (new Result())->addError(new Error('Delivery identity changed.', 'validation'));
			}
			if ($delivery && $delivery['STATUS'] === DeliveryTable::STATUS_DONE
				&& ($event !== self::EVENT_SAVED || $delivery['HOST_DOC_KEY'] === null || (int)$delivery['RESULT_OBJECT_ID'] <= 0))
			{
				return new Result();
			}

			if (!in_array($event, [self::EVENT_SAVED, self::EVENT_FAILOVER, self::EVENT_SESSION_CLOSED], true))
			{
				$this->handleUnsupportedEvent($event);

				return $this->markDeliveryProcessed($deliveryId, $event, $this->extractExternalHash($payload));
			}

			$classification = (new WebhookEventPolicy())->classify($event, $payload);
			if (!$classification->isSuccess())
			{
				return $classification;
			}
			$policy = $classification->getData();
			$key = $policy['hostDocKey'];
			$scopeResult = $this->scopeResolver->resolveState($key);
			if ($scopeResult->isSuccess() && (!$scopeResult->getData()['state']
				|| ($policy['legacy'] && $scopeResult->getData()['legacy'])))
			{
				$scopeResult = $this->scopeResolver->resolve($key);
			}
			if (!$scopeResult->isSuccess())
			{
				return $this->ignoreDelivery($deliveryId, $event, $key, 'scope_unknown');
			}
			$scope = $scopeResult->getData();
			if ((!$policy['identityKnown'] && (!$policy['legacy'] || $event === self::EVENT_FAILOVER))
				|| ($policy['legacy'] && !$scope['legacy'] && $event !== self::EVENT_FAILOVER)
				|| (!$scope['state'] && (!$policy['legacy'] || $scope['sessions'] === [])))
			{
				return $this->ignoreDelivery($deliveryId, $event, $key, 'origin_unknown');
			}

			if ($delivery && $delivery['STATUS'] === DeliveryTable::STATUS_DONE)
			{
				if (($delivery['SNAPSHOT_SEQ'] === null ? null : (int)$delivery['SNAPSHOT_SEQ']) !== $policy['snapshotSeq'])
				{
					return (new Result())->addError(new Error('Delivery snapshot changed.', 'validation'));
				}

				return $this->completeSavedDelivery((int)$delivery['ID'], $policy);
			}

			if ($event === self::EVENT_SAVED && $scope['state'])
			{
				return $this->processSavedDelivery($deliveryId, $payload, $policy, $scope, $delivery);
			}
			if ($event === self::EVENT_SESSION_CLOSED && !$policy['legacy'])
			{
				return $this->terminalSessionFinalizer->withDocumentTransaction(
					$key,
					function () use ($deliveryId, $event, $key, $policy): Result {
						$closed = $this->registry->applyClosed($key, $policy['terminated'], $policy['retained']);
						if (!$closed->isSuccess())
						{
							return $closed;
						}
						$finalized = $this->terminalSessionFinalizer->finalizeSessions($closed->getData()['sessionIds']);

						return $finalized->isSuccess()
							? $this->markDeliveryProcessed($deliveryId, $event, null, ['HOST_DOC_KEY' => $key])
							: $finalized;
					},
				);
			}
			if ($event === self::EVENT_FAILOVER && $scope['state'])
			{
				return $this->terminalSessionFinalizer->withDocumentTransaction(
					$key,
					function () use ($payload, $scope, $deliveryId, $event, $key): Result {
						$recipients = $scope['legacy'] && $scope['sessions'] !== []
							? (new Result())->setData(['sessions' => $scope['sessions']])
							: $this->scopeResolver->resolveRecipients($key, $payload['editor_key'], $payload['rev']);
						if (!$recipients->isSuccess())
						{
							return $recipients;
						}
						$updated = $this->updateFailoverMetadata($payload, $recipients->getData()['sessions']);

						return $updated->isSuccess()
							? $this->markDeliveryProcessed($deliveryId, $event, null, ['HOST_DOC_KEY' => $key]) : $updated;
					},
				);
			}

			$processed = match ($event)
			{
				self::EVENT_SAVED => $this->handleSaved($deliveryId, $payload),
				self::EVENT_SESSION_CLOSED => $this->handleSessionClosed($payload),
				default => $this->updateFailoverMetadata($payload, $scope['sessions']),
			};
			if (!$processed->isSuccess())
			{
				return $processed;
			}
			$fields = $scope['state'] || ($processed->getData()['savedContentApplied'] ?? true) === false
				? ['HOST_DOC_KEY' => $key] : [];

			return $this->markDeliveryProcessed($deliveryId, $event, $key, $fields);
		}
		catch (Throwable $exception)
		{
			$this->logDeliveryException($exception, $event, $deliveryId);

			return (new Result())->addError(new Error('Could not process the Vibeoffice delivery.', 'vibeoffice_delivery_failed'));
		}
		finally
		{
			$connection->unlock($lockName);
		}
	}

	private function getDelivery(int $deliveryId): ?array
	{
		return DeliveryTable::query()->setSelect(['*'])->where('DELIVERY_ID', $deliveryId)->fetch() ?: null;
	}

	/**
	 * `DELIVERY_ID` carries a unique index, so an existing ledger row - a saved delivery that
	 * already reached PENDING and then took another branch on a retry - has to be updated, never
	 * inserted a second time.
	 */
	private function markDeliveryProcessed(int $deliveryId, string $event, ?string $externalHash, array $extra = []): Result
	{
		$existing = $this->getDelivery($deliveryId);
		if ($existing)
		{
			$extra['ID'] = (int)$existing['ID'];
		}

		return $this->writeDelivery($extra + [
			'DELIVERY_ID' => $deliveryId,
			'EXTERNAL_HASH' => $externalHash,
			'EVENT' => $event,
			'STATUS' => DeliveryTable::STATUS_DONE,
			'PROCESSED_TIME' => new DateTime(),
		]);
	}

	private function writeDelivery(array $fields): Result
	{
		try
		{
			return ($this->deliveryMarkerWriter)($fields);
		}
		catch (Throwable)
		{
			return (new Result())->addError(new Error('Could not persist the delivery.', 'delivery_marker_failed'));
		}
	}

	private function ignoreDelivery(int $deliveryId, string $event, string $key, string $reason): Result
	{
		$this->acknowledgePermanent("Vibeoffice webhook: {$reason}; event={$event}; delivery={$deliveryId}.");

		return $this->markDeliveryProcessed($deliveryId, $event, null, ['HOST_DOC_KEY' => $key]);
	}

	private function processSavedDelivery(int $deliveryId, array $payload, array $policy, array $scope, ?array $delivery): Result
	{
		$key = $policy['hostDocKey'];
		if ($delivery === null)
		{
			$created = $this->markDeliveryProcessed($deliveryId, self::EVENT_SAVED, null, [
				'HOST_DOC_KEY' => $key,
				'STATUS' => DeliveryTable::STATUS_PENDING,
				'SNAPSHOT_SEQ' => $policy['snapshotSeq'],
			]);
			if (!$created->isSuccess())
			{
				return $created;
			}
			$delivery = $this->getDelivery($deliveryId);
		}
		if ($delivery === null)
		{
			return (new Result())->addError(new Error('Saved delivery was not persisted.', 'delivery_marker_failed'));
		}
		if (($delivery['SNAPSHOT_SEQ'] === null ? null : (int)$delivery['SNAPSHOT_SEQ']) !== $policy['snapshotSeq'])
		{
			return (new Result())->addError(new Error('Delivery snapshot changed.', 'validation'));
		}
		$ledgerId = (int)$delivery['ID'];
		if ($delivery['STATUS'] !== DeliveryTable::STATUS_APPLIED)
		{
			$prepared = $this->savedDocumentWriter->prepare($ledgerId, $payload);
			if (!$prepared->isSuccess())
			{
				return $prepared;
			}
			$applied = $this->savedDocumentWriter->apply($ledgerId, $payload, $prepared->getData()['stagedFile']);
			if (!$applied->isSuccess())
			{
				return $applied;
			}
		}

		$finished = $this->terminalSessionFinalizer->withDocumentTransaction(
			$key,
			function () use ($deliveryId, $ledgerId, $key, $policy, $scope): Result {
				$delivery = $this->getDelivery($deliveryId);
				if ($delivery['STATUS'] !== DeliveryTable::STATUS_APPLIED || (int)$delivery['RESULT_OBJECT_ID'] <= 0)
				{
					return (new Result())->addError(new Error('Saved content is not committed.', 'vibeoffice_saved_not_applied'));
				}
				$finalization = $this->finalizeSavedSessions($policy, $scope);
				if (!$finalization->isSuccess())
				{
					return $finalization;
				}

				return $this->writeDelivery([
					'ID' => $ledgerId,
					'STATUS' => DeliveryTable::STATUS_DONE,
					'PROCESSED_TIME' => new DateTime(),
				]);
			},
		);

		return $finished->isSuccess() ? $this->completeSavedDelivery($ledgerId, $policy) : $finished;
	}

	private function finalizeSavedSessions(array $policy, array $scope): Result
	{
		if (!in_array($policy['finalization'], [WebhookEventPolicy::FINALIZATION_THIS_EVENT, WebhookEventPolicy::FINALIZATION_LEGACY], true))
		{
			return new Result();
		}
		$key = $policy['hostDocKey'];
		if ($policy['finalization'] === WebhookEventPolicy::FINALIZATION_THIS_EVENT)
		{
			// Forgotten and a delayed retained decision may arrive in either order.
			$rows = PlatformSessionTable::query()->setSelect(['DOCUMENT_SESSION_ID'])
				->where('HOST_DOC_KEY', $key)->where('EDITOR_KEY', $policy['editorKey'])->where('REV', $policy['rev'])
				->where('STATUS', PlatformSessionTable::STATUS_TERMINATED)->whereNotNull('API_SESSION_ID')->fetchAll();

			$ids = array_map('intval', array_column($rows, 'DOCUMENT_SESSION_ID'));

			return $this->terminalSessionFinalizer->finalizeSessions($ids);
		}
		if ($policy['identityKnown'])
		{
			$terminated = $this->registry->terminateEditorGeneration($key, $policy['editorKey'], $policy['rev']);
		}
		else
		{
			$rows = PlatformSessionTable::query()->setSelect(['API_SESSION_ID'])
				->where('HOST_DOC_KEY', $key)->whereNotNull('API_SESSION_ID')->fetchAll();
			$terminated = $this->registry->applyClosed($key, array_column($rows, 'API_SESSION_ID'), []);
		}
		if (!$terminated->isSuccess())
		{
			return $terminated;
		}
		$ids = $terminated->getData()['sessionIds'];
		if ($policy['finalization'] === WebhookEventPolicy::FINALIZATION_LEGACY && !$policy['identityKnown'])
		{
			$ids = array_merge($ids, array_map(static fn(DocumentSession $session): int => (int)$session->getId(), $scope['sessions']));
		}

		return $this->terminalSessionFinalizer->finalizeSessions($ids);
	}

	private function completeSavedDelivery(int $ledgerId, array $policy): Result
	{
		$generation = $policy['identityKnown'] && !$policy['legacy'] ? [
			'hostDocKey' => $policy['hostDocKey'], 'editorKey' => $policy['editorKey'], 'rev' => $policy['rev'],
		] : null;

		return $this->savedDocumentWriter->complete($ledgerId, $generation);
	}

	/** Historical sessions without pinned state use the legacy source rules and cache checkpoint. */
	private function handleSaved(int $deliveryId, array $payload): Result
	{
		$result = new Result();

		$externalHash = $this->extractExternalHash($payload);
		if ($externalHash === null)
		{
			return $result->addError(new Error('Missing host_doc_key in document.saved webhook.', 'validation'));
		}

		$source = mb_strtolower((string)($payload['source'] ?? 'save'));
		$savedDeliveryStage = $this->getSavedDeliveryStage($deliveryId);
		$savedData = [];

		if ($savedDeliveryStage === null)
		{
			$uploadResult = ($this->savedVersionUploader)($payload, $externalHash);
			if (!$uploadResult->isSuccess())
			{
				return $result->addErrors($uploadResult->getErrors());
			}

			$savedData = $uploadResult->getData();
			if (($savedData['savedContentApplied'] ?? true) === false)
			{
				return $result->setData(['savedContentApplied' => false]);
			}

			$versionMarkerResult = $this->markSavedDeliveryStage($deliveryId, self::SAVED_STAGE_VERSION_APPLIED);
			if (!$versionMarkerResult->isSuccess())
			{
				return $result->addErrors($versionMarkerResult->getErrors());
			}

			$savedDeliveryStage = self::SAVED_STAGE_VERSION_APPLIED;
		}

		if (in_array($source, self::TERMINAL_SOURCES, true))
		{
			$finalizationResult = $this->terminalSessionFinalizer->finalizeDocument($externalHash);
			if (!$finalizationResult->isSuccess())
			{
				return $result->addErrors($finalizationResult->getErrors());
			}
		}

		if ($savedDeliveryStage !== self::SAVED_STAGE_SIDE_EFFECTS_APPLIED)
		{
			if ($savedData === [])
			{
				$savedData = $this->loadSavedSideEffectsData($externalHash);
			}

			$sideEffectsResult = ($this->savedSideEffectsHandler)($savedData);
			if (!$sideEffectsResult->isSuccess())
			{
				return $result->addErrors($sideEffectsResult->getErrors());
			}

			$sideEffectsMarkerResult = $this->markSavedDeliveryStage(
				$deliveryId,
				self::SAVED_STAGE_SIDE_EFFECTS_APPLIED,
			);
			if (!$sideEffectsMarkerResult->isSuccess())
			{
				return $result->addErrors($sideEffectsMarkerResult->getErrors());
			}
		}

		return $result;
	}

	private function uploadSavedVersion(array $payload, string $externalHash): Result
	{
		$result = new Result();

		$fileUrl = (string)($payload['file_url'] ?? '');
		if ($fileUrl === '')
		{
			return $result->addError(new Error('Missing file_url in document.saved webhook.', 'validation'));
		}

		// `file_url` is attacker-influenceable payload data, so never dereference it blindly.
		// Accept only http(s) URLs pointing at the configured
		// platform host before it is downloaded and stored as a Disk version.
		if (!$this->isAllowedFileUrl($fileUrl))
		{
			return $result->addError(new Error('Refusing to download file_url outside the configured platform host.', 'ssrf_blocked'));
		}

		$sessions = $this->getSessionsByHash($externalHash);
		$session = $sessions[0] ?? null;
		if (!$session)
		{
			// Permanent not-found: the local session was drained/deleted while a late
			// save still arrived. Retrying can never resolve it, so acknowledge (2xx + ledger
			// row) and log instead of a non-2xx that triggers an endless retry storm. Mirrors
			// self::handleUnsupportedEvent(). Transient failures (download) stay retryable below.
			return $this->acknowledgePermanent(
				"Vibeoffice webhook: no local session for document.saved key '{$externalHash}', acknowledging."
			)->setData(['savedContentApplied' => false]);
		}

		$file = $session->getFile();
		if (!($file instanceof File))
		{
			// Permanent not-found: the session's file was removed. Acknowledge + log; a
			// retry would fail identically forever.
			return $this->acknowledgePermanent(
				"Vibeoffice webhook: no file for document.saved session (key '{$externalHash}'), acknowledging."
			)->setData(['savedContentApplied' => false]);
		}

		$downloadResult = $this->downloadFromPlatform($fileUrl);
		if (!$downloadResult->isSuccess())
		{
			return $result->addErrors($downloadResult->getErrors());
		}

		$tmpFile = $downloadResult->getData()['file'];
		$tmpFileArray = \CFile::makeFileArray($tmpFile);
		if (!is_array($tmpFileArray) || empty($tmpFileArray['tmp_name']))
		{
			// Missing or empty temp files make makeFileArray return null/false.
			// bail out with a clear error (the platform retries) instead of feeding an
			// invalid array into uploadVersion and emitting a PHP warning.
			return $result->addError(new Error('Downloaded document is not a readable file.', 'download_failed'));
		}

		// Cap the platform-supplied file against getMaxFileSize() before storing it as a Disk
		// version. An oversized file is a permanent condition (the same bytes
		// would download every retry), so acknowledge + log instead of an endless retry.
		$maxFileSize = $this->getConfiguration()->getMaxFileSize();
		$fileSize = (int)($tmpFileArray['size'] ?? 0);
		if ($fileSize <= 0)
		{
			$fileSize = (int)@filesize($tmpFileArray['tmp_name']);
		}
		if ($maxFileSize > 0 && $fileSize > $maxFileSize)
		{
			return $this->acknowledgePermanent(
				"Vibeoffice webhook: downloaded document ({$fileSize} bytes) exceeds the max file size"
				. " ({$maxFileSize} bytes) for key '{$externalHash}'; skipping upload."
			)->setData(['savedContentApplied' => false]);
		}

		if (in_array($tmpFileArray['type'] ?? null, ['application/encrypted', 'application/zip'], true))
		{
			$fileType = (string)($payload['filetype'] ?? $file->getExtension());
			$tmpFileArray['type'] = MimeType::getByFileExtension($fileType);
		}

		$userId = $this->resolveSavingUserId($payload, $sessions, $session);

		// commentAttachedObjects is run separately (on background) below, matching OnlyOffice.
		if (!$file->uploadVersion($tmpFileArray, $userId, ['commentAttachedObjects' => false]))
		{
			return $result->addError(new Error('Could not upload the new document version.', 'upload_failed'));
		}

		return $result->setData([
			'savedContentApplied' => true,
			'file' => $file,
			'sessions' => $sessions,
		]);
	}

	private function dispatchSavedSideEffects(array $data): Result
	{
		$sessions = $data['sessions'] ?? [];
		$file = $data['file'] ?? null;

		if ($file instanceof File)
		{
			$this->commentAttachedObjectsOnBackground($file);
		}

		if (is_array($sessions))
		{
			$this->sendEventToParticipants($sessions, 'saved');
		}

		return new Result();
	}

	private function loadSavedSideEffectsData(string $externalHash): array
	{
		$sessions = $this->getSessionsByHash($externalHash);
		$session = $sessions[0] ?? null;
		$file = $session?->getFile();

		return [
			'file' => $file instanceof File ? $file : null,
			'sessions' => $sessions,
		];
	}

	/**
	 * How far a `document.saved` that is still being applied has got.
	 *
	 * A saved is applied in two steps (the version, then the side effects), and a failure in
	 * between makes the platform re-deliver it, so the reached step is checkpointed. The delivery
	 * ledger is not the place for it: {@see DeliveryTable} holds one row per real platform
	 * delivery and its `EVENT` is the protocol event. The checkpoint lives in the shared
	 * persistent storage instead, where its own {@see self::SAVED_STAGE_TTL} applies; if it does
	 * expire before a very late retry, the retry simply repeats the upload.
	 */
	private function getSavedDeliveryStage(int $deliveryId): ?string
	{
		$stage = $this->getSavedDeliveryStageStorage()->get($this->getSavedDeliveryStageKey($deliveryId));
		if (
			$stage === self::SAVED_STAGE_VERSION_APPLIED
			|| $stage === self::SAVED_STAGE_SIDE_EFFECTS_APPLIED
		)
		{
			return $stage;
		}

		return null;
	}

	private function markSavedDeliveryStage(int $deliveryId, string $stage): Result
	{
		$result = new Result();

		try
		{
			$stored = $this->getSavedDeliveryStageStorage()->set(
				$this->getSavedDeliveryStageKey($deliveryId),
				$stage,
				self::SAVED_STAGE_TTL,
			);
		}
		catch (Throwable $exception)
		{
			return $result->addError(new Error(
				$exception->getMessage(),
				'saved_delivery_stage_marker_failed',
			));
		}

		if (!$stored)
		{
			return $result->addError(new Error(
				'Could not store the saved delivery stage.',
				'saved_delivery_stage_marker_failed',
			));
		}

		return $result;
	}

	private function getSavedDeliveryStageKey(int $deliveryId): string
	{
		return self::SAVED_STAGE_KEY_PREFIX . $deliveryId;
	}

	private function getSavedDeliveryStageStorage(): PersistentStorageInterface
	{
		return ServiceLocator::getInstance()->get(PersistentStorageInterface::class);
	}

	private function updateFailoverMetadata(array $payload, array $sessions): Result
	{
		$metadata = ['editor_key' => $payload['editor_key'], 'rev' => $payload['rev']];
		$manager = new DocumentSessionManager();
		foreach ($sessions as $session)
		{
			$stored = DocumentSessionManager::extractVoMetadata($session);
			if (($stored['editor_key'] ?? null) === $metadata['editor_key'] && ($stored['rev'] ?? null) === $metadata['rev'])
			{
				continue;
			}
			if (!$manager->persistSessionMetadata($session, $metadata))
			{
				return (new Result())->addError(new Error('Could not persist failover metadata.', 'vibeoffice_metadata_failed'));
			}
		}

		return new Result();
	}

	/**
	 * Deactivates the local sessions for the document key. A strict
	 * `notmodified:true` is a global terminal outcome and also releases document restrictions.
	 * revoked or otherwise ambiguous closes keep the restrictions for drain.
	 */
	private function handleSessionClosed(array $payload): Result
	{
		$result = new Result();

		$externalHash = $this->extractExternalHash($payload);
		if ($externalHash === null)
		{
			return $result->addError(new Error('Missing host_doc_key in document.session-closed webhook.', 'validation'));
		}

		if (($payload['notmodified'] ?? null) === true)
		{
			$finalizationResult = $this->terminalSessionFinalizer->finalizeDocument($externalHash);
			if (!$finalizationResult->isSuccess())
			{
				return $result->addErrors($finalizationResult->getErrors());
			}
		}
		else
		{
			return $this->deactivateSessions($externalHash);
		}

		return $result;
	}

	/**
	 * Resolves the user the new version is attributed to: the claim of the payload when it names a
	 * participant of the `EXTERNAL_HASH` sessions, otherwise the session owner and finally the
	 * system user. See {@see WebhookEventPolicy::resolveVersionAuthorId()}.
	 *
	 * @param DocumentSession[] $sessions
	 */
	private function resolveSavingUserId(array $payload, array $sessions, DocumentSession $session): int
	{
		$sessionUserId = $session->getUserId();

		return WebhookEventPolicy::resolveVersionAuthorId(
			$payload,
			$this->collectSessionUserIds($sessions),
			$sessionUserId > 0 ? $sessionUserId : SystemUser::SYSTEM_USER_ID,
		);
	}

	/**
	 * User ids that participate in the given document sessions (the trusted set of possible
	 * version authors. See {@see self::resolveSavingUserId()}.
	 *
	 * @param DocumentSession[] $sessions
	 * @return int[]
	 */
	private function collectSessionUserIds(array $sessions): array
	{
		$ids = [];
		foreach ($sessions as $session)
		{
			$userId = $session->getUserId();
			if ($userId > 0)
			{
				$ids[] = $userId;
			}
		}

		return $ids;
	}

	/**
	 * SSRF allowlist for the platform-supplied `file_url`.
	 *
	 * Accepts only http(s) URLs whose host equals the configured platform base URL host
	 * ({@see Configuration::getServer()}). If the platform base URL is not configured we
	 * cannot vouch for the target host, so we fail closed rather than dereference an
	 * arbitrary address.
	 */
	private function isAllowedFileUrl(string $fileUrl): bool
	{
		$url = new Uri($fileUrl);

		$scheme = mb_strtolower($url->getScheme());
		if ($scheme !== 'http' && $scheme !== 'https')
		{
			return false;
		}

		$server = $this->getConfiguration()->getServer();
		if (!is_string($server) || $server === '')
		{
			return false;
		}

		$expectedUri = new Uri($server);
		$expectedHost = mb_strtolower($expectedUri->getHost());
		$actualHost = mb_strtolower($url->getHost());

		if ($expectedHost === '' || $actualHost !== $expectedHost)
		{
			return false;
		}

		// Defense-in-depth: also pin the port, so a same-host URL on a different port (e.g.
		// an internal service co-located on the platform host) is rejected. Uri::getPort()
		// normalises the scheme default (80/443), so an explicit default port compares equal.
		// Private IPs are still deliberately NOT blocked (box/on-prem reachability). See
		// self::downloadFromPlatform().
		return $url->getPort() === $expectedUri->getPort();
	}

	/**
	 * Resolves the shared vibeoffice {@see Configuration} through the registered singleton
	 * service instead of `new Configuration()`, so the php_interface override file is
	 * stat'ed/required once per request rather than on every hot-path call.
	 */
	private function getConfiguration(): Configuration
	{
		return ServiceLocator::getInstance()->get('disk.vibeofficeConfiguration');
	}

	/**
	 * Acknowledges a PERMANENTLY unprocessable delivery (the target session/file no longer
	 * exists, the downloaded document violates a hard limit, or the envelope itself is rejected -
	 * see {@see WebhookEventPolicy::isPermanentFailure()}): logs it and returns a successful
	 * Result so the caller answers 2xx. A permanent condition can never succeed on retry, so a
	 * non-2xx here would only cause an endless retry storm and log spam. Mirrors
	 * {@see self::handleUnsupportedEvent()}.
	 */
	private function acknowledgePermanent(string $message): Result
	{
		AddMessage2Log($message, 'disk');

		return new Result();
	}

	/**
	 * Keeps a swallowed delivery failure diagnosable: a repeating non-2xx is otherwise the only
	 * trace of a deadlock, a lock timeout or a CFile failure. The record names the event, the
	 * delivery and the exception, and carries neither payload nor session hash.
	 */
	private function logDeliveryException(Throwable $exception, string $event, int $deliveryId): void
	{
		(new LoggerFactory())
			->createById(self::LOGGER_ID, isCheckEnabledFromRegistry: false)
			?->error('Vibeoffice webhook: event={event}, deliveryId={deliveryId}, {exception}: {message}.', [
				'event' => $event,
				'deliveryId' => $deliveryId,
				'exception' => $exception::class,
				'message' => $exception->getMessage(),
			])
		;
	}

	/**
	 * Downloads the presigned platform `file_url` into a temp file, hardened against SSRF.
	 *
	 * SSRF is contained by a host allowlist and by disabled redirects. The presigned platform
	 * URL serves the bytes directly, so a 3xx here is treated as a failure and a redirect to
	 * another address cannot be used to pivot. Private IP targets are deliberately allowed:
	 * the vibeoffice platform can be reachable at a private/internal address in box,
	 * on-premise or local development deployments. This path uses a no-redirect client so
	 * OnlyOffice download behavior stays unchanged.
	 */
	private function downloadFromPlatform(string $fileUrl): Result
	{
		$result = new Result();

		$tmpFile = \CTempFile::getFileName(Random::getString(16));
		checkDirPath($tmpFile);

		$httpClient = new HttpClient();
		$httpClient->setRedirect(false);

		if (!$httpClient->download($fileUrl, $tmpFile))
		{
			return $result->addError(new Error('Could not download the document from the platform.', 'download_failed'));
		}

		$status = $httpClient->getStatus();
		if ($status !== 200)
		{
			return $result->addError(new Error("Could not download the document from the platform. Got {$status}.", 'download_failed'));
		}

		return $result->setData(['file' => $tmpFile]);
	}

	/**
	 * Unsupported/unknown webhook event.
	 *
	 * Failing here would make the caller answer non-2xx and the platform would retry the
	 * same delivery forever. Instead we acknowledge it: the successful Result makes
	 * {@see self::process()} write the idempotency ledger row (so the delivery is retained),
	 * and we additionally log the event for visibility.
	 */
	private function handleUnsupportedEvent(string $event): Result
	{
		AddMessage2Log("Vibeoffice webhook: acknowledging unsupported event '{$event}'.", 'disk');

		return new Result();
	}

	private function deactivateSessions(string $externalHash): Result
	{
		foreach ($this->getSessionsByHash($externalHash) as $session)
		{
			$update = DocumentSessionTable::update((int)$session->getId(), ['STATUS' => DocumentSessionTable::STATUS_NON_ACTIVE]);
			if (!$update->isSuccess())
			{
				return (new Result())->addErrors($update->getErrors());
			}
		}

		return new Result();
	}

	private function commentAttachedObjectsOnBackground(File $file): void
	{
		Application::getInstance()->addBackgroundJob(static function () use ($file) {
			$lastVersion = $file->getLastVersion();
			if ($lastVersion)
			{
				$file->commentAttachedObjects($lastVersion);
			}
		});
	}

	/**
	 * @param DocumentSession[] $sessions
	 */
	private function sendEventToParticipants(array $sessions, string $event): void
	{
		foreach ($sessions as $session)
		{
			Driver::getInstance()->sendEvent($session->getUserId(), 'vibeoffice', [
				'object' => [
					'id' => $session->getObjectId(),
				],
				'documentSession' => [
					'hash' => $session->getExternalHash(),
				],
				'event' => $event,
			]);
		}
	}

	/**
	 * @return DocumentSession[]
	 */
	private function getSessionsByHash(string $externalHash): array
	{
		$result = $this->scopeResolver->resolve($externalHash);

		return $result->isSuccess() && $result->getData()['legacy'] ? $result->getData()['sessions'] : [];
	}

	private function extractExternalHash(array $payload): ?string
	{
		$hash = $payload['host_doc_key'] ?? null;

		return is_string($hash) && $hash !== '' ? $hash : null;
	}
}

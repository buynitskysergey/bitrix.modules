<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Controller;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Error;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\SystemException;
use Bitrix\Main\Web\Uri;
use Bitrix\Note\Infrastructure\Agent\Import\ImportAgent;
use Bitrix\Note\Internal\Access\AccessController;
use Bitrix\Note\Internal\Access\ActionDictionary;
use Bitrix\Note\Internal\Access\PortalAdmin;
use Bitrix\Note\Internal\Access\Service\CollectionAccessService;
use Bitrix\Note\Internal\Integration\Landing\SiteReader;
use Bitrix\Note\Internal\Repository\CollectionRepository;
use Bitrix\Note\Internal\Repository\ImportMapRepository;
use Bitrix\Note\Internal\Repository\ImportSessionRepository;
use Bitrix\Note\Internal\Service\Import\ImportLogger;
use Bitrix\Note\Internal\Service\Import\LandingImportAvailability;
use Bitrix\Note\Internal\Service\Import\Source\LandingSource;
use Bitrix\Note\Internal\Service\Import\Source\OutlineSource;
use Bitrix\Note\Internal\Service\Import\Source\SourceInterface;
use Bitrix\Note\Internal\Service\Import\Source\SourceResult;
use Bitrix\Note\Internal\Service\Import\Source\WikiSource;
use Bitrix\Note\Internal\Service\Import\Step\CreateCollectionStep;
use Bitrix\Note\Internal\Service\Import\WikiImportAvailability;
use Bitrix\Note\Public\Command\Import\CancelImportCommand;
use Bitrix\Note\Public\Command\Import\StartImportCommand;

class ImportController extends Controller
{
	protected function getDefaultPreFilters(): array
	{
		return array_merge(
			parent::getDefaultPreFilters(),
			[
				new ActionFilter\NoteAccess(),
			],
		);
	}

	public function getActiveSessionAction(): ?array
	{
		if (!$this->assertImportAccess())
		{
			return null;
		}

		$userId = (int)$this->getCurrentUser()->getId();
		$repository = new ImportSessionRepository();
		$existing = $repository->getByUser($userId);

		if ($existing === null)
		{
			return ['active' => false];
		}

		$sessionId = (int)$existing['ID'];
		$progress = $this->readStepperProgress($sessionId);

		return [
			'active' => true,
			'sessionId' => $sessionId,
			'progress' => $progress,
		];
	}

	public function acknowledgeFinishAction(int $sessionId): ?bool
	{
		if (!$this->assertImportAccess())
		{
			return null;
		}

		$session = $this->loadSession($sessionId);
		if ($session === null)
		{
			return null;
		}

		if ($session['STATUS'] === 'in_progress')
		{
			$this->addError(new Error(
				Loc::getMessage('NOTE_IMPORT_IN_PROGRESS'),
				'IMPORT_IN_PROGRESS',
			));

			return null;
		}

		Option::delete('main.stepper.note', ['name' => ImportAgent::class . "({$sessionId})"]);
		(new ImportSessionRepository())->delete($sessionId);

		return true;
	}

	public function checkConnectionAction(string $sourceType, string $url = '', string $token = ''): ?array
	{
		if (!$this->assertImportAccess())
		{
			return null;
		}

		if (!$this->assertSourceAvailable($sourceType))
		{
			return null;
		}

		$userId = (int)$this->getCurrentUser()->getId();
		$existing = (new ImportSessionRepository())->getByUser($userId);
		if ($existing !== null && $existing['STATUS'] === 'in_progress')
		{
			$this->addError(new Error(
				Loc::getMessage('NOTE_IMPORT_IN_PROGRESS'),
				'IMPORT_IN_PROGRESS',
			));

			return null;
		}

		$source = $this->createSource($sourceType, $url, $token);
		$result = $source->checkConnection();

		if (!$result->success)
		{
			$this->addError(new Error(
				$result->error ?? Loc::getMessage('NOTE_IMPORT_CONNECTION_FAILED'),
				$this->resolveConnectionErrorCode($sourceType, $result),
			));

			return null;
		}

		return [
			'instanceName' => $result->data['instanceName'] ?? '',
		];
	}

	public function checkOverlapAction(string $sourceType, array $collectionIds): ?array
	{
		if (!$this->assertImportAccess())
		{
			return null;
		}

		if (!$this->assertSourceAvailable($sourceType))
		{
			return null;
		}

		// The map is asked by its own keys and answered in the ids of the source: a base reaches the
		// screen under the id the source listed it by, and that id comes back here and to the start of
		// the conversion as the base to convert. Which id a key stands for is read off the request and
		// never out of the key itself - a key cut to the column cannot be turned back into an id.
		$sourceIdsByMapKey = [];
		foreach ($collectionIds as $collectionId)
		{
			$sourceCollectionId = (string)$collectionId;
			$mapKey = CreateCollectionStep::collectionMapKey($sourceType, $sourceCollectionId);
			$sourceIdsByMapKey[$mapKey] = $sourceCollectionId;
		}

		$mapRepository = new ImportMapRepository();
		$mappings = $mapRepository->bulkLookup($sourceType, array_keys($sourceIdsByMapKey));

		$mappedCollectionIds = [];
		foreach ($mappings as $mapKey => $mapping)
		{
			$sourceCollectionId = $sourceIdsByMapKey[(string)$mapKey] ?? null;
			if ($sourceCollectionId === null || $mapping['collectionId'] === null)
			{
				continue;
			}

			$mappedCollectionIds[$sourceCollectionId] = (int)$mapping['collectionId'];
		}

		// One answer, told twice: the plain list of bases converted earlier is taken from the described
		// ones, which are already only those the caller may see (API-01). Built from the mappings
		// instead, it would name a base of somebody else in a list of ids while the list of names next
		// to it keeps quiet about that base.
		$existingCollections = $this->describeExistingCollections($mappedCollectionIds);

		return [
			'existingCollectionIds' => array_column($existingCollections, 'externalId'),
			'existingCollections' => $existingCollections,
		];
	}

	public function getCollectionsAction(string $sourceType, string $url = '', string $token = ''): ?array
	{
		if (!$this->assertImportAccess())
		{
			return null;
		}

		if (!$this->assertSourceAvailable($sourceType))
		{
			return null;
		}

		$source = $this->createSource($sourceType, $url, $token);
		$result = $source->getCollections();

		if (!$result->success)
		{
			$this->addError(new Error($result->error ?? Loc::getMessage('NOTE_IMPORT_COLLECTIONS_FAILED')));

			return null;
		}

		return ['collections' => $result->data['collections'] ?? []];
	}

	public function getDocumentTreeAction(string $sourceType, string $collectionId, string $url = '', string $token = ''): ?array
	{
		if (!$this->assertImportAccess())
		{
			return null;
		}

		if (!$this->assertSourceAvailable($sourceType))
		{
			return null;
		}

		if (!$this->mayReadSourceCollection($sourceType, $collectionId))
		{
			// Word for word the answer about a base that is not there, and with no code of its own to
			// tell it apart by: a caller learning "this one exists, you just may not read it" could walk
			// the ids of other people's bases through this very action ([P6.T4]).
			$this->addError(new Error(Loc::getMessage('NOTE_IMPORT_SOURCE_NOT_FOUND')));

			return null;
		}

		$source = $this->createSource($sourceType, $url, $token);
		$result = $source->getDocumentTree($collectionId);

		if (!$result->success)
		{
			$this->addError(new Error(
				$result->error ?? $this->getImportTermMessage('NOTE_IMPORT_TREE_FAILED', $sourceType),
			));

			return null;
		}

		return ['documents' => $result->data['documents'] ?? []];
	}

	/**
	 * @param mixed $overwrite whether an earlier conversion is to be updated, in any form the
	 *                         transport delivers it in; untyped on purpose, see below
	 */
	public function startAction(
		string $sourceType,
		string $url,
		string $token,
		array $collectionIds,
		$overwrite = false,
	): ?array
	{
		// The flag arrives over a form-encoded transport, so it is a string, and a weak-mode (bool) of
		// the non-empty 'false' is true - the choice "create a new base" would start an overwrite of
		// the base there is. A `bool` declaration would do that cast before this line, so the raw value
		// is taken untyped and read here: only a value spelled as truth turns the overwrite on.
		$overwrite = filter_var($overwrite, FILTER_VALIDATE_BOOLEAN);

		if (!$this->assertImportAccess())
		{
			return null;
		}

		if (!$this->assertSourceAvailable($sourceType))
		{
			return null;
		}

		$userId = (int)$this->getCurrentUser()->getId();

		try
		{
			if ($sourceType !== 'wiki' && $sourceType !== 'landing')
			{
				$this->validateSourceUrl($url);
			}
		}
		catch (SystemException $e)
		{
			// The address is the user's own input, and what is wrong with it is said in words written
			// for them ({@see validateSourceUrl}), so this one message travels as it is.
			$this->addError(new Error($e->getMessage()));

			return null;
		}

		try
		{
			$result = (new StartImportCommand(
				$sourceType,
				$url,
				$token,
				$collectionIds,
				$userId,
				overwrite: $overwrite,
			))->run();
		}
		catch (\Throwable $e)
		{
			// Same split as getStatusAction: the log keeps the whole of it, the screen gets the reason
			// of the failure. The message of an exception of the database layer carries the text of its
			// query, and one of any other layer names files, classes and ids of the portal.
			ImportLogger::logError(sprintf(
				'startAction(sourceType=%s) failed: %s in %s:%d',
				$sourceType,
				$e->getMessage(),
				$e->getFile(),
				$e->getLine(),
			));
			// The cause decides the reason, not the wrapper a command throws around it
			// ({@see \Bitrix\Main\Command\AbstractCommand::run()}), which names the command and nothing
			// about what went wrong.
			$this->addError(new Error(
				ImportLogger::resolveErrorReason($e->getPrevious() ?? $e),
				'IMPORT_START_FAILED',
			));

			return null;
		}

		if (!$result->isSuccess())
		{
			foreach ($result->getErrors() as $error)
			{
				$this->addError($error);
			}

			return null;
		}

		$sessionId = (int)$result->getData()['sessionId'];

		return [
			'sessionId' => $sessionId,
			'progress' => $this->readStepperProgress($sessionId),
		];
	}

	public function getStatusAction(int $sessionId): ?array
	{
		if (!$this->assertImportAccess())
		{
			return null;
		}

		try
		{
			$session = $this->loadSession($sessionId);
			if ($session === null)
			{
				return null;
			}

			$stepperProgress = $this->readStepperProgress($sessionId);
			if ($stepperProgress !== null)
			{
				return ['progress' => $stepperProgress];
			}

			return [
				'progress' => [
					'status' => $session['STATUS'],
					'total' => 0,
					'done' => 0,
					'error' => 0,
					'errorMessage' => '',
				],
			];
		}
		catch (\Throwable $e)
		{
			// Log the full stack so we can diagnose "Не удалось получить статус импорта"
			// flashes - the UI's polling loop currently only surfaces the generic
			// localized error which gives us nothing to act on.
			ImportLogger::logError(sprintf(
				'getStatusAction(sessionId=%d) failed: %s in %s:%d',
				$sessionId,
				$e->getMessage(),
				$e->getFile(),
				$e->getLine(),
			));
			// The log above keeps the whole of it; the screen gets the reason of the failure and not the
			// message of the exception, which names files, classes and ids of the portal.
			$this->addError(new Error(ImportLogger::resolveErrorReason($e), 'IMPORT_STATUS_FAILED'));

			return null;
		}
	}

	public function cancelAction(int $sessionId): ?bool
	{
		if (!$this->assertImportAccess())
		{
			return null;
		}

		$session = $this->loadSession($sessionId);
		if ($session === null)
		{
			return null;
		}

		try
		{
			$result = (new CancelImportCommand($sessionId))->run();
		}
		catch (\Throwable $e)
		{
			// Same split as startAction: the log keeps the whole of it, the screen gets the reason of
			// the failure. The message of an exception of the database layer carries the text of its
			// query, and one of any other layer names files, classes and ids of the portal.
			ImportLogger::logError(sprintf(
				'cancelAction(sessionId=%d) failed: %s in %s:%d',
				$sessionId,
				$e->getMessage(),
				$e->getFile(),
				$e->getLine(),
			));
			// The cause decides the reason, not the wrapper a command throws around it
			// ({@see \Bitrix\Main\Command\AbstractCommand::run()}), which names the command and nothing
			// about what went wrong.
			$this->addError(new Error(
				ImportLogger::resolveErrorReason($e->getPrevious() ?? $e),
				'IMPORT_CANCEL_FAILED',
			));

			return null;
		}

		if (!$result->isSuccess())
		{
			foreach ($result->getErrors() as $error)
			{
				$this->addError($error);
			}

			return null;
		}

		return true;
	}

	public function getConvertedCollectionsAction(int $sessionId): ?array
	{
		if (!$this->assertImportAccess())
		{
			return null;
		}

		$session = $this->loadSession($sessionId);
		if ($session === null)
		{
			return null;
		}

		$option = $this->readStepperOption($sessionId);
		if ($option === null)
		{
			return ['collections' => []];
		}

		$sourceType = (string)($option['sourceType'] ?? '');
		$sourceCollectionIds = array_values(array_filter(array_map(
			'strval',
			(array)($option['collectionIds'] ?? []),
		), static fn(string $id): bool => $id !== ''));

		if ($sourceType === '' || empty($sourceCollectionIds))
		{
			return ['collections' => []];
		}

		// Same rule as checkOverlapAction(): the state of the conversion holds the ids of the source,
		// the map holds keys of its own, and the answer is put back under the id it was asked about.
		$mapKeysBySourceId = [];
		foreach ($sourceCollectionIds as $sourceCollectionId)
		{
			$mapKeysBySourceId[$sourceCollectionId] = CreateCollectionStep::collectionMapKey(
				$sourceType,
				$sourceCollectionId,
			);
		}

		$mappings = (new ImportMapRepository())->bulkLookup($sourceType, array_values($mapKeysBySourceId));

		$mapBySourceCollectionId = [];
		foreach ($mapKeysBySourceId as $sourceCollectionId => $mapKey)
		{
			if (isset($mappings[$mapKey]))
			{
				$mapBySourceCollectionId[$sourceCollectionId] = $mappings[$mapKey];
			}
		}

		$collectionIds = [];
		foreach ($sourceCollectionIds as $sourceCollectionId)
		{
			$collectionId = (int)($mapBySourceCollectionId[$sourceCollectionId]['collectionId'] ?? 0);
			if ($collectionId <= 0)
			{
				continue;
			}

			$collectionIds[$collectionId] = true;
		}

		if (empty($collectionIds))
		{
			return ['collections' => []];
		}

		$collectionsById = [];
		foreach ((new CollectionRepository())->getListByIds(array_keys($collectionIds)) as $collection)
		{
			$collectionsById[(int)$collection->getId()] = $collection;
		}

		$levels = $this->resolveCollectionLevels(array_keys($collectionIds));

		$resultItems = [];
		$seenCollectionIds = [];
		foreach ($sourceCollectionIds as $sourceCollectionId)
		{
			$collectionId = (int)($mapBySourceCollectionId[$sourceCollectionId]['collectionId'] ?? 0);
			if ($collectionId <= 0 || isset($seenCollectionIds[$collectionId]))
			{
				continue;
			}

			$collection = $collectionsById[$collectionId] ?? null;
			if ($collection === null)
			{
				continue;
			}

			// Same rule as describeExistingCollections(): a base the caller may not see is left out
			// entirely, so an import made earlier by somebody else cannot disclose its collection name.
			$level = $levels[$collectionId] ?? CollectionAccessService::LEVEL_NONE;
			if ($level < CollectionAccessService::LEVEL_VIEW)
			{
				continue;
			}

			$resultItems[] = [
				'collectionId' => $collectionId,
				'name' => (string)$collection->getName(),
			];
			$seenCollectionIds[$collectionId] = true;
		}

		return ['collections' => $resultItems];
	}

	/**
	 * Target knowledge bases of an earlier import, as the repeat-import screen needs them (API-01).
	 *
	 * A base the caller may not see is left out of the answer entirely instead of being listed
	 * without a name: an entry of its own would let them enumerate other people's bases.
	 *
	 * @param array<string, int> $mappedCollectionIds external id => collection id
	 * @return array<int, array{externalId: string, collectionId: int, name: string, canModerate: bool}>
	 */
	private function describeExistingCollections(array $mappedCollectionIds): array
	{
		if (empty($mappedCollectionIds))
		{
			return [];
		}

		$collectionsById = [];
		$ids = array_values(array_unique($mappedCollectionIds));
		foreach ((new CollectionRepository())->getListByIds($ids) as $collection)
		{
			$collectionsById[(int)$collection->getId()] = $collection;
		}

		$levels = $this->resolveCollectionLevels($ids);

		$items = [];
		foreach ($mappedCollectionIds as $externalId => $collectionId)
		{
			$collection = $collectionsById[$collectionId] ?? null;
			if ($collection === null)
			{
				continue;
			}

			// The right of the user this request belongs to, which is the one the screen acts for.
			$level = $levels[$collectionId] ?? CollectionAccessService::LEVEL_NONE;
			if ($level < CollectionAccessService::LEVEL_VIEW)
			{
				continue;
			}

			$items[] = [
				'externalId' => (string)$externalId,
				'collectionId' => $collectionId,
				'name' => (string)$collection->getName(),
				'canModerate' => $level >= CollectionAccessService::LEVEL_MODERATE,
			];
		}

		return $items;
	}

	/**
	 * What the caller may do with each of $collectionIds, asked once for the whole list.
	 *
	 * The answer is the one a snapshot of a single base gives
	 * ({@see CollectionAccessService::getCurrentUserAccessSnapshot}): a personal denial means nothing is
	 * allowed, otherwise the highest of the personal levels, otherwise the policy of the base. A base
	 * missing from the answer has neither, and that is the level of a stranger - both callers read it as
	 * such. Asked per base, it was a query per base, and for an administrator a query whose answer was
	 * thrown away: they may moderate every base of the portal, and nothing here reads the policy.
	 *
	 * @param int[] $collectionIds
	 * @return array<int, int> collection id => access level
	 */
	private function resolveCollectionLevels(array $collectionIds): array
	{
		if (empty($collectionIds))
		{
			return [];
		}

		if (PortalAdmin::isCurrentUserAdmin())
		{
			return array_fill_keys($collectionIds, CollectionAccessService::LEVEL_MODERATE);
		}

		$userId = (int)$this->getCurrentUser()->getId();
		if ($userId <= 0)
		{
			return [];
		}

		return CollectionAccessService::batchGetUserLevels(
			$collectionIds,
			array_values(array_unique([...CollectionAccessService::buildUserAccessCodes($userId), '*'])),
		);
	}

	private function loadSession(int $sessionId): ?array
	{
		$repository = new ImportSessionRepository();
		$session = $repository->getById($sessionId);

		if ($session === null)
		{
			$this->addError(new Error(Loc::getMessage('NOTE_IMPORT_SESSION_NOT_FOUND')));

			return null;
		}

		$currentUserId = (int)$this->getCurrentUser()->getId();
		if ((int)$session['CREATED_BY'] !== $currentUserId && !PortalAdmin::isCurrentUserAdmin())
		{
			$this->addError(new Error(Loc::getMessage('NOTE_IMPORT_ACCESS_DENIED')));

			return null;
		}

		return $session;
	}

	private function readStepperProgress(int $sessionId): ?array
	{
		$data = $this->readStepperOption($sessionId);
		if (!is_array($data))
		{
			return null;
		}

		$collectionNames = $data['collectionNames'] ?? [];
		$collectionIds = $data['collectionIds'] ?? [];
		$collectionIndex = (int)($data['collectionIndex'] ?? 0);
		$currentExternalId = $collectionIds[$collectionIndex] ?? null;
		$currentCollectionName = ($currentExternalId !== null)
			? ($collectionNames[$currentExternalId] ?? '')
			: '';

		$status = $data['status'] ?? 'in_progress';
		$isFinished = ($status !== 'in_progress');

		$step = $data['step'] ?? 'createCollection';
		// During phase 1 (createStructure), doneCount stays at 0; expose structureIndex
		// instead so the progress bar reflects how much of the tree has been laid out.
		$currentDone = ($step === 'createStructure')
			? (int)($data['structureIndex'] ?? 0)
			: (int)($data['doneCount'] ?? 0);
		$currentError = (int)($data['errorCount'] ?? 0);
		$currentTotalAttachments = (int)($data['totalAttachments'] ?? 0);
		$currentDoneAttachments = (int)($data['doneAttachments'] ?? 0);

		$globalDone = (int)($data['globalDoneCount'] ?? 0);
		$globalError = (int)($data['globalErrorCount'] ?? 0);
		$globalTotalAttachments = (int)($data['globalTotalAttachments'] ?? 0);
		$globalDoneAttachments = (int)($data['globalDoneAttachments'] ?? 0);

		return [
			'status' => $status,
			'sourceType' => (string)($data['sourceType'] ?? ''),
			'step' => $step,
			'total' => (int)($data['totalItems'] ?? 0),
			'done' => $isFinished ? $globalDone : $currentDone,
			'error' => $isFinished ? $globalError : $currentError,
			'totalAttachments' => $isFinished ? $globalTotalAttachments : $currentTotalAttachments,
			'doneAttachments' => $isFinished ? $globalDoneAttachments : $currentDoneAttachments,
			'collectionName' => $currentCollectionName,
			'collectionIndex' => $collectionIndex,
			'collectionCount' => count($collectionIds),
			'collectionIds' => $collectionIds,
			'sourceUrl' => $data['sourceUrl'] ?? null,
			// Why the import stopped, as the agent recorded it (API-03); empty string when it did not.
			'errorMessage' => (string)($data['errorMessage'] ?? ''),
			'errorDetails' => $data['errorDetails'] ?? [],
		];
	}

	private function readStepperOption(int $sessionId): ?array
	{
		$optionKey = ImportAgent::class . "({$sessionId})";
		$raw = Option::get('main.stepper.note', $optionKey);
		if ($raw === '')
		{
			return null;
		}

		$data = unserialize($raw, ['allowed_classes' => false]);

		return is_array($data) ? $data : null;
	}

	private function assertImportAccess(): bool
	{
		if (AccessController::getCurrent()->check(ActionDictionary::ACTION_NOTE_IMPORT))
		{
			return true;
		}

		$this->addError(new Error(Loc::getMessage('NOTE_IMPORT_ACCESS_DENIED')));

		return false;
	}

	/**
	 * Whether the source $sourceType is turned on for this portal.
	 *
	 * A source that converts bases of this very portal is released behind an option of its own, and the
	 * picker of the dialog only offers it once the option is set. The same answer is given here, so that
	 * a source type spelled out by hand does not reach the conversion around the picker.
	 */
	private function isSourceAvailable(string $sourceType): bool
	{
		return match ($sourceType)
		{
			'wiki' => (new WikiImportAvailability())->isEnabled(),
			'landing' => (new LandingImportAvailability())->isEnabled(),
			default => true,
		};
	}

	/**
	 * The same answer as {@see isSourceAvailable}, with the refusal said out loud where the screen
	 * asking reads it.
	 *
	 * Only `landing` is told why. These actions have been answering a source turned off with a success
	 * carrying nothing since `wiki` was released; the screens of `wiki` and `outline` are written
	 * against that answer, and rewriting them belongs to the work that owns those sources.
	 */
	private function assertSourceAvailable(string $sourceType): bool
	{
		if ($this->isSourceAvailable($sourceType))
		{
			return true;
		}

		if ($sourceType === 'landing')
		{
			$this->addSourceUnavailableError();
		}

		return false;
	}

	/**
	 * The refusal of a source the portal has not released, under the code the connection screen already
	 * knows a source it cannot use by ({@see resolveConnectionErrorCode}).
	 */
	private function addSourceUnavailableError(): void
	{
		$this->addError(new Error(
			Loc::getMessage('NOTE_IMPORT_SOURCE_UNAVAILABLE'),
			'IMPORT_SOURCE_UNAVAILABLE',
		));
	}

	/**
	 * The phrase of the term the source $sourceType calls for.
	 *
	 * `landing` and `wiki` convert bases of this very portal into knowledge bases and name their objects
	 * the way the knowledge base does; every other source keeps the words of the source itself. The `_KB`
	 * variant is written in ru only, so a locale without one falls back to the base phrase instead of
	 * rendering nothing - the same rule the dialog follows (`install/js/note/import/src/constants.js`,
	 * importTermMessageCode).
	 */
	private function getImportTermMessage(string $baseCode, string $sourceType): string
	{
		if ($sourceType !== 'landing' && $sourceType !== 'wiki')
		{
			return (string)Loc::getMessage($baseCode);
		}

		return Loc::getMessage($baseCode . '_KB') ?? (string)Loc::getMessage($baseCode);
	}

	/**
	 * A failed source check as a code the connection screen can act on (API-04).
	 *
	 * For a source with an address and a token the code names the field to highlight. landing has
	 * neither, so both of its meaningful causes used to arrive as an address error: they get codes
	 * of their own here, while the codes of every other source stay as they were.
	 */
	private function resolveConnectionErrorCode(string $sourceType, SourceResult $result): string
	{
		if ($sourceType === 'landing')
		{
			return \Bitrix\Main\Loader::includeModule('landing')
				? 'IMPORT_SOURCE_EMPTY'
				: 'IMPORT_SOURCE_UNAVAILABLE';
		}

		return $result->errorField === 'token' ? 'IMPORT_INVALID_TOKEN' : 'IMPORT_INVALID_URL';
	}

	/**
	 * Whether the caller may read the base $collectionId at the source ([P6.T4]).
	 *
	 * Asked here and now, in a request of the caller: it is the one context where landing answers about
	 * them and not about whoever happens to be logged in (ALG-03,
	 * {@see SiteReader::userHasReadAccess}). The conversion carries the answer along in a snapshot
	 * ({@see \Bitrix\Note\Internal\Service\Import\SourceReadableSnapshot}); the actions that read a base
	 * right away have no background job between them and the reader, so they ask directly.
	 *
	 * A source whose bases carry no access of their own - a base of the local wiki, a base at the other
	 * end of an address and a token - is read by whoever may convert at all, so nothing is gated here.
	 */
	private function mayReadSourceCollection(string $sourceType, string $collectionId): bool
	{
		if ($sourceType !== 'landing')
		{
			return true;
		}

		if (!\Bitrix\Main\Loader::includeModule('landing'))
		{
			return false;
		}

		$siteReader = new SiteReader();
		$siteId = $siteReader->findSiteIdByExternalId($collectionId);

		return $siteId !== null
			&& $siteReader->userHasReadAccess($siteId, (int)$this->getCurrentUser()->getId());
	}

	private function createSource(string $sourceType, string $url, string $token): SourceInterface
	{
		return match ($sourceType)
		{
			'outline' => $this->createOutlineSource($url, $token),
			'landing' => new LandingSource((int)$this->getCurrentUser()->getId()),
			'wiki' => new WikiSource((int)$this->getCurrentUser()->getId()),
			default => throw new SystemException('Unsupported source type: ' . $sourceType),
		};
	}

	private function createOutlineSource(string $url, string $token): SourceInterface
	{
		// URL-based source: validate scheme/host before any network call.
		$this->validateSourceUrl($url);

		return new OutlineSource($url, $token);
	}

	private function validateSourceUrl(string $url): void
	{
		$uri = new Uri($url);
		$scheme = mb_strtolower($uri->getScheme());

		if ($scheme !== 'https' && $scheme !== 'http')
		{
			throw new SystemException(Loc::getMessage('NOTE_IMPORT_ERROR_URL_SCHEME'));
		}

		$host = $uri->getHost();
		if ($host === '')
		{
			throw new SystemException(Loc::getMessage('NOTE_IMPORT_ERROR_URL_HOST'));
		}
	}
}

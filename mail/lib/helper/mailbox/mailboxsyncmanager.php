<?php
namespace Bitrix\Mail\Helper\Mailbox;

use COption;
use Bitrix\Mail\MailboxTable;
use Bitrix\Mail\Internals\MailEntityOptionsTable;
use Bitrix\Main\Loader;
use Bitrix\Mail\Helper;

class MailboxSyncManager
{
	private $userId;
	private $mailCheckInterval;

	const MIN_INTERVAL_BETWEEN_CONNECTION_ATTEMPTS = 300;
	const MAX_CONNECTION_ATTEMPTS_BEFORE_UNAVAILABLE = 3;

	public function __construct($userId)
	{
		$this->userId = $userId;
		$this->mailCheckInterval = COption::getOptionString('intranet', 'mail_check_period', 10) * 60;
	}

	public static function checkSyncWithCrm(int $mailboxId): bool
	{
		return Loader::includeModule('crm') && CrmImapFilter::isConnected($mailboxId);
	}

	public function getFailedToSyncMailboxes()
	{
		$mailboxes = [];
		$mailboxesSyncInfo = $this->getMailboxesSyncInfo();

		foreach ($mailboxesSyncInfo as $mailboxId => $lastMailCheckData)
		{
			if (!$lastMailCheckData['isSuccess'])
			{
				$mailboxes[$mailboxId] = $lastMailCheckData;
			}
		}
		return $mailboxes;
	}

	public function getSuccessSyncedMailboxes()
	{
		$mailboxesToSync = [];
		$mailboxesSyncInfo = $this->getMailboxesSyncInfo();

		foreach ($mailboxesSyncInfo as $mailboxId => $lastMailCheckData)
		{
			if ($lastMailCheckData['isSuccess'])
			{
				$mailboxesToSync[$mailboxId] = $lastMailCheckData;
			}
		}
		return $mailboxesToSync;
	}

	/*
	 *	It's time for synchronization for at least one mailbox.
	 */
	public function isMailNeedsToBeSynced()
	{
		return count($this->getNeedToBeSyncedMailboxes()) > 0;
	}

	/*
	 *	Returns mailboxes that are recommended to be synchronized.
	 */
	public function getNeedToBeSyncedMailboxes()
	{
		$mailboxesSyncData = $this->getSuccessSyncedMailboxes();
		$mailboxesToSync = [];
		foreach ($mailboxesSyncData as $mailboxId => $lastMailCheckData)
		{
			if ($lastMailCheckData['timeStarted'] >= 0 && (time() - intval($lastMailCheckData['timeStarted']) >= $this->mailCheckInterval))
			{
				$mailboxesToSync[$mailboxId] = $lastMailCheckData;
			}
		}
		return $mailboxesToSync;
	}

	public function getMailCheckInterval()
	{
		return $this->mailCheckInterval;
	}

	public function deleteSyncData($mailboxId): \Bitrix\Main\DB\Result
	{
		return MailEntityOptionsTable::deleteList($this->getOptionFilter($mailboxId, MailEntityOptionsTable::SYNC_STATUS_PROPERTY_NAME));
	}

	public function setDefaultSyncData($mailboxId)
	{
		$transitionResult = (new ProblemMailboxStateService())->setDefaultSyncData((int)$mailboxId);
		$this->sendMailboxGridButtonRefreshIfNeeded((int)$mailboxId, $transitionResult);
	}

	private function buildTimeForSyncStatus($time): int
	{
		if($time !== null && (int)$time >= 0)
		{
			return (int)$time;
		}

		return time();
	}

	public function setSyncStartedData($mailboxId, $time = null)
	{
		$timestamp = $this->buildTimeForSyncStatus($time);

		(new ProblemMailboxStateService())->setSyncStartedData((int)$mailboxId, $timestamp);
	}

	public function setSyncStatus(int $mailboxId, bool $isSuccess, ?int $time = null): void
	{
		$timestamp = $this->buildTimeForSyncStatus($time);

		$transitionResult = (new ProblemMailboxStateService())->setSyncStatus($mailboxId, $isSuccess, $timestamp);
		$this->sendMailboxGridButtonRefreshIfNeeded($mailboxId, $transitionResult);
	}

	public function registerFailedConnection(int $mailboxId): void
	{
		$transitionResult = (new ProblemMailboxStateService())->registerFailedConnectionAttempt($mailboxId);
		$this->sendMailboxGridButtonRefreshIfNeeded($mailboxId, $transitionResult);
	}

	public function getCachedConnectionStatus(int $mailboxId): bool
	{
		$problemMailboxStateService = new ProblemMailboxStateService();

		$lastMailboxSyncStatus = $this->getLastMailboxSyncIsSuccessStatus($mailboxId);

		if ($lastMailboxSyncStatus)
		{
			$transitionResult = $this->markConnectionRecovered($mailboxId, $problemMailboxStateService);
			$this->sendMailboxGridButtonRefreshIfNeeded($mailboxId, $transitionResult);

			return true;
		}

		/*
			A mailbox already known to be unavailable, or one checked too recently, is answered from
			the stored state: the check below opens a connection with a login per synced folder, each
			one up to the connect timeout. Only an explicit sync clears a raised problem status.
		*/
		$connectionCheckState = $problemMailboxStateService->getConnectionCheckState($mailboxId);

		if ($connectionCheckState['isProblem'] || !$connectionCheckState['isAttemptDue'])
		{
			return !$connectionCheckState['isProblem'];
		}

		$mailboxHelper = Helper\Mailbox::createInstance($mailboxId, false);

		if (!$mailboxHelper)
		{
			// The mailbox was legally disabled,
			// therefore the connection should be considered successful to avoid future notifications and error outputs.
			return true;
		}

		if ($mailboxHelper->isAuthenticated())
		{
			$transitionResult = $this->markConnectionRecovered($mailboxId, $problemMailboxStateService);
			$this->sendMailboxGridButtonRefreshIfNeeded($mailboxId, $transitionResult);

			return true;
		}

		// After token renewal, re-authorization is performed
		if ($mailboxHelper->renewOauthTokens() && $mailboxHelper->isAuthenticated())
		{
			$transitionResult = $this->markConnectionRecovered($mailboxId, $problemMailboxStateService);
			$this->sendMailboxGridButtonRefreshIfNeeded($mailboxId, $transitionResult);

			return true;
		}

		$transitionResult = $problemMailboxStateService->registerFailedConnectionAttempt($mailboxId);
		$this->sendMailboxGridButtonRefreshIfNeeded($mailboxId, $transitionResult);

		return !$transitionResult['isProblem'];
	}

	/**
	 * Connection state of the mailbox as it is stored, without touching the mail server. Read paths
	 * that render a screen or answer a payload use this one: a live check belongs to an explicit sync.
	 *
	 * Precondition: $mailboxId belongs to the user this manager was created for. Half of the answer
	 * comes from the state warmed for that user alone, so another mailbox may be answered from the
	 * unscoped row instead and read differently. The right to the mailbox is the business of the
	 * caller: the entry point checks it, not this helper.
	 */
	public function getStoredConnectionStatus(int $mailboxId): bool
	{
		if ($this->getLastMailboxSyncIsSuccessStatus($mailboxId))
		{
			return true;
		}

		$warmedProblemStatus = $this->findWarmedProblemStatus($mailboxId);

		if ($warmedProblemStatus !== null)
		{
			return !$warmedProblemStatus;
		}

		return !(new ProblemMailboxStateService())->isProblemMailbox($mailboxId);
	}

	/**
	 * Problem status of the mailbox out of the state the read above has already warmed. This reader now
	 * answers by the same predicate as the mailbox grid - PROBLEM_STATUS alone - though not from the
	 * same source: the grid reads the flag with a direct query of its own,
	 * {@see self::getMailboxesWithConnectionErrorForUsers()}.
	 *
	 * Null means the mailbox is not in that state and the caller has to read the row: the warmed state
	 * covers the mailboxes of the user alone, so a passwordless staging record may be missing from it,
	 * and the attempt counter it does not carry is deliberately left uncached.
	 */
	private function findWarmedProblemStatus(int $mailboxId): ?bool
	{
		$userId = (int)$this->userId;

		if (!$userId)
		{
			return null;
		}

		$warmedState = $this->loadMailboxSyncState($userId);

		if (!in_array($mailboxId, $warmedState['mailboxIds'], true))
		{
			return null;
		}

		return in_array($mailboxId, $warmedState['connectionErrorIds'], true);
	}

	/**
	 * @return array{wasProblem: bool, isProblem: bool, stateChanged: bool}
	 */
	private function markConnectionRecovered(int $mailboxId, ProblemMailboxStateService $problemMailboxStateService): array
	{
		return $problemMailboxStateService->markConnectionRecovered($mailboxId);
	}

	/**
	 * @param array{wasProblem: bool, isProblem: bool, stateChanged: bool} $transitionResult
	 */
	private function sendMailboxGridButtonRefreshIfNeeded(int $mailboxId, array $transitionResult): void
	{
		if (!$transitionResult['stateChanged'])
		{
			return;
		}

		(new MailboxGridButtonCounterRefreshService())->sendForMailbox($mailboxId);
	}

	private function getOptionFilter(int $mailboxId, string $propertyName): array
	{
		return [
			'=MAILBOX_ID' => $mailboxId,
			'=ENTITY_TYPE' => MailEntityOptionsTable::MAILBOX_TYPE_NAME,
			'=ENTITY_ID' => $mailboxId,
			'=PROPERTY_NAME' => $propertyName,
		];
	}

	/**
	 * @param int|null $userId
	 * @return int[]
	 */
	public function getCachedMailboxesIdsWithConnectionError(?int $userId = null): array
	{
		$userId = $userId ?? $this->userId;

		if (!$userId)
		{
			return [];
		}

		return $this->loadMailboxSyncState((int)$userId)['connectionErrorIds'];
	}

	/**
	 * Single bulk+cached read of per-mailbox sync state for a user:
	 * combines SYNC_STATUS and PROBLEM_STATUS into one cached fetch.
	 *
	 * `mailboxIds` tells "this mailbox has no problem status" from "this mailbox is not covered here":
	 * only the mailboxes of the user are read, so a caller outside that set has to fall back to a row.
	 *
	 * @return array{syncInfo: array<int, array{isSuccess: bool, timeStarted: int}>, connectionErrorIds: int[], mailboxIds: int[]}
	 */
	private function loadMailboxSyncState(int $userId): array
	{
		static $cache = [];

		if (isset($cache[$userId]))
		{
			return $cache[$userId];
		}

		$cache[$userId] = [
			'syncInfo' => [],
			'connectionErrorIds' => [],
			'mailboxIds' => [],
		];

		$userMailboxIds = array_keys(MailboxTable::getUserMailboxes($userId, true));

		if (empty($userMailboxIds))
		{
			return $cache[$userId];
		}

		$cache[$userId]['mailboxIds'] = array_map('intval', $userMailboxIds);

		$mailboxesOptions = MailEntityOptionsTable::getCachedMailboxesOptions(
			$userMailboxIds,
			[
				MailEntityOptionsTable::SYNC_STATUS_PROPERTY_NAME,
				MailEntityOptionsTable::PROBLEM_STATUS_PROPERTY_NAME,
			]
		);

		foreach ($userMailboxIds as $rawMailboxId)
		{
			$mailboxId = (int)$rawMailboxId;
			$options = $mailboxesOptions[$mailboxId] ?? [];

			$syncStatus = $options[MailEntityOptionsTable::SYNC_STATUS_PROPERTY_NAME] ?? null;

			if ($syncStatus !== null && isset($syncStatus['VALUE']))
			{
				$cache[$userId]['syncInfo'][$mailboxId] = [
					'isSuccess' => (bool)$syncStatus['VALUE'],
					'timeStarted' => $syncStatus['DATE_INSERT']->getTimestamp(),
				];
			}

			$problemStatus = $options[MailEntityOptionsTable::PROBLEM_STATUS_PROPERTY_NAME] ?? null;

			if ($problemStatus !== null && ($problemStatus['VALUE'] ?? null) === 'Y')
			{
				$cache[$userId]['connectionErrorIds'][] = $mailboxId;
			}
		}

		return $cache[$userId];
	}

	/**
	 * @param array $userIds
	 * @return int[]
	 */
	public static function getMailboxesWithConnectionErrorForUsers(array $userIds): array
	{
		if (empty($userIds))
		{
			return [];
		}

		$mailboxIds = MailboxTable::query()
			->setSelect(['ID'])
			->whereIn('USER_ID', $userIds)
			->where('ACTIVE', 'Y')
			->fetchAll()
		;

		$mailboxIds = array_map('strval', array_column($mailboxIds, 'ID'));
		if (empty($mailboxIds))
		{
			return [];
		}

		$query = MailEntityOptionsTable::query()
			->setSelect([
				'ENTITY_ID',
			])
			->where('ENTITY_TYPE', '=', MailEntityOptionsTable::MAILBOX_TYPE_NAME)
			->where('PROPERTY_NAME', '=', MailEntityOptionsTable::PROBLEM_STATUS_PROPERTY_NAME)
			->where('VALUE', '=', 'Y')
			->whereIn('ENTITY_ID', $mailboxIds)
		;

		$result = $query->exec();

		$mailboxIdsWithError = [];
		while ($row = $result->fetch())
		{
			$mailboxIdsWithError[] = (int)$row['ENTITY_ID'];
		}

		return $mailboxIdsWithError;
	}

	public function getMailboxesSyncInfo(): array
	{
		$userId = (int)$this->userId;

		if (!$userId)
		{
			return [];
		}

		return $this->loadMailboxSyncState($userId)['syncInfo'];
	}

	/**
	 * @deprecated Use \Bitrix\Mail\Helper\Mailbox\MailboxSyncManager::getTimeBeforeNextSync()
	 */
	public function getNextTimeToSync($lastMailCheckData)
	{
		return intval($lastMailCheckData['timeStarted']) + $this->mailCheckInterval - time();
	}

	/*
	 * Returns the time remaining until the required recommended mail synchronization.
	 * If it's time to synchronize, it will return 0.
	 */
	public function getTimeBeforeNextSync()
	{
		$mailboxesSuccessSynced = $this->getSuccessSyncedMailboxes();
		$timeBeforeNextSyncMailboxes = [];

		foreach ($mailboxesSuccessSynced as $mailboxId => $lastMailCheckData)
		{
			$timeBeforeNextSyncMailboxes[] = intval($lastMailCheckData['timeStarted']) + $this->mailCheckInterval - time();
		}

		return !empty($timeBeforeNextSyncMailboxes) && min($timeBeforeNextSyncMailboxes) > 0 ? min($timeBeforeNextSyncMailboxes) : 0;
	}

	/**
	 * @return null|int
	 */
	public function getFirstFailedToSyncMailboxId(): ?int
	{
		return $this->getCachedMailboxesIdsWithConnectionError()[0] ?? null;
	}

	/**
	 * Returns the status of the last sync.
	 * If the status could not be found out, null will be returned.
	 *
	 * @param int $mailboxId
	 * @return bool|null
	 */
	public function getLastMailboxSyncIsSuccessStatus(int $mailboxId): ?bool
	{
		$mailboxesOptions = $this->getMailboxesSyncInfo();
		if (!(isset($mailboxesOptions[$mailboxId]) && array_key_exists('isSuccess', $mailboxesOptions[$mailboxId])))
		{
			return null;
		}

		if (isset($mailboxesOptions[$mailboxId]['isSuccess']))
		{
			return (bool)$mailboxesOptions[$mailboxId]['isSuccess'];
		}

		return null;
	}

	public function getLastMailboxSyncTime($mailboxId)
	{
		$mailboxesOptions = $this->getMailboxesSyncInfo();
		if (!(isset($mailboxesOptions[$mailboxId]) && array_key_exists('timeStarted', $mailboxesOptions[$mailboxId])))
		{
			return null;
		}
		return $mailboxesOptions[$mailboxId]['timeStarted'];
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\AiAgent\Lifecycle\Cleanup;

use Bitrix\Bizproc\Integration\ImBot\BizprocBot;
use Bitrix\Bizproc\Internal\AiAgent\Lifecycle\ValueObject\ManagedAgentResourcePayload;
use Bitrix\Bizproc\Internal\Container;
use Bitrix\Bizproc\Internal\Entity\AiAgent\ManagedAgentInstance;
use Bitrix\Bizproc\Internal\Entity\AiAgent\ManagedAgentResource;
use Bitrix\Bizproc\Internal\Entity\AiAgent\ManagedAgentResourceType;
use Bitrix\Bizproc\Internal\Repository\AiAgent\ManagedAgentResourceRepositoryInterface;
use Bitrix\Bizproc\Internal\Service\AiAgentGrid\AgentChatbotsExtractor;
use Bitrix\Bizproc\Internal\Service\AiAgentGrid\ChatbotDeleteService;
use Bitrix\Im\Model\BotTable;
use Bitrix\Main\ArgumentOutOfRangeException;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;

/**
 * Cleanup participant of the chat bots owned by a managed system AI agent instance.
 *
 * One class serves both bot types: the type is a parameter of the constructor and is answered by
 * {@see self::type()}, so the participant is constructed and registered twice. The mechanics are the same for
 * both, only the module and the service that resolve a bot code differ.
 *
 * The RESOURCE_ID is the reserved bot code and not an id, because the code is what the reservation of an
 * absent bot owns before the bot exists. A code is reserved only for a bot the copy is going to create: a bot
 * that already existed before the instance was enabled stays an external dependency, is never assigned to the
 * instance and is therefore never deleted by this API.
 *
 * A bot carries no link to the template of the copy, so the ownership of a row is proven by the reserved code
 * together with the id of the bot that was first seen under it: the code is unique for the copy, and the
 * reservation of the module does not keep it taken inside the bot subsystem, therefore an ordinary producer may
 * register a bot of its own under the very same code once ours is gone. The id is pinned by the first
 * observation and never replaced, and a code that now resolves to another id is read as a bot that is already
 * gone. The price is a bot left behind when the very same code is legitimately rebuilt for us, which is
 * preferred over deleting a bot of somebody else.
 *
 * Bots are cleaned up after the workflows that create them and before the storage scope.
 */
final class BotCleanup implements ManagedResourceCleanupInterface
{
	/**
	 * Own deadline of this participant: the pass as a whole lasts five seconds and is shared by five types.
	 */
	public const DEFAULT_DEADLINE_SECONDS = 1.0;

	/**
	 * Reservations one reconciliation reads at a time: a copy creates a handful of bots, so a batch of this
	 * size never lets the reconciliation of one type swallow the rows of the whole pass.
	 */
	public const RESERVATION_BATCH_LIMIT = 50;

	public const ERROR_DATA_INVALID = 'AI_AGENT_BOT_DATA_INVALID';

	public const ERROR_RESOURCE_ID_INVALID = 'AI_AGENT_BOT_CODE_INVALID';

	public const ERROR_OWNER_UNREADABLE = 'AI_AGENT_BOT_OWNER_UNREADABLE';

	public const ERROR_READ_FAILED = 'AI_AGENT_BOT_READ_FAILED';

	public const ERROR_WRITE_FAILED = 'AI_AGENT_BOT_WRITE_FAILED';

	public const ERROR_DELETE_FAILED = 'AI_AGENT_BOT_DELETE_FAILED';

	/**
	 * The module that owns the bots is not available, hence the operation is not finished and is certainly not
	 * read as a successful absence of the bot.
	 *
	 * This is a failure and not a continuation: the answer is instant and cannot change while the module is
	 * missing, so a pending result would make the background pass ask the very same question every 300 seconds
	 * without ever charging a retry delay.
	 */
	public const REASON_MODULE_UNAVAILABLE = 'AI_AGENT_BOT_MODULE_UNAVAILABLE';

	/**
	 * The deletion was accepted while the bot is still there: a continuation until the deadline, a failure after.
	 */
	public const REASON_STILL_PRESENT = 'AI_AGENT_BOT_STILL_PRESENT';

	private readonly ?ManagedAgentResourceRepositoryInterface $resourceRepository;

	/**
	 * Bot kind of {@see AgentChatbotsExtractor}, which is how {@see ChatbotDeleteService} is addressed.
	 */
	private readonly string $botKind;

	/**
	 * Value of the CLASS column the reserved code is resolved by.
	 */
	private readonly string $botClass;

	/**
	 * @throws ArgumentOutOfRangeException when the type is not one of the two bot types
	 */
	public function __construct(
		private readonly ManagedAgentResourceType $type,
		?ManagedAgentResourceRepositoryInterface $resourceRepository = null,
		private readonly ChatbotDeleteService $deleteService = new ChatbotDeleteService(),
		private readonly float $deadlineSeconds = self::DEFAULT_DEADLINE_SECONDS,
	)
	{
		$this->botKind = self::resolveBotKind($type);
		$this->botClass = self::resolveBotClass($this->botKind);
		$this->resourceRepository = $resourceRepository ?? Container::getManagedAgentResourceRepository();
	}

	public function type(): ManagedAgentResourceType
	{
		return $this->type;
	}

	public function reconcile(
		ManagedAgentInstance $instance,
		ManagedResourceCleanupBudget $budget,
	): ManagedResourceCleanupResult
	{
		if ($budget->isExhausted())
		{
			return ManagedResourceCleanupResult::createPending();
		}

		$budget->startParticipantDeadline($this->type(), $this->deadlineSeconds);

		if ($this->resourceRepository === null)
		{
			return ManagedResourceCleanupResult::createFailed(self::ERROR_WRITE_FAILED);
		}

		$instanceId = (int)$instance->getId();
		if ($instanceId <= 0)
		{
			return ManagedResourceCleanupResult::createFailed(self::ERROR_OWNER_UNREADABLE);
		}

		return $this->completeReservations($instanceId, $budget);
	}

	public function cleanup(
		ManagedAgentResource $resource,
		ManagedResourceCleanupBudget $budget,
	): ManagedResourceCleanupResult
	{
		if ($budget->isExhausted())
		{
			return ManagedResourceCleanupResult::createPending();
		}

		$budget->startParticipantDeadline($this->type(), $this->deadlineSeconds);

		if (!ManagedAgentResourcePayload::matchesSchema($resource->getData(), $this->type()))
		{
			return ManagedResourceCleanupResult::createFailed(self::ERROR_DATA_INVALID);
		}

		$botCode = $resource->getResourceId();
		if ($botCode === '')
		{
			return ManagedResourceCleanupResult::createFailed(self::ERROR_RESOURCE_ID_INVALID);
		}

		if (!self::areBotModulesAvailable())
		{
			return ManagedResourceCleanupResult::createFailed(self::REASON_MODULE_UNAVAILABLE);
		}

		$pinnedBotId = $resource->getData()[ManagedAgentResourcePayload::KEY_BOT_ID] ?? null;

		try
		{
			$botId = $this->resolveBotIdByCode($botCode);
			$budget->consumeRows(1);
		}
		catch (\Throwable)
		{
			return ManagedResourceCleanupResult::createFailed(self::ERROR_READ_FAILED);
		}

		if ($botId === null)
		{
			// The reserved code holds no bot: either it was never created or it is already gone.
			return ManagedResourceCleanupResult::createComplete();
		}

		if ($pinnedBotId !== null && $botId !== $pinnedBotId)
		{
			// Our bot was already deleted and the code has been taken by another producer since: the pinned id is
			// the only proof this row ever had, so the row is dropped instead of unregistering a foreign bot.
			return ManagedResourceCleanupResult::createComplete();
		}

		return $this->deleteBot($botCode, $botId, $budget);
	}

	private function deleteBot(
		string $botCode,
		int $botId,
		ManagedResourceCleanupBudget $budget,
	): ManagedResourceCleanupResult
	{
		try
		{
			$deleted = $this->deleteService->deleteOwnedBot($this->botKind, $botId);
			$budget->consumeRows(1);
		}
		catch (\Throwable)
		{
			return ManagedResourceCleanupResult::createFailed(self::ERROR_DELETE_FAILED);
		}

		if (self::describesUnavailableModules($deleted))
		{
			return ManagedResourceCleanupResult::createFailed(self::REASON_MODULE_UNAVAILABLE);
		}

		try
		{
			$remaining = $this->resolveBotIdByCode($botCode);
			$budget->consumeRows(1);
		}
		catch (\Throwable)
		{
			return ManagedResourceCleanupResult::createFailed(self::ERROR_READ_FAILED);
		}

		if ($remaining === null)
		{
			return ManagedResourceCleanupResult::createComplete();
		}

		if (!$deleted->isSuccess())
		{
			return ManagedResourceCleanupResult::createFailed(self::ERROR_DELETE_FAILED);
		}

		return self::leaveUnfinished(self::REASON_STILL_PRESENT, $budget);
	}

	/**
	 * Completes the reservations of this instance with the ids of the bots that were actually created.
	 *
	 * A reserved code is registered before its bot exists, therefore nothing has to be adopted here: a live
	 * bot of the copy without a registration cannot happen, and an existing bot of the portal is not owned.
	 *
	 * The rows are walked by keyset inside one call and the walk starts over on the next pass, because the
	 * cursor of a pass outlives nothing. What makes repeated passes progress is the deletion instead: it drops
	 * the reservation of every bot it removed, so the next pass walks fewer rows. The reconciliation is bounded
	 * by the share of the budget of its own phase, hence it can never spend the rows that deletion needs.
	 */
	private function completeReservations(
		int $instanceId,
		ManagedResourceCleanupBudget $budget,
	): ManagedResourceCleanupResult
	{
		$afterId = 0;
		$modulesChecked = false;

		while (true)
		{
			if ($budget->isExhausted())
			{
				return ManagedResourceCleanupResult::createPending();
			}

			$limit = min($budget->getRemainingRows(), self::RESERVATION_BATCH_LIMIT);

			try
			{
				$resources = $this->resourceRepository->findBatchByType($instanceId, $this->type(), $limit, $afterId);
			}
			catch (\Throwable)
			{
				return ManagedResourceCleanupResult::createFailed(self::ERROR_READ_FAILED);
			}

			$budget->consumeRows(count($resources));

			if ($resources === [])
			{
				return ManagedResourceCleanupResult::createComplete();
			}

			// Asked only when a reservation exists: an instance without bots must not depend on the module.
			if (!$modulesChecked)
			{
				if (!self::areBotModulesAvailable())
				{
					return ManagedResourceCleanupResult::createFailed(self::REASON_MODULE_UNAVAILABLE);
				}

				$modulesChecked = true;
			}

			// The portion itself may have used up the budget, and then it is adopted by the next pass unread.
			if ($budget->isExhausted())
			{
				return ManagedResourceCleanupResult::createPending();
			}

			try
			{
				$botIds = $this->resolveBotIdsByCodes(self::describeReservedCodes($resources));
			}
			catch (\Throwable)
			{
				return ManagedResourceCleanupResult::createFailed(self::ERROR_READ_FAILED);
			}

			foreach ($resources as $resource)
			{
				if ($budget->isExhausted())
				{
					return ManagedResourceCleanupResult::createPending();
				}

				$failure = $this->rememberBotId($resource, $botIds, $budget);
				if ($failure !== null)
				{
					return $failure;
				}

				$afterId = max($afterId, (int)$resource->getId());
			}

			if (count($resources) < $limit)
			{
				return ManagedResourceCleanupResult::createComplete();
			}
		}
	}

	/**
	 * Pins the id of the bot the reserved code resolves to, or null when there is nothing to store.
	 *
	 * The first observation wins and a stored id is never replaced: it is what proves that the bot under the code
	 * is still ours, and an id rewritten to whatever the code holds now would prove nothing at all.
	 *
	 * The row is written directly and idempotently: the creation barrier of the registry refuses a
	 * registration while the instance is being deleted, and this is where the reconciliation runs.
	 *
	 * The bots of the whole portion are resolved at once, and every reservation of it still costs the one row of
	 * the budget its own lookup used to cost: what the portion changes is the number of queries and not the
	 * amount of work a pass is allowed to do.
	 *
	 * @param array<string, int> $botIds bots of the portion, by the reserved code they were resolved from
	 * @return ManagedResourceCleanupResult|null failure of the reconciliation, or null when it may go on
	 */
	private function rememberBotId(
		ManagedAgentResource $resource,
		array $botIds,
		ManagedResourceCleanupBudget $budget,
	): ?ManagedResourceCleanupResult
	{
		$botCode = $resource->getResourceId();
		if ($botCode === '')
		{
			// An unusable row is reported by cleanup(), which is the only place allowed to drop it.
			return null;
		}

		$botId = $botIds[$botCode] ?? null;
		$budget->consumeRows(1);

		if ($botId === null)
		{
			// A reservation whose bot was never created is confirmed as complete by cleanup().
			return null;
		}

		$stored = ManagedAgentResourcePayload::matchesSchema($resource->getData(), $this->type())
			? $resource->getData()
			: []
		;

		if (isset($stored[ManagedAgentResourcePayload::KEY_BOT_ID]))
		{
			return null;
		}

		try
		{
			$this->resourceRepository->save($resource->withData(ManagedAgentResourcePayload::buildForBot($botId)));
			$budget->consumeRows(1);
		}
		catch (\Throwable)
		{
			return ManagedResourceCleanupResult::createFailed(self::ERROR_WRITE_FAILED);
		}

		return null;
	}

	/**
	 * Bots of one portion of reservations, by the reserved code each of them was resolved from.
	 *
	 * @param list<string> $botCodes
	 * @return array<string, int> ids of the codes that hold a bot; a code without one is absent
	 */
	private function resolveBotIdsByCodes(array $botCodes): array
	{
		if ($botCodes === [])
		{
			return [];
		}

		$rows = BotTable::query()
			->setSelect(['BOT_ID', 'CODE'])
			->where('CLASS', $this->botClass)
			->whereIn('CODE', $botCodes)
			->setOrder(['BOT_ID' => 'ASC'])
			->fetchAll()
		;

		$botIds = [];
		foreach ($rows as $row)
		{
			$botId = (int)$row['BOT_ID'];
			$code = (string)$row['CODE'];

			if ($botId > 0 && !isset($botIds[$code]))
			{
				$botIds[$code] = $botId;
			}
		}

		return $botIds;
	}

	/**
	 * Reserved codes of the portion, deduplicated and without the unusable ones cleanup() reports on its own.
	 *
	 * @param ManagedAgentResource[] $resources
	 * @return list<string>
	 */
	private static function describeReservedCodes(array $resources): array
	{
		$codes = [];
		foreach ($resources as $resource)
		{
			$code = $resource->getResourceId();
			if ($code !== '')
			{
				$codes[$code] = true;
			}
		}

		// A numeric key of an array is an integer, and an integer in the filter of a string column costs the index.
		return array_map(strval(...), array_keys($codes));
	}

	/**
	 * Id of the bot the reserved code belongs to, or null when no such bot exists.
	 */
	private function resolveBotIdByCode(string $botCode): ?int
	{
		$row = BotTable::query()
			->setSelect(['BOT_ID'])
			->where('CLASS', $this->botClass)
			->where('CODE', $botCode)
			->setOrder(['BOT_ID' => 'ASC'])
			->setLimit(1)
			->fetch()
		;

		$botId = $row === false ? 0 : (int)$row['BOT_ID'];

		return $botId > 0 ? $botId : null;
	}

	/**
	 * The operation is left unfinished: a continuation until the deadline of the participant, a failure after.
	 *
	 * Both of them charge the retry delay - the bot is gone from neither of them - and what the deadline decides
	 * is whether the reason is stored as the last error of the instance as well.
	 */
	private static function leaveUnfinished(
		string $reason,
		ManagedResourceCleanupBudget $budget,
	): ManagedResourceCleanupResult
	{
		return $budget->isParticipantOverdue()
			? ManagedResourceCleanupResult::createFailed($reason)
			: ManagedResourceCleanupResult::createBlocked($reason)
		;
	}

	private static function describesUnavailableModules(Result $deleted): bool
	{
		$unavailable = ChatbotDeleteService::ERROR_MODULES_UNAVAILABLE;

		return $deleted->getErrorCollection()->getErrorByCode($unavailable) !== null;
	}

	private static function areBotModulesAvailable(): bool
	{
		return Loader::includeModule('im') && Loader::includeModule('imbot');
	}

	/**
	 * @throws ArgumentOutOfRangeException when the type is not one of the two bot types
	 */
	private static function resolveBotKind(ManagedAgentResourceType $type): string
	{
		return match ($type)
		{
			ManagedAgentResourceType::BizprocBot => AgentChatbotsExtractor::KIND_BIZPROC,
			ManagedAgentResourceType::OpenLinesBot => AgentChatbotsExtractor::KIND_OPENLINES,
			default => throw new ArgumentOutOfRangeException('type', [
				ManagedAgentResourceType::BizprocBot->value,
				ManagedAgentResourceType::OpenLinesBot->value,
			]),
		};
	}

	private static function resolveBotClass(string $botKind): string
	{
		return $botKind === AgentChatbotsExtractor::KIND_BIZPROC
			? BizprocBot::class
			: AgentChatbotsExtractor::OPENLINES_BOT_CLASS
		;
	}
}

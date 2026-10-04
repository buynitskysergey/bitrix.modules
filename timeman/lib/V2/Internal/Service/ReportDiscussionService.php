<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Internal\Service;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\Add\AddResult;
use Bitrix\Im\V2\Chat\ChatFactory;
use Bitrix\Main\Application;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\UserTable;
use Bitrix\Timeman\V2\Internal\Model\ReportDiscussionTable;
use Bitrix\Timeman\V2\Public\Dto\FullReport\FullReport;
use Bitrix\Timeman\V2\Public\Dto\Report\RecordReportType;
use Bitrix\Timeman\V2\Public\Provider\FullReportProvider;

/**
 * Server-side "discuss report" domain operation: resolves the manager/employee pair,
 * ensures a single pair chat, links the report to that chat and idempotently posts the
 * AI/robot message and the handwritten comment on behalf of the acting user.
 */
class ReportDiscussionService
{
	public const CHAT_ENTITY_TYPE = 'TIMEMAN_REPORT_DISCUSS';

	public const ERROR_REPORT_NOT_FOUND = 'REPORT_NOT_FOUND';
	public const ERROR_ACCESS_DENIED = 'ACCESS_DENIED';
	public const ERROR_NO_MANAGER = 'NO_MANAGER';
	public const ERROR_CHAT_CREATION = 'CHAT_CREATION_ERROR';

	private const LOCK_TIMEOUT = 5;

	public function __construct(
		private readonly FullReportProvider $reportProvider,
		private readonly FullReportUserService $userService,
		private readonly ReportPeriodPhraseFormatter $periodPhraseFormatter,
		private readonly ReportTextNormalizerService $reportTextNormalizer,
	)
	{
	}

	public function discuss(int $reportId, int $actorId): Result
	{
		$result = new Result();

		$report = $this->loadReport($reportId);
		if ($report === null)
		{
			return $result->addError(new Error('Report not found', self::ERROR_REPORT_NOT_FOUND));
		}

		$employeeId = $report->userId;

		// Drafts (ACTIVE=N) are not discussable by anyone, including the owner: a discussion only
		// makes sense for a submitted report. This also keeps a manager guessing a sequential
		// reportId from surfacing an unsent draft.
		if ($report->active !== true)
		{
			return $result->addError(new Error('Report not found', self::ERROR_REPORT_NOT_FOUND));
		}

		$managerIds = $this->resolveManagerIds($employeeId);

		if ($actorId === $employeeId)
		{
			$managerId = $managerIds[0] ?? 0;
			if ($managerId <= 0)
			{
				return $result->addError(new Error('No manager to discuss with', self::ERROR_NO_MANAGER));
			}
		}
		elseif (in_array($actorId, $managerIds, true) && $this->canActorReadEmployee($actorId, $employeeId))
		{
			// Being an HR manager is not enough: Timeman read rights (tm_read/tm_read_subordinate)
			// may have been revoked while the manager stayed in the org structure. Without this the
			// manager could brute-force reportId to pull a report they are no longer allowed to read.
			$managerId = $actorId;
		}
		else
		{
			return $result->addError(new Error('Access denied', self::ERROR_ACCESS_DENIED));
		}

		if (!Loader::includeModule('im'))
		{
			return $result->addError(new Error('Chat creation error', self::ERROR_CHAT_CREATION));
		}

		// Refuse before touching IM so we never create an orphan "ghost" pair chat that cannot be
		// linked. Temporary runtime safety net for installations updated before the versioned
		// updater (deliberately deferred) has created the discussion table; drop it once that ships.
		if (!$this->isDiscussionStorageReady())
		{
			return $result->addError(new Error('Chat creation error', self::ERROR_CHAT_CREATION));
		}

		$connection = Application::getConnection();
		$lockName = 'timeman:report-discuss:' . $reportId . ':' . $managerId;

		if (!$connection->lock($lockName, self::LOCK_TIMEOUT))
		{
			(new \Bitrix\Main\Diag\LoggerFactory())->createById('timeman.report-discussion')?->warning(
				'Report discussion lock timeout (degraded: chat markers skipped) for reportId={reportId}, managerId={managerId}, actorId={actorId}',
				[
					'reportId' => $reportId,
					'managerId' => $managerId,
					'actorId' => $actorId,
				],
			);

			// Rare path: a concurrent operation on this pair outran the timeout.
			// Best effort: return the (idempotent) existing chat; markers are left
			// to the owner of the lock. Degradation acceptable only under timeout.
			$chatResult = $this->addPairChat($managerId, $employeeId, $actorId);
			if (!$chatResult->isSuccess())
			{
				return $result->addError(new Error('Chat creation error', self::ERROR_CHAT_CREATION));
			}

			// Honour the public `created` contract even on this degraded path: the chat may have
			// been freshly created here, so derive the flag from ALREADY_EXISTS like the main branch.
			$created = !($chatResult->getResult()['ALREADY_EXISTS'] ?? false);

			return $result->setData([
				'dialogId' => 'chat' . (int)$chatResult->getChatId(),
				'created' => $created,
			]);
		}

		try
		{
			$chatResult = $this->addPairChat($managerId, $employeeId, $actorId);
			if (!$chatResult->isSuccess())
			{
				return $result->addError(new Error('Chat creation error', self::ERROR_CHAT_CREATION));
			}

			$chatId = (int)$chatResult->getChatId();
			$created = !($chatResult->getResult()['ALREADY_EXISTS'] ?? false);

			// Idempotent repeat clicks must not re-UPSERT an unchanged row (extra lock and, on
			// PostgreSQL, a new row version): read the link under the lock and write only when it
			// is missing or the linked chat actually changed.
			$link = ReportDiscussionTable::getByReportAndManager($reportId, $managerId);
			if ($link === null || (int)$link['CHAT_ID'] !== $chatId)
			{
				$linkData = [
					'REPORT_ID' => $reportId,
					'MANAGER_ID' => $managerId,
					'CHAT_ID' => $chatId,
					'EMPLOYEE_ID' => $employeeId,
				];

				// Chat changed (old pair chat was deleted, a new one created): drop the message
				// markers so the report content is re-delivered into the fresh chat instead of
				// being skipped as already sent. A plain repeat click keeps the markers.
				if ($link !== null)
				{
					$linkData['MAIN_MESSAGE_ID'] = null;
					$linkData['COMMENT_MESSAGE_ID'] = null;
				}

				ReportDiscussionTable::merge($linkData);

				$link = ReportDiscussionTable::getByReportAndManager($reportId, $managerId);
			}

			if ($link !== null)
			{
				$this->deliverMessages($report, $chatId, $actorId, $link);
			}

			return $result->setData([
				'dialogId' => 'chat' . $chatId,
				'created' => $created,
			]);
		}
		finally
		{
			$connection->unlock($lockName);
		}
	}

	/**
	 * @param array<string, mixed> $link
	 */
	private function deliverMessages(FullReport $report, int $chatId, int $actorId, array $link): void
	{
		$linkId = (int)$link['ID'];

		if (($link['MAIN_MESSAGE_ID'] ?? null) === null)
		{
			$body = $this->buildMainMessageBody($report);
			if (trim($body) !== '')
			{
				$messageId = $this->sendChatMessage($chatId, $actorId, $body);
				if ($messageId > 0)
				{
					ReportDiscussionTable::update($linkId, ['MAIN_MESSAGE_ID' => $messageId]);
				}
			}
		}

		if (($link['COMMENT_MESSAGE_ID'] ?? null) === null)
		{
			$comment = $this->normalizeForChat((string)$report->report);
			if (trim($comment) !== '')
			{
				$messageId = $this->sendChatMessage($chatId, $actorId, $comment);
				if ($messageId > 0)
				{
					ReportDiscussionTable::update($linkId, ['COMMENT_MESSAGE_ID' => $messageId]);
				}
			}
		}
	}

	private function buildMainMessageBody(FullReport $report): string
	{
		$text = $this->normalizeForChat((string)$report->reportExtended);

		// The period phrase is added for robot reports only; AI reports carry it in the source itself.
		if ($report->type === RecordReportType::AI_REPORT)
		{
			return $text;
		}

		$phrase = $this->periodPhraseFormatter->format(
			(int)($report->dateFrom ?? $report->reportDate ?? 0),
			(int)($report->dateTo ?? $report->reportDate ?? 0),
		);

		$parts = array_filter(
			[$phrase, $text],
			static fn (string $part): bool => trim($part) !== '',
		);

		return implode("\n\n", $parts);
	}

	private function normalizeForChat(string $text): string
	{
		return $this->reportTextNormalizer->flattenParagraphsForChat(
			$this->reportTextNormalizer->normalize($text),
		);
	}

	protected function loadReport(int $reportId): ?FullReport
	{
		// The discussion flow uses only owner id, dates, type and the two report texts; skip the
		// participant hydration (extra user and photo queries) that getById() performs per click.
		return $this->reportProvider->getByIdWithoutParticipants($reportId);
	}

	/**
	 * @return array<int, int>
	 */
	protected function resolveManagerIds(int $employeeId): array
	{
		return $this->userService->getManagerIds($employeeId);
	}

	protected function canActorReadEmployee(int $actorId, int $employeeId): bool
	{
		return $this->userService->canUserReadUser($actorId, $employeeId);
	}

	/**
	 * Temporary runtime safety net until the versioned updater (deliberately deferred) creates the
	 * discussion table on existing installations. Remove once that updater ships.
	 */
	protected function isDiscussionStorageReady(): bool
	{
		return Application::getConnection()->isTableExists(ReportDiscussionTable::getTableName());
	}

	/**
	 * Ensures the pair chat for a discussion, trusting ownership recorded in our discussion table.
	 *
	 * The table is the single source of truth for which chat belongs to a pair: a chat we recorded
	 * earlier is reused as-is, without checking its membership, so a third participant added to the
	 * pair chat does not break the discussion. A new chat is created only when we have no recorded
	 * chat yet (first discussion) or the recorded chat was hard-deleted. The strict integrity check
	 * applies only on that create path, where the predictable ENTITY_ID could have been pre-hijacked
	 * (or two first discussions of the same pair raced to create it).
	 */
	protected function addPairChat(int $managerId, int $employeeId, int $actorId): AddResult
	{
		$knownChatId = ReportDiscussionTable::findPairChatId($managerId, $employeeId);
		if ($knownChatId > 0 && $this->chatExists($knownChatId))
		{
			// Trust by ownership: we created and recorded this chat, membership is not re-checked.
			return (new AddResult())->setResult(['CHAT_ID' => $knownChatId, 'ALREADY_EXISTS' => true]);
		}

		$result = ChatFactory::getInstance()
			->withContextUser($actorId)
			->addUniqueChat([
				'TYPE' => Chat::IM_TYPE_CHAT,
				'ENTITY_TYPE' => self::CHAT_ENTITY_TYPE,
				'ENTITY_ID' => $managerId . '_' . $employeeId,
				'USERS' => [$managerId, $employeeId],
				'AUTHOR_ID' => $actorId,
				'TITLE' => $this->buildChatTitle($employeeId),
				'SKIP_ADD_MESSAGE' => 'Y',
			])
		;

		if (!$result->isSuccess())
		{
			return $result;
		}

		$alreadyExists = (bool)($result->getResult()['ALREADY_EXISTS'] ?? false);
		if ($alreadyExists && !$this->isPairChatTrusted((int)$result->getChatId(), $managerId, $employeeId))
		{
			return (new AddResult())->addError(
				new Error('Discussion chat failed integrity check', self::ERROR_CHAT_CREATION),
			);
		}

		return $result;
	}

	/**
	 * Whether a chat we recorded earlier still exists. A hard-deleted chat lets the flow fall through
	 * to re-create the pair chat instead of posting into a dead one.
	 */
	protected function chatExists(int $chatId): bool
	{
		if ($chatId <= 0)
		{
			return false;
		}

		return Chat::getInstance($chatId)->isExist();
	}

	/**
	 * Fail-closed guard against chat pre-hijack on the create path only. The entity key
	 * CHAT_ENTITY_TYPE + managerId_employeeId is predictable, and addUniqueChat() reuses any chat
	 * matching it. A user could pre-create such a chat via public im.chat.add and have report
	 * content delivered into it; a concurrent first discussion of the same pair can also reuse a
	 * chat just created by the racing request. Only trust a chat whose author and exact membership
	 * are the pair. Chats already recorded in our table skip this: they are trusted by ownership.
	 */
	private function isPairChatTrusted(int $chatId, int $managerId, int $employeeId): bool
	{
		if ($chatId <= 0)
		{
			return false;
		}

		$chat = Chat::getInstance($chatId);

		$authorId = (int)$chat->getAuthorId();
		if ($authorId !== $managerId && $authorId !== $employeeId)
		{
			return false;
		}

		$memberIds = array_map('intval', $chat->getRelations()->getUserIds());
		sort($memberIds);

		$expected = [$managerId, $employeeId];
		sort($expected);

		return $memberIds === $expected;
	}

	protected function sendChatMessage(int $chatId, int $actorId, string $text): int
	{
		if (!Loader::includeModule('im'))
		{
			return 0;
		}

		$messageId = \CIMMessenger::Add([
			'MESSAGE_TYPE' => Chat::IM_TYPE_CHAT,
			'TO_CHAT_ID' => $chatId,
			'FROM_USER_ID' => $actorId,
			'MESSAGE' => $text,
			'SYSTEM' => 'N',
			// Report text is user-authored data: never let a leading slash run as a bot
			// command in the acting manager's context.
			'SKIP_COMMAND' => 'Y',
		]);

		return (int)$messageId;
	}

	private function buildChatTitle(int $employeeId): string
	{
		return (string)Loc::getMessage(
			'TIMEMAN_REPORT_DISCUSSION_CHAT_TITLE',
			['#USER_NAME#' => $this->getUserName($employeeId)],
		);
	}

	private function getUserName(int $userId): string
	{
		$user = UserTable::getList([
			'select' => ['NAME', 'LAST_NAME', 'SECOND_NAME', 'LOGIN'],
			'filter' => ['=ID' => $userId],
			'limit' => 1,
		])->fetch();

		if (!$user)
		{
			return '';
		}

		return (string)\CUser::FormatName(\CSite::GetNameFormat(), $user, true, false);
	}
}

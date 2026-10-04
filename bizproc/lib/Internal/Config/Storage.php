<?php

namespace Bitrix\Bizproc\Internal\Config;

use Bitrix\Main\Application;
use Bitrix\Main\Engine\CurrentUser;
use CUserOptions;

class Storage
{
	protected const OPTION_CATEGORY = 'bizproc';
	protected const VIEWED_NEW_ROBOT_IDS_OPTION_NAME = 'viewedNewRobotIds';
	protected const EXISTING_RUNS_WARNING_OPTION_NAME = 'aiAgentsExistingRunsWarningShown';
	protected const AI_AGENTS_FILTER_HINT_OPTION_NAME = 'aiAgentsFilterHint';

	private const EXISTING_RUNS_WARNING_LOCK_PREFIX = 'bp_ai_agents_existing_runs_warning_';
	private const WARNING_SPENT = 'Y';
	private const WARNING_NOT_SPENT = 'N';
	private const FILTER_HINT_STATE_KEY = 'state';
	private const FILTER_HINT_STATE_NONE = 'none';
	private const FILTER_HINT_STATE_PENDING = 'pending';
	private const FILTER_HINT_STATE_SHOWN = 'shown';

	public function getViewedNewRobotIds(): array
	{
		return array_values(CUserOptions::getOption(static::OPTION_CATEGORY, static::VIEWED_NEW_ROBOT_IDS_OPTION_NAME, []));
	}

	public function setViewedNewRobotIds(array $ids): void
	{
		CUserOptions::setOption(static::OPTION_CATEGORY, static::VIEWED_NEW_ROBOT_IDS_OPTION_NAME, $ids);
	}

	/**
	 * Whether the current user has already spent the single right to see the existing-runs
	 * warning. Cheap early check for the warning policy and a render-time hint for the page; an
	 * optimization only - the one-shot guarantee comes from claimExistingRunsWarning().
	 */
	public function isExistingRunsWarningSpent(): bool
	{
		return $this->readExistingRunsWarning() === self::WARNING_SPENT;
	}

	/**
	 * Atomically spends the current user's single right to see the existing-runs warning: returns
	 * true only to the call that actually took it, false to every later one. That asymmetry is
	 * what makes "shown exactly once" a checked property rather than an agreement between two
	 * requests.
	 *
	 * The lock name is built from the current user, so users never queue behind each other, and a
	 * call that cannot take the lock does not wait and does not show the warning (fail-open).
	 *
	 * The check under the lock reads past the CUserOptions caches on purpose: the cheap
	 * isExistingRunsWarningSpent() above it has already cached the whole option category for this
	 * request, so a cached read here would answer from a snapshot taken before the competing
	 * request wrote its value. A check-and-write under a lock has to read from the same source of
	 * truth the write goes to - hence the master-only read. The write stays under the lock but
	 * outside that mode: a write is never routed to a replica anyway, while outside the mode the
	 * pool sees it and keeps later reads of b_user_option on the master. setOption() drops both
	 * cache levels of the category, so the cheap reads that follow it in this request are correct.
	 */
	public function claimExistingRunsWarning(): bool
	{
		$connection = Application::getConnection();
		$lockName = self::EXISTING_RUNS_WARNING_LOCK_PREFIX . $this->getCurrentUserId();

		if (!$connection->lock($lockName))
		{
			return false;
		}

		try
		{
			if ($this->readStoredExistingRunsWarningFromMaster() === self::WARNING_SPENT)
			{
				return false;
			}

			return CUserOptions::setOption(
				static::OPTION_CATEGORY,
				static::EXISTING_RUNS_WARNING_OPTION_NAME,
				self::WARNING_SPENT,
			);
		}
		finally
		{
			try
			{
				$connection->unlock($lockName);
			}
			catch (\Throwable)
			{
				// the lock is released with the connection anyway, so a failure here must not
				// turn a right that was already taken into an error
			}
		}
	}

	/**
	 * State of the one-off filter hint for the current user: 'none' when the hint was never
	 * scheduled, 'pending' when it was scheduled but has not appeared yet, 'shown' when it
	 * actually appeared. The client owns the value (BX.userOptions.save); the server only reads it,
	 * and reads anything it does not know as 'none' - the option is client-written, so the promised
	 * three states have to be enforced where they are declared.
	 */
	public function getAiAgentsFilterHintState(): string
	{
		$option = CUserOptions::getOption(
			static::OPTION_CATEGORY,
			static::AI_AGENTS_FILTER_HINT_OPTION_NAME,
			[],
		);

		$state = is_array($option) ? ($option[self::FILTER_HINT_STATE_KEY] ?? null) : null;
		$knownStates = [
			self::FILTER_HINT_STATE_NONE,
			self::FILTER_HINT_STATE_PENDING,
			self::FILTER_HINT_STATE_SHOWN,
		];

		return in_array($state, $knownStates, true) ? $state : self::FILTER_HINT_STATE_NONE;
	}

	/**
	 * The option lives in the user's own category and can be written with any shape through the
	 * generic user_options endpoint, so anything but a scalar is read as "not spent" instead of being
	 * cast to a string - the same contract the two readers around this one keep.
	 */
	private function readExistingRunsWarning(): string
	{
		$option = CUserOptions::getOption(
			static::OPTION_CATEGORY,
			static::EXISTING_RUNS_WARNING_OPTION_NAME,
			self::WARNING_NOT_SPENT,
		);

		return is_scalar($option) ? (string)$option : self::WARNING_NOT_SPENT;
	}

	/**
	 * The same value taken from the table itself: getOption() answers from a static per-category
	 * cache, getList() always queries. With the cluster module that query may be routed to a
	 * replica which has not seen the competing write yet, so it is forced to the master - the
	 * check of a check-and-write has to come from the same place the write goes to. The name is
	 * lowered because setOption() stores it that way, and the value is unserialized the same way
	 * getOption() does it.
	 */
	private function readStoredExistingRunsWarningFromMaster(): string
	{
		$pool = Application::getInstance()->getConnectionPool();
		$pool->useMasterOnly(true);

		try
		{
			$row = CUserOptions::getList([], [
				'USER_ID' => $this->getCurrentUserId(),
				'CATEGORY' => static::OPTION_CATEGORY,
				'NAME' => mb_strtolower(static::EXISTING_RUNS_WARNING_OPTION_NAME),
			])->fetch();
		}
		finally
		{
			$pool->useMasterOnly(false);
		}

		$stored = $row ? (string)($row['VALUE'] ?? '') : '';
		if ($stored === '')
		{
			return self::WARNING_NOT_SPENT;
		}

		$value = unserialize($stored, ['allowed_classes' => false]);

		return is_scalar($value) ? (string)$value : self::WARNING_NOT_SPENT;
	}

	private function getCurrentUserId(): int
	{
		return (int)CurrentUser::get()->getId();
	}
}

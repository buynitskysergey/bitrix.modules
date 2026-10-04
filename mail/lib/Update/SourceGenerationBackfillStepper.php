<?php

declare(strict_types=1);

namespace Bitrix\Mail\Update;

use Bitrix\Mail\Internal\Service\SourceGeneration\BackfillService;
use Bitrix\Main;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;

/**
 * Background walk that prepares G1 for every existing IMAP-family mailbox (P1.T3).
 *
 * The stepper is only the pacing shell: the whole preparation of one mailbox lives
 * in BackfillService, the very same repeatable initializer the priority user run
 * uses, so both modes share one operation and can never create two G1.
 *
 * The keyset cursor selects mailboxes by the empty active generation pointer, not
 * by a fixed upper id: a mailbox created while the walk runs gets its G1 at the
 * creation point and never enters the cursor; only a mailbox whose creation-time
 * initialization failed stays pending and is picked up here.
 *
 * A mailbox that cannot finish within one step is retried while it shows progress
 * (its unassigned row count keeps falling: a big mailbox legitimately needs many
 * budgeted steps). A stalled mailbox (e.g. its sync lock stays busy) is skipped
 * after a few attempts and revisited by the next pass; the walk gives up after a
 * pass without a single completion, leaving the rest to the priority run.
 *
 * The walk runs only where {@see ENABLED_OPTION} allows it, and by default it does not.
 */
class SourceGenerationBackfillStepper extends Main\Update\Stepper
{
	/** Mailboxes one step may complete. */
	protected const MAILBOXES_PER_STEP = 20;

	/** Physical rows one step may assign to a generation. */
	protected const ROW_BUDGET_PER_STEP = 20000;

	/** Steps without progress before the current mailbox is skipped until the next pass. */
	protected const MAX_STALLED_ATTEMPTS = 10;

	/** Full passes over the pending mailboxes. */
	protected const MAX_PASSES = 3;

	/**
	 * Whether the portal runs the walk at all. Off by default, and that is the point:
	 * bindClass() gives the agent an interval of one second, so the walk took a step on
	 * nearly every agent cycle, and a step assigns up to ROW_BUDGET_PER_STEP rows in
	 * batches - a portal saw a continuous stream of batch updates over its placements.
	 *
	 * A walk that is not allowed finishes on its very first run, and a finished stepper
	 * removes both its agent and its option by itself. That is what withdraws the walk
	 * from a portal that already carries the agent, without a query per portal.
	 *
	 * Enabling it again takes two steps, and both belong to the update that plans the
	 * remaining mailboxes (see .ai/knowledge-base/notes/source-generation-indexes.md):
	 * set the option, and bind the stepper anew - the withdrawn walk has no agent left.
	 */
	public const ENABLED_OPTION = 'source_generations_backfill_enabled';

	protected static $moduleId = 'mail';

	public function execute(array &$option)
	{
		if (!$this->isEnabled())
		{
			return self::FINISH_EXECUTION;
		}

		if (empty($option))
		{
			$option['lastId'] = 0;
			$option['pass'] = 1;
			$option['passHadProgress'] = false;
			$option['attempts'] = 0;
			$option['count'] = $this->countPendingMailboxes();
			$option['steps'] = 0;
		}

		$service = $this->createService();

		for ($guard = static::MAILBOXES_PER_STEP; $guard > 0; $guard--)
		{
			$mailboxId = $this->findNextPendingMailboxId((int)$option['lastId']);

			if ($mailboxId === null)
			{
				if (!$option['passHadProgress'] || $option['pass'] >= static::MAX_PASSES)
				{
					return self::FINISH_EXECUTION;
				}

				if ($this->findNextPendingMailboxId(0) === null)
				{
					return self::FINISH_EXECUTION;
				}

				$option['pass']++;
				$option['lastId'] = 0;
				$option['passHadProgress'] = false;
				$option['attempts'] = 0;

				return self::CONTINUE_EXECUTION;
			}

			$assignedRows = 0;

			try
			{
				$state = $service->initializeUnderOperationLock($mailboxId, static::ROW_BUDGET_PER_STEP, $assignedRows);
			}
			catch (\Throwable)
			{
				// A deadlock or an unexpected row must not kill the agent, the run is repeatable
				$state = BackfillService::STATE_INITIALIZING_G1;
			}

			if ($state !== BackfillService::STATE_INITIALIZING_G1)
			{
				$option['lastId'] = $mailboxId;
				$option['steps']++;
				$option['attempts'] = 0;
				$option['passHadProgress'] = true;

				continue;
			}

			// The rows the step assigned are its progress: what is left over needs no counting
			if ($assignedRows > 0)
			{
				$option['attempts'] = 0;
				$option['passHadProgress'] = true;
			}
			else
			{
				$option['attempts']++;
			}

			if ((int)$option['attempts'] >= static::MAX_STALLED_ATTEMPTS)
			{
				// Leave the mailbox to the next pass or to the priority run
				$option['lastId'] = $mailboxId;
				$option['attempts'] = 0;
			}

			// The budget of this step is most likely spent
			return self::CONTINUE_EXECUTION;
		}

		return self::CONTINUE_EXECUTION;
	}

	protected function isEnabled(): bool
	{
		return Option::get(static::getModuleId(), static::ENABLED_OPTION, 'N') === 'Y';
	}

	protected function createService(): BackfillService
	{
		return new BackfillService();
	}

	protected function findNextPendingMailboxId(int $lastId): ?int
	{
		$mailboxId = (int)Application::getConnection()->queryScalar(sprintf(
			"SELECT MIN(ID) FROM b_mail_mailbox
				WHERE ID > %u AND ACTIVE_GENERATION_ID = 0 AND SERVER_TYPE IN ('imap', 'controller', 'domain', 'crdomain')",
			$lastId,
		));

		return $mailboxId > 0 ? $mailboxId : null;
	}

	protected function countPendingMailboxes(): int
	{
		return (int)Application::getConnection()->queryScalar(
			"SELECT COUNT(*) FROM b_mail_mailbox
				WHERE ACTIVE_GENERATION_ID = 0 AND SERVER_TYPE IN ('imap', 'controller', 'domain', 'crdomain')",
		);
	}
}

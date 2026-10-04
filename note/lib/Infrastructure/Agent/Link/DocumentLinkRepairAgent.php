<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Agent\Link;

use Bitrix\Note\Internal\Service\Link\DocumentLinkIndexService;

/**
 * [P2.T6] Retries the link rebuild of ONE document whose write failed, then removes itself.
 *
 * There is no queue of pending documents: registering the agent IS the record, one b_agent row per
 * document, the id and the attempt number carried by the row's NAME. The string a tick returns
 * replaces that NAME (CAgent::ExecuteAgents), which is how the attempt counter advances; returning
 * '' deletes the row. A portal with nothing broken carries no row at all, which is why the
 * migrations deliberately never register this agent — same reasoning as
 * {@see \Bitrix\Note\Infrastructure\Agent\Access\SubtreeAclReconcileAgent}.
 *
 * The retry budget is finite: a rebuild that keeps failing is a defect, and an agent looping on it
 * forever would only bury it. Exhaustion is written to the log and the row goes; the next save of
 * that document rebuilds the set anyway, since the whole set is rewritten every time.
 */
final class DocumentLinkRepairAgent
{
	private const AGENT_INTERVAL = 300;
	private const MAX_ATTEMPTS = 5;

	/**
	 * @param int $attempt 1 for the registration made at the moment of failure.
	 * @param DocumentLinkIndexService|null $indexService Injection seam for tests; CAgent evaluates
	 *                                                    the expression with the two ints only.
	 * @return string '' when the document is done with (deletes this agent), otherwise the retry
	 *                expression
	 */
	public static function run(
		int $documentId = 0,
		int $attempt = 1,
		?DocumentLinkIndexService $indexService = null,
	): string
	{
		if ($documentId <= 0)
		{
			return '';
		}

		try
		{
			($indexService ?? new DocumentLinkIndexService())->rebuildOrFail($documentId);

			return '';
		}
		catch (\Throwable $e)
		{
			self::logError('DocumentLinkRepairAgent: document ' . $documentId . ': ' . $e->getMessage());
		}

		if ($attempt >= self::MAX_ATTEMPTS)
		{
			self::logError('DocumentLinkRepairAgent: giving up on document ' . $documentId);

			return '';
		}

		return self::expression($documentId, $attempt + 1);
	}

	/**
	 * Idempotent registration, called by {@see DocumentLinkRepairScheduler}.
	 *
	 * AddAgent dedups by NAME, so repeated failures on the same document collapse into the one row
	 * still sitting at attempt 1. $existError = false keeps the duplicate case from pushing a
	 * CAdminException into $APPLICATION on every failed save.
	 */
	public static function schedule(int $documentId): void
	{
		if ($documentId <= 0)
		{
			return;
		}

		\CAgent::AddAgent(
			self::expression($documentId, 1),
			'note',
			'N',
			self::AGENT_INTERVAL,
			'',
			'Y',
			'',
			100,
			false,
			false,
		);
	}

	/**
	 * Every value is cast to int on the way in: the string is eval'd by the agent framework, so
	 * nothing but integers may reach it.
	 */
	public static function expression(int $documentId, int $attempt): string
	{
		return self::class . '::run(' . (int)$documentId . ', ' . (int)$attempt . ');';
	}

	private static function logError(string $message): void
	{
		\CEventLog::Add([
			'SEVERITY' => \CEventLog::SEVERITY_ERROR,
			'AUDIT_TYPE_ID' => 'NOTE_DOCUMENT_LINK_REPAIR_ERROR',
			'MODULE_ID' => 'note',
			'DESCRIPTION' => $message,
		]);
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\Pilot;

use Bitrix\Bizproc\Internal\Service\AiAgentGrid\Version\TemplateRevisionService;
use Bitrix\Main\Diag\LoggerFactory;
use Psr\Log\LoggerInterface;

/**
 * Keeps COMMON_SCHEME_REVISION - the fingerprint of the live scheme - in step with the scheme itself.
 *
 * The fingerprint is computed once per write of the scheme and never per process start: it covers the
 * whole activity tree, so recomputing it on a start would cost as much as the start itself. A template
 * that was not rewritten after the feature was switched on gets its fingerprint on first demand
 * ({@see self::ensureCommonRevision()}), once for the rest of its life.
 *
 * An empty scheme yields an empty revision, not a missing record: "the template has no common version"
 * is an answer of its own, while a missing record only means the question was never asked.
 */
final class CommonRevisionWriter
{
	private const LOGGER_ID = 'bizproc.pilot';

	public function __construct(
		private readonly PilotTemplateSettings $settings = new PilotTemplateSettings(),
		private readonly TemplateRevisionService $revisionService = new TemplateRevisionService(),
		private readonly RestrictedTemplateArea $restrictedArea = new RestrictedTemplateArea(),
	)
	{
	}

	/**
	 * Stores the fingerprint for callers that are not able to roll back their write.
	 *
	 * @param array $template Scheme as it was written, not as it came in the request.
	 * @return bool whether the fingerprint is now stored
	 */
	public function writeCommonRevision(int $templateId, array $template): bool
	{
		if ($templateId <= 0)
		{
			return false;
		}

		try
		{
			$this->writeCommonRevisionStrict($templateId, $template, false);

			return true;
		}
		catch (\Throwable $exception)
		{
			$this->logFailure('write', $templateId, $exception);

			return false;
		}
	}

	/**
	 * Stores the fingerprint as part of the transaction that writes the common scheme.
	 *
	 * @throws \Bitrix\Main\Repository\Exception\PersistenceException
	 */
	public function writeCommonRevisionForUpdate(int $templateId, array $template): bool
	{
		if ($templateId <= 0)
		{
			return false;
		}

		$this->writeCommonRevisionStrict($templateId, $template, true);

		return true;
	}

	/**
	 * Records that the template has never been published for everyone. The live scheme is not consulted and
	 * not changed: a template created by the editor carries the stub of the converter, which is a scheme
	 * like any other, so the answer cannot be derived from it and is written here as an answer of its own.
	 *
	 * The record is overwritten by the first write of the live scheme - that is the publication for
	 * everyone, and the common version really does appear there.
	 *
	 * @throws \Bitrix\Main\Repository\Exception\PersistenceException
	 */
	public function markNoCommonVersion(int $templateId): bool
	{
		if ($templateId <= 0)
		{
			return false;
		}

		$this->writeCommonRevisionStrict($templateId, [], false);

		return true;
	}

	/**
	 * The template has a common version - the single question the whole pilot branches on, asked so that
	 * the answer cannot depend on the order the callers ask it in: a template written before the feature
	 * existed has no revision stored, and reading the setting raw would call it a template that was never
	 * published for everyone.
	 */
	public function hasCommonVersion(int $templateId): bool
	{
		return $this->ensureCommonRevision($templateId) !== '';
	}

	/**
	 * Strict variant for a write transaction holding the template row lock.
	 *
	 * @throws \Bitrix\Main\Repository\Exception\PersistenceException
	 */
	public function hasCommonVersionForUpdate(int $templateId): bool
	{
		if ($templateId <= 0)
		{
			return false;
		}

		return $this->ensureCommonRevisionStrict($templateId, true) !== '';
	}

	/**
	 * The stored revision, computed from the live scheme on the first call for a template written
	 * before the feature existed. A stored value is returned as is - an empty string included, because
	 * it is the answer "no common version", not a reason to hash the activity tree again.
	 *
	 * Safe without a lock: the fingerprint is deterministic, so competing callers store the same value.
	 * On failure returns an empty revision instead of throwing - the process start it serves must go on.
	 */
	public function ensureCommonRevision(int $templateId): string
	{
		if ($templateId <= 0)
		{
			return '';
		}

		try
		{
			return $this->ensureCommonRevisionStrict($templateId, false);
		}
		catch (\Throwable $exception)
		{
			$this->logFailure('lazy fill', $templateId, $exception);

			return '';
		}
	}

	/**
	 * Stores the revision and drops the stored area of the pilot visibility rule when this write could
	 * have changed it: a template leaves that area when a common version appears and enters it back when
	 * the scheme is emptied under a pilot mark that stays.
	 *
	 * A scheme is written on every portal, so the area is touched only while the answer can change - an
	 * ordinary write over a portal with nothing hidden costs nothing at all. An empty revision is the one
	 * case that can put a template into the area, so it counts even when the area is empty.
	 *
	 * @throws \Bitrix\Main\Repository\Exception\PersistenceException
	 */
	private function ensureCommonRevisionStrict(int $templateId, bool $deferCacheInvalidation): string
	{
		$stored = $this->settings->getCommonSchemeRevision($templateId);
		if ($stored !== null)
		{
			return $stored;
		}

		$revision = $this->revisionService->getTemplateRevision($templateId) ?? '';
		$mayChangeTheArea = $this->storeRevision($templateId, $revision, !$deferCacheInvalidation);
		if ($mayChangeTheArea && !$deferCacheInvalidation)
		{
			$this->restrictedArea->invalidate();
		}

		return $revision;
	}

	private function writeCommonRevisionStrict(
		int $templateId,
		array $template,
		bool $deferCacheInvalidation,
	): void
	{
		$mayChangeTheArea = $this->storeRevision(
			$templateId,
			$template ? $this->revisionService->calculateRevision($template) : '',
			!$deferCacheInvalidation,
		);
		if ($mayChangeTheArea && !$deferCacheInvalidation)
		{
			$this->restrictedArea->invalidate();
		}
	}

	private function storeRevision(int $templateId, string $revision, bool $synchronizeArea = true): bool
	{
		$mayChangeTheArea = $revision === '' || $this->restrictedArea->hasAnyRestrictedTemplate();

		$this->settings->setCommonSchemeRevision($templateId, $revision);

		if ($mayChangeTheArea && $synchronizeArea)
		{
			$this->restrictedArea->synchronize();
		}

		return $mayChangeTheArea;
	}

	private function logFailure(string $stage, int $templateId, \Throwable $exception): void
	{
		$this->getLogger()?->warning(
			'Bizproc common scheme revision {stage} failed for template {templateId}: {message}',
			[
				'stage' => $stage,
				'templateId' => $templateId,
				'message' => $exception->getMessage(),
			],
		);
	}

	/**
	 * Null while the logger is switched off in the registry: there is nothing to write the diagnostics
	 * to, so the caller skips it instead of feeding a NullLogger.
	 */
	private function getLogger(): ?LoggerInterface
	{
		return (new LoggerFactory(alwaysReturnLogger: false))->createById(self::LOGGER_ID);
	}
}

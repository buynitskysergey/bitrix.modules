<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Provider;

use Bitrix\Bizproc\Internal\Config\PilotPublicationFeature;
use Bitrix\Bizproc\Internal\Exception\Pilot\AudienceUnavailableException;
use Bitrix\Bizproc\Internal\Service\Pilot\PilotAudienceService;
use Bitrix\Bizproc\Internal\Service\Pilot\RestrictedTemplateArea;
use Bitrix\Main\Diag\LoggerFactory;
use Psr\Log\LoggerInterface;

/**
 * The single answer to "which templates may this employee see among the ones a pilot acts on". One rule
 * gives both of the required states: while the pilot runs the template is seen by its participants and by
 * nobody else, and after the pilot is stopped there is no audience left, so it is seen by
 * nobody at all, former participants included. No branch of "the pilot is over" exists here.
 *
 * The area the rule narrows is its own, and only the shape of the answer is borrowed from the ACL of the
 * templates ({@see TemplateAccessProvider}): both filters are ORM conditions over the same ID, they use
 * different keys and are simply put side by side, so a selection keeps asking the ACL exactly what it asked
 * before.
 *
 * The employee is a named argument and never the current context: the same selections are made by the
 * background code, which has no employee at all, and an implicit filter would hide the templates from it.
 *
 * The area the rule acts on is held in the cache whole, so a selection pays no query for the rule - not
 * on a portal the feature is unused on, and not on one where a pilot runs. Whether the portal runs any
 * pilot is not that answer: it turns to no when a pilot is stopped, while the template its stop hides
 * stays hidden - see {@see RestrictedTemplateArea}.
 */
class PilotVisibilityProvider
{
	/**
	 * The key of the narrowing condition. It is not the one the ACL filter uses ('@ID' / '=ID'), so the two
	 * filters merge into one array without overwriting each other.
	 */
	private const HIDDEN_FILTER_KEY = '!@ID';

	private const LOGGER_ID = 'bizproc.pilot';

	public function __construct(
		private readonly PilotAudienceService $audienceService = new PilotAudienceService(),
		private readonly RestrictedTemplateArea $restrictedArea = new RestrictedTemplateArea(),
	)
	{
	}

	/**
	 * ORM filter over WorkflowTemplateTable.ID narrowing a selection to the templates the employee may see:
	 *  - nothing to hide => [] (no ID restriction, the selection is left as it was);
	 *  - otherwise => ['!@ID' => [t1, t2,...]].
	 *
	 * @param int|null $initiatorId the employee the selection is made for; null in a background context
	 */
	public function getVisibilityFilter(?int $initiatorId): array
	{
		$hidden = $this->hiddenTemplateIds($initiatorId, null);

		return $hidden ? [self::HIDDEN_FILTER_KEY => $hidden] : [];
	}

	/**
	 * The same rule over a list already in hand - for the selections that filter a result of a cache, where
	 * there is no query left to narrow.
	 *
	 * @param int[] $templateIds
	 * @return int[] the given templates the employee may see, in the given order and without duplicates
	 */
	public function filterVisibleIds(?int $initiatorId, array $templateIds): array
	{
		$templateIds = $this->normalizeTemplateIds($templateIds);
		if (!$templateIds)
		{
			return [];
		}

		$hidden = $this->hiddenTemplateIds($initiatorId, $templateIds);

		return $hidden ? array_values(array_diff($templateIds, $hidden)) : $templateIds;
	}

	/**
	 * The same rule about a single template - for a read by an identifier taken from a request, where a list
	 * is never built and the narrowing of the lists would be bypassed.
	 */
	public function isVisible(?int $initiatorId, int $templateId): bool
	{
		return $templateId > 0 && $this->hiddenTemplateIds($initiatorId, [$templateId]) === [];
	}

	/**
	 * The rule itself, kept in one place so that the filter of a query, the filter of a list and the check of
	 * a single template can never answer differently.
	 *
	 * @param int[]|null $scope the templates asked about; null asks about the whole portal
	 * @return int[] the templates of the scope this employee may not see
	 */
	private function hiddenTemplateIds(?int $initiatorId, ?array $scope): array
	{
		if (!$this->restrictedArea->hasAnyRestrictedTemplate())
		{
			return [];
		}

		$restricted = $this->restrictedArea->getRestrictedTemplateIds($scope);
		if (!$restricted)
		{
			return [];
		}

		return array_values(array_diff($restricted, $this->visibleAmong($initiatorId, $restricted)));
	}

	/**
	 * Hiding acts regardless of the rollout flag while showing does not: with the feature off the audience is
	 * never asked, so nobody is a participant and a template living in its pilot version only is available to
	 * no one. The required behaviour of a switched off feature follows from the same rule.
	 *
	 * @param int[] $restricted
	 * @return int[]
	 */
	private function visibleAmong(?int $initiatorId, array $restricted): array
	{
		if ($initiatorId === null || $initiatorId <= 0 || !PilotPublicationFeature::isEnabled())
		{
			return [];
		}

		try
		{
			return $this->audienceService->matchTemplates($initiatorId, $restricted);
		}
		catch (AudienceUnavailableException $exception)
		{
			// a platform that cannot answer narrows the restricted templates and nothing else. The
			// ACL reads an empty scope as "show nothing"; the same reading here would hide the whole portal.
			$this->logAudienceRefusal($exception);

			return [];
		}
	}

	/**
	 * @param int[] $templateIds
	 * @return int[]
	 */
	private function normalizeTemplateIds(array $templateIds): array
	{
		$normalized = [];
		foreach ($templateIds as $templateId)
		{
			$templateId = (int)$templateId;
			if ($templateId > 0)
			{
				$normalized[$templateId] = $templateId;
			}
		}

		return array_values($normalized);
	}

	private function logAudienceRefusal(\Throwable $exception): void
	{
		$this->getLogger()?->warning(
			'Bizproc pilot audience is unavailable, the templates published to a pilot are hidden: {message}',
			['message' => $exception->getMessage()],
		);
	}

	/**
	 * Null while the logger is switched off in the registry: there is nothing to write the diagnostics to, so
	 * the caller skips it instead of feeding a NullLogger.
	 */
	private function getLogger(): ?LoggerInterface
	{
		return (new LoggerFactory(alwaysReturnLogger: false))->createById(self::LOGGER_ID);
	}
}

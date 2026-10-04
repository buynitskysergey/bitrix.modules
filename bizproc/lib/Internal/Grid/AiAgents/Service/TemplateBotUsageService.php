<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Grid\AiAgents\Service;

use Bitrix\Bizproc\Integration\ImBot\BizprocBot;
use Bitrix\Bizproc\Internal\Factory\Workflow\TriggerStageWorkflowFactory;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTriggerTable;
use Bitrix\Main\Loader;

/**
 * Resolves the chat bots used by AI-agent templates: bots bound to a template via the
 * "new message" trigger and bots created inside the template body via the "chatbot setup" activity.
 *
 * Extracted from AiAgentsGridHelper so the grid helper keeps only orchestration and column preparation.
 */
class TemplateBotUsageService
{
	private const IM_BOT_NEW_MESSAGE_TRIGGER = 'ImBotNewMessageTrigger';
	private const IM_BOT_CREATE_BOT_ACTIVITY = 'ImBotCreateBotActivity';
	private const IM_BOT_PARAM_BOT_CODE = 'BotCode';
	private const IM_BOT_PARAM_BOT_ID = 'BotId';
	private const IM_BOT_CREATE_ACTIVITY_PARAM_BOT_CODE = 'botCode';

	/**
	 * Builds the [templateId => [botUserId, ...]] map for the given templates.
	 *
	 * @param array<int> $templateIds
	 * @param array $templates Template rows that include the TEMPLATE body.
	 * @return array<int, list<int>>
	 */
	public function getBotIdsByTemplate(array $templateIds, array $templates): array
	{
		$templateBotIdMap = [];
		// codes resolved from triggers/activities, deferred for a single bulk lookup: [['templateId' => int, 'code' => string], ...]
		$codeRefs = [];

		// bots bound to a template via the "new message" trigger
		$triggersToFetch = [self::IM_BOT_NEW_MESSAGE_TRIGGER];
		$templatesTriggers = $this->fetchTemplatesTriggers($templateIds, $triggersToFetch);

		foreach ($templatesTriggers as $trigger)
		{
			$templateId = (int)($trigger['TEMPLATE_ID'] ?? 0);
			$resolution = $this->resolveTriggerBot($trigger);

			if (isset($resolution['botId']))
			{
				$templateBotIdMap[$templateId][] = $resolution['botId'];
			}
			elseif (isset($resolution['code']))
			{
				$codeRefs[] = ['templateId' => $templateId, 'code' => $resolution['code']];
			}
		}

		// bots created right inside the template body via the "chatbot setup" activity
		foreach ($this->fetchTemplateCreateBotActivities($templates) as $templateId => $activities)
		{
			foreach ($activities as $activity)
			{
				$code = $this->resolveCreateBotActivityCode(
					$activity['properties'],
					$templateId,
					$activity['documentId'],
				);

				if ($code !== '')
				{
					$codeRefs[] = ['templateId' => $templateId, 'code' => $code];
				}
			}
		}

		if ($codeRefs)
		{
			$botIdsByCode = BizprocBot::getBotIdsByCodes(array_values(array_unique(array_column($codeRefs, 'code'))));

			foreach ($codeRefs as $ref)
			{
				$botId = $botIdsByCode[$ref['code']] ?? null;
				if ($botId)
				{
					$templateBotIdMap[$ref['templateId']][] = $botId;
				}
			}
		}

		foreach ($templateBotIdMap as $templateId => $botIds)
		{
			$templateBotIdMap[$templateId] = array_values(array_unique($botIds));
		}

		return $templateBotIdMap;
	}

	/**
	 * Extracts "chat bot setup" activities (ImBotCreateBotActivity) from the already-loaded template bodies.
	 *
	 * @param array $templates Template rows that include the TEMPLATE body.
	 * @return array<int, list<array{properties: array, documentId: array}>>
	 */
	private function fetchTemplateCreateBotActivities(array $templates): array
	{
		if (!Loader::includeModule('imbot'))
		{
			return [];
		}

		$result = [];
		foreach ($templates as $template)
		{
			$body = $template['TEMPLATE'] ?? null;
			if (!is_array($body) || empty($body))
			{
				continue;
			}

			$activities = $this->findActivitiesByType($body, self::IM_BOT_CREATE_BOT_ACTIVITY);
			if (empty($activities))
			{
				continue;
			}

			$documentId = $this->getTemplateDocumentType($template);

			foreach ($activities as $activity)
			{
				$result[(int)$template['ID']][] = [
					'properties' => $activity['Properties'] ?? [],
					'documentId' => $documentId,
				];
			}
		}

		return $result;
	}

	/**
	 * Recursively collects all activities of the given type from a workflow template tree.
	 *
	 * @param array $template Workflow template tree (list of activity nodes).
	 * @param string $type Activity type to look for.
	 * @return list<array> Matched activity nodes.
	 */
	private function findActivitiesByType(array $template, string $type): array
	{
		$result = [];

		foreach ($template as $activity)
		{
			if (!is_array($activity))
			{
				continue;
			}

			if (($activity['Type'] ?? null) === $type)
			{
				$result[] = $activity;
			}

			if (!empty($activity['Children']) && is_array($activity['Children']))
			{
				array_push($result, ...$this->findActivitiesByType($activity['Children'], $type));
			}
		}

		return $result;
	}

	/**
	 * Resolves the bot code declared by an ImBotCreateBotActivity node (expressions included).
	 * The code is resolved to an id later, in a single bulk lookup.
	 *
	 * @param array $properties Activity properties from the template body.
	 * @param int $templateId
	 * @param array $documentId
	 * @return string Resolved bot code, or an empty string if none.
	 */
	private function resolveCreateBotActivityCode(array $properties, int $templateId, array $documentId): string
	{
		if (!Loader::includeModule('imbot') || empty($properties))
		{
			return '';
		}

		// literal codes are the common case: resolve them directly, without building a stub workflow
		$rawBotCode = $properties[self::IM_BOT_CREATE_ACTIVITY_PARAM_BOT_CODE] ?? null;
		if (is_string($rawBotCode) && $rawBotCode !== '' && !$this->containsExpression($rawBotCode))
		{
			return trim($rawBotCode);
		}

		$activity = $this->createResolvedActivity(self::IM_BOT_CREATE_BOT_ACTIVITY, $properties, $templateId, $documentId);
		if (!$activity)
		{
			return '';
		}

		$value = $activity->{self::IM_BOT_CREATE_ACTIVITY_PARAM_BOT_CODE};
		if (is_array($value))
		{
			$value = reset($value);
		}

		return is_scalar($value) ? trim((string)$value) : '';
	}

	/**
	 * Tells whether the value is or contains a workflow expression. isExpression() matches only
	 * whole-string expressions, while real bot codes are often literal+expression mixes
	 * (e.g. 'BOT_{=Workflow:TemplateId}'), so inline occurrences must be detected separately.
	 *
	 * @param string $value
	 * @return bool
	 */
	private function containsExpression(string $value): bool
	{
		return \CBPActivity::isExpression($value)
			|| preg_match(\CBPActivity::ValueInlinePattern, $value)
			|| preg_match(\CBPActivity::CalcInlinePattern, $value);
	}

	/**
	 * Builds an activity instance bound to a stub workflow so its properties
	 * (e.g. {=Workflow:TemplateId} expressions) can be resolved.
	 *
	 * @param string $type Activity/trigger type.
	 * @param array $properties Activity properties.
	 * @param int $templateId
	 * @param array $documentId
	 * @return \CBPActivity|null
	 */
	private function createResolvedActivity(string $type, array $properties, int $templateId, array $documentId): ?\CBPActivity
	{
		if (!\CBPRuntime::getRuntime()->includeActivityFile($type))
		{
			return null;
		}

		$activity = \CBPActivity::createInstance($type, '');
		if (!$activity)
		{
			return null;
		}

		$activity->initializeFromArray($properties);

		$stubWorkflow = (new TriggerStageWorkflowFactory())->create($templateId, $documentId);
		$activity->setWorkflow($stubWorkflow);

		return $activity;
	}

	/**
	 * Resolves a bot reference from a trigger: either a direct bot id, or a bot code
	 * (deferred to a single bulk lookup). Expressions in properties are resolved here.
	 *
	 * @param array $trigger
	 * @return array{botId?: int, code?: string} Empty array when nothing is resolved.
	 */
	private function resolveTriggerBot(array $trigger): array
	{
		if (!Loader::includeModule('imbot'))
		{
			return [];
		}

		$triggerType = $trigger['TRIGGER_TYPE'] ?? null;
		$templateId = $trigger['TEMPLATE_ID'] ?? null;
		$applyRules = $trigger['APPLY_RULES'] ?? [];
		$properties = $applyRules['Properties'] ?? [];

		if (is_null($triggerType) || is_null($templateId) || empty($properties))
		{
			return [];
		}

		$documentId = [$trigger['MODULE_ID'], $trigger['ENTITY'], $trigger['DOCUMENT_TYPE']];

		$activity = $this->createResolvedActivity($triggerType, $properties, (int)$templateId, $documentId);
		if (!$activity)
		{
			return [];
		}

		$botId = filter_var($activity->{self::IM_BOT_PARAM_BOT_ID}, FILTER_VALIDATE_INT, [
			'options' => [
				'min_range' => 1,
			],
		]);

		if ($botId)
		{
			return ['botId' => (int)$botId];
		}

		$botCode = (string)$activity->{self::IM_BOT_PARAM_BOT_CODE};
		if ($botCode !== '')
		{
			return ['code' => $botCode];
		}

		return [];
	}

	/**
	 * Fetches workflow template triggers for given template IDs and trigger type.
	 *
	 * @param list<int> $templateIds
	 * @param list<string> $triggerTypes (e.g., ['ImBotNewMessageTrigger', ...])
	 * @return array<int, array<string, mixed>>
	 */
	private function fetchTemplatesTriggers(array $templateIds, array $triggerTypes = []): array
	{
		if (empty($templateIds))
		{
			return [];
		}

		$query = WorkflowTemplateTriggerTable::query()
			->setSelect([
				'TEMPLATE_ID',
				'TRIGGER_TYPE',
				'APPLY_RULES',
				'MODULE_ID',
				'ENTITY',
				'DOCUMENT_TYPE',
			])
			->whereIn('TEMPLATE_ID', $templateIds)
		;

		if (!empty($triggerTypes))
		{
			$query->whereIn('TRIGGER_TYPE', $triggerTypes);
		}

		return $query->fetchAll();
	}

	/**
	 * Returns the workflow document type triple [MODULE_ID, ENTITY, DOCUMENT_TYPE] for a template row.
	 *
	 * DOCUMENT_TYPE arrives in two shapes depending on the source: a scalar code from the grid ORM
	 * query, or an already-expanded triple array from the copy-and-start raw fields. Both are
	 * normalized to a flat triple; otherwise a nested array is passed as the third element and
	 * CBPHelper::parseDocumentId() rejects it as an empty documentId.
	 *
	 * @param array $template
	 * @return array{0: mixed, 1: mixed, 2: mixed}
	 */
	private function getTemplateDocumentType(array $template): array
	{
		$documentType = $template['DOCUMENT_TYPE'] ?? null;

		if (is_array($documentType))
		{
			return array_values($documentType);
		}

		return [$template['MODULE_ID'] ?? null, $template['ENTITY'] ?? null, $documentType];
	}
}

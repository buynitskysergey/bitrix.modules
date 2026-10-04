<?php

namespace Bitrix\Bizproc\Workflow\Template\Converter;

use Bitrix\Bizproc\Activity\ActivityDescription;
use Bitrix\Bizproc\Activity\Dto\NodePorts;
use Bitrix\Bizproc\Activity\Enum\ActivityType;
use Bitrix\Bizproc\Internal\Service\Container;
use Bitrix\Bizproc\Runtime\ActivitySearcher\Searcher;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Bizproc\Activity\ContentBlockResolver;
use Bitrix\Bizproc\Activity\ReturnPropertiesResolver;

final class TemplateToNodes
{
	/**
	 * @param bool $complexNodeConnectionsAvailable Verdict of the rollout option of the feature
	 *     (`bizprocdesigner`/`complex_node_connections_available`), passed in by the editor: the option belongs
	 *     to that module. It gates the relations block of a node and the surface a translated node is served
	 *     by ({@see \Bitrix\Bizproc\Internal\Service\Activity\UnifiedPanelDescriptorProvider::isSurfaceAvailableForNode()}).
	 */
	public function __construct(
		private readonly array $template,
		private readonly bool $complexNodeConnectionsAvailable = false,
	)
	{}

	public function convert(): array
	{
		$template = $this->convertTemplate($this->template);
		$root = $template[0];
		$blocks = $this->createBlocks($root[NodesToTemplate::ELEMENT_CHILDREN], null);
		$connections = $this->createConnections(
			$root[NodesToTemplate::ELEMENT_PROPERTIES][NodesToTemplate::PROPERTY_LINKS]
		);

		return [$blocks, $connections];
	}

	private function convertTemplate(array $template): array
	{
		$type = $template[0]['Type'];

		if ($type === NodesToTemplate::ROOT_NODE_TYPE)
		{
			return $template;
		}

		if ($type === 'SequentialWorkflowActivity')
		{
			return (new SequentialToNodeWorkflow($template))
				->setStartTrigger('ManualStartTrigger')
				->convert()
			;
		}

		if ($type === 'StateMachineWorkflowActivity')
		{
			return (new StateMachineToNodeWorkflow($template))->convert();
		}

		return [];
	}


	/**
	 * @param array $activities
	 * @return array|array[]
	 */
	private function createBlocks(array $activities, ?array $documentType): array
	{
		/** @var Searcher $searcher */
		$searcher = ServiceLocator::getInstance()->get('bizproc.runtime.activitysearcher.searcher');

		// No excluded filter here: these maps describe blocks already saved in the
		// template, not the palette catalog. Excluded (rolled-out) activities must
		// still resolve icon/color/ports for their existing blocks.
		$defaultActivities =
			$searcher->searchByType(
				[ActivityType::NODE->value, ActivityType::TRIGGER->value],
				$documentType
			)
				->sort()
		;

		$nodeTypesMap = [];
		$servedByUnifiedPanelMap = [];
		$iconMap = [];
		$auxCapabilityMap = [];
		$relationsAvailableMap = [];
		/* @var ActivityDescription $activity*/
		foreach ($defaultActivities as $activity)
		{
			$class = $activity->getClass();
			if (!$class)
			{
				continue;
			}

			if ($activity->getPresets())
			{
				foreach ($activity->getPresets() as $preset)
				{
					$presetActivity = $activity->applyPreset($preset);
					$id = $class . '_' .  $preset['ID'];
					$iconMap[$id] = [
						'CODE' => $presetActivity->getIcon(),
						'COLOR' => $presetActivity->getColorIndex(),
						'CONTENT_BLOCK_COLOR' => $presetActivity->getContentBlockColor(),
						'TITLE' => $presetActivity->getName(),
					];
					$auxCapabilityMap[$id] = $presetActivity->getNodeSettings()?->ports?->aux !== null;
					$relationsAvailableMap[$id] = $this->resolveRelationsAvailable($presetActivity);
				}
			}
			else
			{
				$auxCapabilityMap[$class] = $activity->getNodeSettings()?->ports?->aux !== null;
				$relationsAvailableMap[$class] = $this->resolveRelationsAvailable($activity);
			}

			// Base entry for every activity, presets or not: a saved node whose PresetId the descriptor
			// no longer declares falls back to it instead of losing its icon, color and default title.
			$iconMap[$class] ??= [
				'CODE' => $activity->getIcon(),
				'COLOR' => $activity->getColorIndex(),
				'CONTENT_BLOCK_COLOR' => $activity->getContentBlockColor(),
				'TITLE' => $activity->getName(),
			];

			$nodeTypesMap[$class] = $activity->getNodeType();

			// Keyed by class alone, unlike the maps above: a preset never changes the panel serving a node.
			$servedByUnifiedPanelMap[$class] = $this->resolveServedByUnifiedPanel($class, $activity);
		}

		// An activity declaring no type at all (the manual start and create-document triggers) is out of the
		// type-based lookup above, yet searchByCode() completes it with the panel descriptor all the same.
		// Resolving per code keeps both lookup paths at one answer.
		$resolveServedByUnifiedPanel = function (?string $activityCode) use (
			$searcher,
			&$servedByUnifiedPanelMap,
		): bool {
			if ($activityCode === null || $activityCode === '')
			{
				return false;
			}

			if (!array_key_exists($activityCode, $servedByUnifiedPanelMap))
			{
				$description = $searcher->searchByCode($activityCode);
				$servedByUnifiedPanelMap[$activityCode] = $description === null
					? false
					: $this->resolveServedByUnifiedPanel($activityCode, $description)
				;
			}

			return $servedByUnifiedPanelMap[$activityCode];
		};

		return array_map(
			static function ($child) use (
				$iconMap,
				$auxCapabilityMap,
				$relationsAvailableMap,
				$nodeTypesMap,
				$resolveServedByUnifiedPanel,
			) {
				$node = $child['Node'];
				unset($child['Node']);

				$nodeType = $nodeTypesMap[$child['Type']] ?? $node['type'] ?? 'simple';
				if (\CBPRuntime::getRuntime()->isTriggerActivity((string)$child['Type']))
				{
					$nodeType = 'trigger';
				}

				$rawActivityType = $child['Type'] ?? null;
				$activityType = $rawActivityType;
				if (isset($child['PresetId']))
				{
					$activityType .= '_' . $child['PresetId'];
				}

				// Only the visuals fall back to the base entry: the capability maps below resolve an unknown
				// preset to their neutral default (no aux ports, no explicit relations descriptor), which is
				// what a node with such a preset resolved before the base entry existed.
				$visuals = $iconMap[$activityType] ?? $iconMap[$rawActivityType] ?? [];
				$icon = $visuals['CODE'] ?? null;
				$color = $visuals['COLOR'] ?? null;

				$nodePorts = NodePorts::fromArray($node['ports'] ?? []);

				$shouldShowAuxPorts = $activityType !== null && ($auxCapabilityMap[$activityType] ?? false);

				// Per-node relations-block availability so the canvas gate matches the
				// node-settings store WITHOUT opening settings. Non-null only for nodes with
				// an explicit availableBlocks descriptor; null keeps the legacy fallback path.
				$relationsAvailable = $activityType !== null ? ($relationsAvailableMap[$activityType] ?? null) : null;

				return [
					'id' => $node['id'],
					'type' => $nodeType,
					'position' => $node['position'],
					'dimensions' => $node['dimensions'],
					'ports' => $nodePorts->toArray(), // normalize ports structure
					'activity' => $child,
					'node' => [
						...($node['node'] ?? []),
						'type' => $nodeType,
						// The editor picks the settings surface before it loads anything from the server, so
						// the marker travels with the block; an activity absent from the portal keeps false.
						'servedByUnifiedPanel' => $resolveServedByUnifiedPanel($rawActivityType),
						'colorIndex' => $color,
						'icon' => $icon,
						'shouldShowAuxPorts' => $shouldShowAuxPorts,
						'relationsAvailable' => $relationsAvailable,
						'contentBlockColor' => $visuals['CONTENT_BLOCK_COLOR'] ?? null,
						// The editor resolves the default node title from the palette, where an activity
						// kept out of it has no card at all; this is the source for such nodes.
						'defaultTitle' => $visuals['TITLE'] ?? null,
					],
				];
			},
			$this->transformBlockActivities($activities)
		);
	}

	/**
	 * The panel a saved block is served by: the descriptor of its activity intersected with the rollout gate of
	 * the feature, so switching the flag off returns a translated node to its legacy settings form while a
	 * complex node - a node with a descriptor of its own - keeps the panel it always had.
	 */
	private function resolveServedByUnifiedPanel(string $activityCode, ActivityDescription $description): bool
	{
		return Container::instance()->getUnifiedPanelDescriptorProvider()->isSurfaceAvailableForNode(
			$activityCode,
			$description,
			$this->complexNodeConnectionsAvailable,
		);
	}

	/**
	 * Relations-block availability for a node: its explicit availableBlocks descriptor intersected
	 * with the same runtime gate CapabilityCatalogService applies for loadSettings, so the canvas
	 * and the settings panel always agree. Returns null when the node has no explicit descriptor
	 * (legacy nodes) so the frontend keeps its prior feature-flag fallback and behaviour does not change.
	 */
	private function resolveRelationsAvailable(ActivityDescription $description): ?bool
	{
		return Container::instance()
			->getCapabilityCatalogService()
			->getRelationsAvailabilityForNode($description, $this->complexNodeConnectionsAvailable)
		;
	}

	/**
	 * @param mixed $links
	 * @return array|array[]
	 */
	private function createConnections(mixed $links): array
	{
		return array_map(
			static function (array $link) {
				[$sourceBlockId, $targetBlockId, $createdAt] = array_pad($link, 3, null);

				$sourcePortId = 'o0';
				$targetPortId = 'i0';

				if (str_contains($sourceBlockId, ':'))
				{
					[$sourceBlockId, $sourcePortId] = explode(':', $sourceBlockId);
				}

				if (str_contains($targetBlockId, ':'))
				{
					[$targetBlockId, $targetPortId] = explode(':', $targetBlockId);
				}

				$type = null;
				if (strtolower($sourcePortId[0]) === 'a' && strtolower($targetPortId[0]) === 't')
				{
					$type = 'aux';
				}

				return [
					'id' => "{$sourceBlockId}_{$targetBlockId}_{$sourcePortId}_{$targetPortId}",
					'sourceBlockId' => $sourceBlockId,
					'sourcePortId' => $sourcePortId,
					'targetBlockId' => $targetBlockId,
					'targetPortId' => $targetPortId,
					'type' => $type,
					'createdAt' => $createdAt,
				];
			},
			$links,
		);
	}

	private function transformBlockActivities(array $activities): array
	{
		$contentBlocks = ContentBlockResolver::resolveForTemplate($activities);

		foreach ($activities as &$activity)
		{
			$activity['ReturnProperties'] = ReturnPropertiesResolver::resolve($activity);

			$contentBlock = $contentBlocks[(string)($activity['Name'] ?? '')] ?? null;
			if ($contentBlock !== null)
			{
				$activity['ContentBlock'] = $contentBlock->toArray();
			}
			else
			{
				unset($activity['ContentBlock']);
			}
		}

		return $activities;
	}
}

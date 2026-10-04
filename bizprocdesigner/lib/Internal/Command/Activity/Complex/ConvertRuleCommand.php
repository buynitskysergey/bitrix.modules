<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Command\Activity\Complex;

use Bitrix\Bizproc\Activity\ReturnPropertiesResolver;
use Bitrix\Bizproc\Automation\Helper;
use Bitrix\Bizproc\Internal\Entity\Port\PortType;
use Bitrix\Bizproc\Internal\Service\Activity\ComplexActivityService;
use Bitrix\Bizproc\Internal\Service\Activity\UnifiedPanelDescriptorProvider;
use Bitrix\Bizproc\Internal\Service\Container as BizprocContainer;
use Bitrix\Bizproc\Public\Activity\Interface\NodeFilterMetadataProvider;
use Bitrix\Bizproc\Public\Activity\Structure\FlowDirectedActivity;
use Bitrix\Bizproc\Public\Service\Activity\ActivityNameGeneratorService;
use Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\PortRuleDto;
use Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\Rule\ActionExpressionDto;
use Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\Rule\BaseSettingsExpressionDto;
use Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\Rule\ConditionExpressionDto;
use Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\Rule\ConstructionDto;
use Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\Rule\OutputExpressionDto;
use Bitrix\BizprocDesigner\Infrastructure\Enum\ConstructionType;
use Bitrix\BizprocDesigner\Internal\Command\AbstractCommand;
use Bitrix\BizprocDesigner\Internal\Entity\ActivityData;
use Bitrix\BizprocDesigner\Internal\Service\Activity\ConditionConstructionConverter;
use Bitrix\BizprocDesigner\Internal\Trait\NodeActionResolver;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\Error;
use Bitrix\Main\DI;
use CBPRuntime;

class ConvertRuleCommand extends AbstractCommand
{
	use NodeActionResolver;

	private const FILTER_RETURN_PROPERTIES_MAP = 'FilterReturnPropertiesMap';
	private const TARGET_FILTER_ID_PROPERTY = 'TargetFilterId';
	private const FILTER_RESULT_ALL_SUFFIX = '_all';

	/**
	 * Host-metadata keys unconditionally injected by SaveCommandHandler into every activity's
	 * Properties. For BASE_SETTINGS these arrive as empty strings (base-settings has no own
	 * title/editorComment) and must not be allowed to overwrite the real values on the host node.
	 */
	private const BASE_SETTINGS_EXCLUDED_PROPERTIES = ['Title', 'EditorComment'];

	private ActivityNameGeneratorService $nameGeneratorService;
	private ConditionConstructionConverter $conditionConverter;

	private array $childrenActivities = [];
	private array $inputNames = [];
	private array $outputNames = [];
	private array $links = [];
	private array $filterSettings = [];
	private array $filterReturnProperties = [];

	/**
	 * The filter projections of this conversion as a wire list, each entry carrying its `Id`. Only the
	 * filters - the package the node publishes is composed in {@see self::composeReturnProperties()}.
	 */
	private array $filterReturnPropertyList = [];
	private array $filterReturnPropertyIndexes = [];

	/**
	 * Accumulated Properties from BASE_SETTINGS constructions.
	 * Merged into the host activity's Properties during execute().
	 * Variant A: base-settings are not a child activity and do not appear in Links.
	 */
	private array $baseSettingsProperties = [];

	/**
	 * Condition entries of a node that keeps its condition in a property of its own instead of a child
	 * branching activity. Written into the host Properties during execute().
	 *
	 * A single condition group fills it, from wherever among the containers and cards of the node that
	 * group arrived: the property is one flat list and holds no alternatives, so a second group - a second
	 * `condition:if`, in the same card or in another one - is rejected by
	 * {@see self::validateSingleConditionGroup()} instead of being accumulated into the first.
	 */
	private array $nodeConditionEntries = [];

	private ?bool $conditionBelongsToNodeProperty = null;

	/**
	 * @param ActivityData $activity
	 * @param array<string, PortRuleDto> $portRuleDtoDictionary
	 * @param array $documentType
	 * @param array<string, PortRuleDto> $relationPortRuleDtoDictionary
	 */
	public function __construct(
		public ActivityData $activity,
		public array $portRuleDtoDictionary,
		public array $documentType,
		public array $relationPortRuleDtoDictionary = [],
	) {
		$this->nameGeneratorService = DI\ServiceLocator::getInstance()
			->get('bizproc.service.activity.nameGenerator')
		;
		$this->conditionConverter = new ConditionConstructionConverter();
	}

	protected function beforeRun(): void
	{
		Loader::requireModule('bizproc');
		parent::beforeRun();
	}

	protected function validate(): bool
	{
		// Node-wide uniqueness guard for BASE_SETTINGS: at most one BASE_SETTINGS construction
		// is allowed across ALL ports of the node. Per-rule uniqueness is already enforced by
		// ValidateSingleRuleCommand, but that guard only sees one rule at a time. Two constructions
		// in different rules / ports would otherwise pass per-rule validation individually.
		// This aggregation point (ConvertRuleCommand) sees every port and is the correct place
		// for the node-level invariant. Relation ports count too: their constructions are host-merged
		// by the same execute() pass, so leaving them out would let a second block in through them.
		$nodeBaseSettingsCount = 0;
		foreach ([$this->portRuleDtoDictionary, $this->relationPortRuleDtoDictionary] as $portRuleDictionary)
		{
			foreach ($portRuleDictionary as $portRule)
			{
				if (!$portRule instanceof PortRuleDto)
				{
					continue;
				}

				foreach ($portRule->rules as $rule)
				{
					foreach ($rule->constructions as $construction)
					{
						if ($construction->constructionType === ConstructionType::BASE_SETTINGS)
						{
							$nodeBaseSettingsCount++;
						}
					}
				}
			}
		}

		if ($nodeBaseSettingsCount > 1)
		{
			$this->errors[] = new Error(
				Loc::getMessage(
					'BIZPROCDESIGNER_COMMAND_CONVERT_RULE_BASE_SETTINGS_NODE_DUPLICATE',
				) ?? 'Only one base-settings construction is allowed per node across all ports.',
				'ERR-BASE-SETTINGS-NODE-DUPLICATE',
			);

			return false;
		}

		foreach ($this->portRuleDtoDictionary as $containerKey => $portRule)
		{
			// The portless container is bound by the allowlist on top of the usual catalog-based checks.
			$allowedConstructionTypes = $this->isPortlessRuleContainer($containerKey, $portRule)
				? ValidateSingleRuleCommand::PORTLESS_RULE_CONSTRUCTION_TYPES
				: null
			;

			$result = (new ValidateSingleRuleCommand(
				portRuleDto: $portRule,
				activityType: $this->activity->type,
				documentType: $this->documentType,
				allowedConstructionTypes: $allowedConstructionTypes,
				activityProperties: $this->activity->properties,
			))->run();
			if (!$result instanceof ValidateSingleRuleCommandResult)
			{
				return false;
			}

			if (!$result->isFilled)
			{
				$this->errors[] = new Error('Rules are not filled for port: ' . $containerKey);

				return false;
			}
		}

		foreach ($this->relationPortRuleDtoDictionary as $portId => $portRule)
		{
			// No activityType: the relation action lives outside the node's actionDictionary, so the
			// capability catalog would reject valid relation cards with ERR-ACTION-NOT-IN-CATALOG.
			// The construction-type allowlist stands in for the catalog block check, and the action
			// itself is matched against the node's declared relation action in the controller.
			$result = (new ValidateSingleRuleCommand(
				portRuleDto: $portRule,
				allowedConstructionTypes: ValidateSingleRuleCommand::RELATION_PORT_CONSTRUCTION_TYPES,
			))->run();
			if (!$result instanceof ValidateSingleRuleCommandResult)
			{
				return false;
			}

			if (!$result->isFilled)
			{
				$this->errors[] = new Error('Rules are not filled for relation port: ' . $portId);
				return false;
			}
		}

		return $this->validateSingleConditionGroup();
	}

	/**
	 * A node that keeps its condition in a property of its own carries exactly one condition group there.
	 * A complex node gives every `condition:if` a branching activity of its own, and their chain is the
	 * conjunction of the groups; the property is one flat list ({@see \CBPMixedCondition}), where a run
	 * joined by `1` is a disjunct, so a conjunction of two groups has no faithful form in it. Rejected and
	 * not merged: merging holds only while no group carries a `condition:or`.
	 */
	private function validateSingleConditionGroup(): bool
	{
		$conditionGroupCount = 0;
		foreach ([$this->portRuleDtoDictionary, $this->relationPortRuleDtoDictionary] as $portRuleDictionary)
		{
			foreach ($portRuleDictionary as $portRule)
			{
				if (!$portRule instanceof PortRuleDto)
				{
					continue;
				}

				foreach ($portRule->rules as $rule)
				{
					foreach ($rule->constructions as $construction)
					{
						if ($construction->constructionType === ConstructionType::IF_CONDITION)
						{
							$conditionGroupCount++;
						}
					}
				}
			}
		}

		// The descriptor is asked only when there is a second group to judge: a node without one is not
		// affected by the address of its condition at all.
		if ($conditionGroupCount < 2 || !$this->conditionBelongsToNodeProperty())
		{
			return true;
		}

		$this->errors[] = new Error(
			Loc::getMessage('BIZPROCDESIGNER_COMMAND_CONVERT_RULE_NODE_CONDITION_SECOND_GROUP')
				?? 'Only one condition group is allowed for this node.',
			'ERR-NODE-CONDITION-SECOND-GROUP',
		);

		return false;
	}

	/**
	 * @return Result|ConvertRuleCommandResult
	 */
	protected function execute(): Result|ConvertRuleCommandResult
	{
		foreach ($this->portRuleDtoDictionary as $containerKey => $portRule)
		{
			if (!$portRule instanceof PortRuleDto)
			{
				continue;
			}

			$this->processRuleCollection(
				$portRule,
				isPortless: $this->isPortlessRuleContainer($containerKey, $portRule),
			);
		}

		foreach ($this->relationPortRuleDtoDictionary as $portId => $portRule)
		{
			if (!$portRule instanceof PortRuleDto)
			{
				continue;
			}

			$this->processRuleCollection($portRule, isPortless: false);
		}

		$activity = $this->activity->toArray();

		$activity['Children'] = $this->childrenActivities;
		$activity['Properties'] = [
			...$activity['Properties'],
			// Merge base-settings properties before standard routing keys (Variant A).
			// BASE_SETTINGS constructions are host-merged: no child activity, no Links entry.
			...$this->baseSettingsProperties,
			...[
				'InputNames' => $this->inputNames,
				'OutputNames' => $this->outputNames,
				'Links' => $this->links,
			],
		];

		if (!empty($this->filterSettings))
		{
			$activity['Properties']['FilterSettings'] = $this->filterSettings;
		}
		else
		{
			unset($activity['Properties']['FilterSettings']);
		}

		if (!empty($this->filterReturnProperties))
		{
			$activity['Properties'][self::FILTER_RETURN_PROPERTIES_MAP] = $this->filterReturnProperties;
		}
		else
		{
			unset($activity['Properties'][self::FILTER_RETURN_PROPERTIES_MAP]);
		}

		// After the properties are assembled: the package of the node is read from them.
		$activity['ReturnProperties'] = $this->composeReturnProperties($activity);

		// An empty condition removes the key instead of storing an empty list, so a node whose condition was
		// cleared stays indistinguishable from a node that never had one.
		if ($this->nodeConditionEntries !== [])
		{
			$activity['Properties'][UnifiedPanelDescriptorProvider::CONDITION_PROPERTY] =
				$this->unConvertNodeCondition()
			;
		}
		else
		{
			unset($activity['Properties'][UnifiedPanelDescriptorProvider::CONDITION_PROPERTY]);
		}

		return new ConvertRuleCommandResult(ActivityData::createFromArray($activity));
	}

	/**
	 * Assemble one rules container: the child activities its constructions become, the links chaining them,
	 * the properties of the host node the childless constructions serialize into, and the start of every
	 * rule chain of the container.
	 *
	 * One assembly serves both kinds of container. Being portless changes only two things: what the
	 * constructions of a node the unified panel serves are allowed to become - its base settings are
	 * host-merged and never a proxy child, its condition is always the property of the node - and how the
	 * starts of its chains are addressed ({@see self::registerInputNames()}).
	 *
	 * @param bool $isPortless Whether this is the reserved container of a node without input ports.
	 */
	private function processRuleCollection(PortRuleDto $portRuleCollection, bool $isPortless): void
	{
		/** @var list<ActivityData> $inputActivities */
		$inputActivities = [];

		foreach ($portRuleCollection->rules as $rule)
		{
			/** @var ?ActivityData $inputActivity */
			$inputActivity = null;
			/** @var ?ActivityData $prevActivity */
			$prevActivity = null;
			$previousFilterId = null;

			$constructions = $rule->constructions;
			foreach ($constructions as $position => $construction)
			{
				/** @var ?ActivityData $currentActivity */
				$currentActivity = null;

				$expression = $construction->expression;
				if (
					$construction->constructionType === ConstructionType::FILTER
					&& $expression instanceof ActionExpressionDto
				)
				{
					$previousFilterId =
						$this->processFilterConstruction($construction, $expression, $previousFilterId)
						?? $previousFilterId
					;

					continue;
				}

				if (
					$construction->constructionType === ConstructionType::BASE_SETTINGS
					&& $expression instanceof BaseSettingsExpressionDto
				)
				{
					$backingActivityType = (string)($expression->activityData['Type'] ?? '');
					$isNodeActionBaseSettings =
						$this->isNodeActionBaseSettings($expression, $backingActivityType)
					;

					// The base settings of a node the unified panel serves arrive bound to no node-action,
					// and those are the only ones the reserved container carries: a construction bound to
					// one describes the settings of another activity, and merging its Properties into the
					// host would overwrite properties of the node itself (Document of a field-changed
					// trigger, say). It is dropped here, exactly as before the container started building
					// children.
					if ($isPortless && $isNodeActionBaseSettings)
					{
						continue;
					}

					// Node-action proxy: base-settings bound to a node-action is converted into a
					// CHILD activity, exactly like an ActionExpressionDto. It sets $currentActivity
					// and falls through to the shared child-linking block below.
					if ($isNodeActionBaseSettings)
					{
						$activityData = $this->prepareActionActivityData($expression->activityData ?? [], null);
						$currentActivity = ActivityData::createFromArray($activityData);
					}
					else
					{
						// Host-merged construction (Variant A): extract Properties from activityData and
						// merge into host activity Properties. Does NOT create a child activity, does NOT
						// add to Links, does NOT set inputActivity.
						$this->mergeBaseSettingsProperties($expression);

						continue;
					}
				}

				if ($expression instanceof ActionExpressionDto)
				{
					$activityData = $this->prepareActionActivityData(
						$expression->activityData ?? [],
						$this->resolveTargetFilterIdForAction($expression),
					);

					if (!empty($expression->auxPortId))
					{
						$activityData['Properties'] ??= [];
						$activityData['Properties']['auxPort'] = $expression->auxPortId;
					}

					$currentActivity = ActivityData::createFromArray($activityData);
				}

				if (
					$construction->constructionType === ConstructionType::IF_CONDITION
					&& $expression instanceof ConditionExpressionDto
				)
				{
					// Same entries either way, only the address they are put at differs. The address stays
					// the descriptor's to decide for the reserved container too: its key is matched against
					// no port, so it may arrive for a complex node as well, whose property would gate the
					// node together with its children (isNodeConditionMet()).
					if ($this->conditionBelongsToNodeProperty())
					{
						$this->collectNodeCondition((int)$position, $constructions);

						continue;
					}

					// The condition of a node owning the reserved container is that property and nothing
					// else, so a group arriving here is one of a node of another kind - dropped instead of
					// becoming a branch of its own, exactly as before the container started building
					// children.
					if ($isPortless)
					{
						continue;
					}

					$currentActivity = $this->processIfConditionExpression((int)$position, $constructions);
				}

				if ($expression instanceof OutputExpressionDto)
				{
					$outputActivity = $prevActivity;
					if (!$outputActivity)
					{
						$outputActivity = $this->makeStubOutputActivity();

						$this->childrenActivities[] = $outputActivity->toArray();
						$inputActivity ??= $outputActivity;
					}

					$this->processOutputExpression($expression, $outputActivity);

					break;
				}

				if ($currentActivity)
				{
					if ($prevActivity)
					{
						$this->links[] = ["$prevActivity->name:o0", "$currentActivity->name:i0"];
					}

					$prevActivity = $currentActivity;
					$inputActivity ??= $currentActivity;

					$this->childrenActivities[] = $currentActivity->toArray();
				}
			}

			if ($inputActivity)
			{
				$inputActivities[] = $inputActivity;
			}
		}

		$this->registerInputNames($portRuleCollection, $isPortless, $inputActivities);
	}

	/**
	 * TPL-02: the start of every rule chain of the container, written into `InputNames` of the host node.
	 *
	 * A container addressed to an input port names its chains by the number of that port; the reserved
	 * container of a node without input ports has no such number and keeps them under its own reserved key
	 * ({@see ComplexActivityService::PORTLESS_RULES_KEY}), the very key that addresses the container itself.
	 * A container that built no chain writes no entry at all: an absent key is what "no children" is.
	 *
	 * @param list<ActivityData> $inputActivities First activity of every rule chain of the container.
	 */
	private function registerInputNames(
		PortRuleDto $portRuleCollection,
		bool $isPortless,
		array $inputActivities,
	): void
	{
		$inputNameRefs = [];
		foreach ($inputActivities as $inputActivity)
		{
			// Every rule chain is entered through input port 0 of its own first activity, so the suffix
			// is a fixed i0 and never repeats the number of the outer host port.
			$inputNameRefs[] = $inputActivity->name
				. FlowDirectedActivity::LINK_DELIMITER
				. PortType::Input->value
				. '0'
			;
		}

		if ($inputNameRefs === [])
		{
			return;
		}

		$containerKey = $isPortless
			? ComplexActivityService::PORTLESS_RULES_KEY
			: (int)preg_replace('/\D+/', '', $portRuleCollection->portId)
		;

		// Wire the input of every rule of the container, not just the first one, so multi-rule switch nodes
		// and multi-rule CRM-complex inputs do not lose part of their input binding on assembly and
		// round-trip. A single-rule container keeps a scalar entry and a multi-rule one carries the list of
		// all rule inputs; both the complex-node runtime (BaseComplexActivity::getStartActivityNames) and
		// the one of a node served by the unified panel (CBPActivity::getStartActivityNames) normalise
		// either shape.
		$this->inputNames[$containerKey] = count($inputNameRefs) === 1
			? $inputNameRefs[0]
			: $inputNameRefs
		;
	}

	/**
	 * Whether a rules container is the reserved one of a node without input ports. The key and the `portId`
	 * of the payload carry the same name, so either answers; neither is resolved against the real ports.
	 */
	private function isPortlessRuleContainer(string|int $containerKey, mixed $portRule): bool
	{
		if ((string)$containerKey === ComplexActivityService::PORTLESS_RULES_KEY)
		{
			return true;
		}

		return $portRule instanceof PortRuleDto
			&& $portRule->portId === ComplexActivityService::PORTLESS_RULES_KEY
		;
	}

	/**
	 * Serialize one filter construction into the host-level filter settings and its return properties.
	 *
	 * @return string|null Id of the registered filter, becoming the source of the next filter of the
	 *     chain; null when the construction carries no filter backing activity and registers nothing.
	 */
	private function processFilterConstruction(
		ConstructionDto $construction,
		ActionExpressionDto $expression,
		?string $previousFilterId,
	): ?string
	{
		$filterSettings = $this->extractFilterSettings($construction, $expression, $previousFilterId);
		if ($filterSettings === null)
		{
			return null;
		}

		$this->filterSettings[] = $filterSettings;

		$filterReturnProperty = $this->extractFilterReturnProperty($expression, $filterSettings['id']);
		if ($filterReturnProperty !== null)
		{
			$this->registerFilterReturnProperty($filterSettings['id'], $filterReturnProperty);
		}

		return $filterSettings['id'];
	}

	/**
	 * Merge Properties from a BASE_SETTINGS expression into the host activity's Properties accumulator.
	 * Called during processPortRuleCollection(); the merged result is applied in execute().
	 * Variant A invariant: no child activity is created, Links are not updated.
	 *
	 * Host-metadata keys (Title, EditorComment) are stripped before merging: SaveCommandHandler
	 * unconditionally writes them as empty strings for base-settings (which has no own title),
	 * and merging them would overwrite the real Title/EditorComment of the host node.
	 */
	private function mergeBaseSettingsProperties(BaseSettingsExpressionDto $expression): void
	{
		$activityData = $expression->activityData ?? [];
		$properties = is_array($activityData['Properties'] ?? null) ? $activityData['Properties'] : [];

		// Strip host-metadata keys injected by SaveCommandHandler that are not owned by base-settings.
		foreach (self::BASE_SETTINGS_EXCLUDED_PROPERTIES as $key)
		{
			unset($properties[$key]);
		}

		$this->baseSettingsProperties = [
			...$this->baseSettingsProperties,
			...$properties,
		];
	}

	private function extractFilterSettings(
		ConstructionDto $construction,
		ActionExpressionDto $expression,
		?string $previousFilterId,
	): ?array
	{
		$activityData = $expression->activityData ?? [];
		$activityType = (string)($activityData['Type'] ?? '');
		if (!self::isNodeFilterBackingActivity($activityType))
		{
			return null;
		}

		$properties = is_array($activityData['Properties'] ?? null) ? $activityData['Properties'] : [];
		$conditions = is_array($properties['DynamicFilterFields'] ?? null)
			? $properties['DynamicFilterFields']
			: ['items' => []];
		$filterId = is_string($activityData['Name'] ?? null) && $activityData['Name'] !== ''
			? $activityData['Name']
			: $construction->id;

		$filterSettings = [
			'id' => $filterId,
			'targetEntityTypeId' => (int)($properties['DynamicTypeId'] ?? 0),
			'sourceMode' => $previousFilterId ? 'filter' : 'workflow',
			'conditions' => $conditions,
		];

		if ($previousFilterId)
		{
			$filterSettings['sourceFilterId'] = $previousFilterId;
		}

		return $filterSettings;
	}

	private static function isNodeFilterBackingActivity(string $activityType): bool
	{
		if ($activityType === '')
		{
			return false;
		}

		if (!\CBPRuntime::getRuntime()->includeActivityFile($activityType))
		{
			return false;
		}

		return is_subclass_of('CBP' . $activityType, NodeFilterMetadataProvider::class, true);
	}

	private function extractFilterReturnProperty(ActionExpressionDto $expression, string $filterId): ?array
	{
		$activityData = $expression->activityData ?? [];
		$returnProperties = is_array($activityData['ReturnProperties'] ?? null)
			? $activityData['ReturnProperties']
			: [];

		$documentProperty = null;
		foreach ($returnProperties as $property)
		{
			if (
				is_array($property)
				&& ($property['Id'] ?? null) === 'Document'
				&& is_array($property['Default'] ?? null)
			)
			{
				$documentProperty = $property;

				break;
			}
		}

		if ($documentProperty === null)
		{
			return null;
		}

		$documentTitle = trim((string)($documentProperty['Name'] ?? ''));
		$filterTitle = trim((string)($activityData['Properties']['Title'] ?? ''));

		$buildProperty = fn(string $messageId, bool $multiple): array => [
			'Name' => $this->composeFilterResultTitle($messageId, $documentTitle, $filterTitle, $filterId),
			'Type' => \Bitrix\Bizproc\FieldType::DOCUMENT,
			'Multiple' => $multiple,
			'Default' => $documentProperty['Default'],
		];

		return [
			'single' => $buildProperty('BIZPROCDESIGNER_CONVERT_RULE_FILTER_RESULT_FIRST', false),
			'all' => $buildProperty('BIZPROCDESIGNER_CONVERT_RULE_FILTER_RESULT_ALL', true),
		];
	}

	private function composeFilterResultTitle(
		string $messageId,
		string $documentTitle,
		string $filterTitle,
		string $filterId,
	): string
	{
		return (string)Loc::getMessage(
			$messageId,
			[
				'#DOCUMENT#' => $documentTitle !== '' ? $documentTitle : $filterId,
				'#FILTER#' => $filterTitle,
			],
		);
	}

	private function registerFilterReturnProperty(string $filterId, array $properties): void
	{
		if (is_array($properties['single'] ?? null))
		{
			$this->putFilterReturnProperty($filterId, $properties['single']);
		}

		if (is_array($properties['all'] ?? null))
		{
			$this->putFilterReturnProperty($filterId . self::FILTER_RESULT_ALL_SUFFIX, $properties['all']);
		}
	}

	private function putFilterReturnProperty(string $propertyId, array $property): void
	{
		$this->filterReturnProperties[$propertyId] = $property;

		$returnProperty = ['Id' => $propertyId, ...$property];
		if (isset($this->filterReturnPropertyIndexes[$propertyId]))
		{
			$this->filterReturnPropertyList[$this->filterReturnPropertyIndexes[$propertyId]] = $returnProperty;

			return;
		}

		$this->filterReturnPropertyIndexes[$propertyId] = count($this->filterReturnPropertyList);
		$this->filterReturnPropertyList[] = $returnProperty;
	}

	/**
	 * The whole output package of the converted node, the same one the template load publishes for it.
	 *
	 * The answer of a save replaces the activity of the block on the canvas outright, so a package narrower
	 * than the one the load computes is not a smaller answer but a loss: until the page is reloaded, the
	 * children of the node stop seeing its outputs in the value selector. The package is therefore taken from
	 * {@see ReturnPropertiesResolver} - the `RETURN` of the description plus the `ADDITIONAL_RESULT`
	 * properties of the instance - exactly as {@see \Bitrix\Bizproc\Workflow\Template\Converter\TemplateToNodes}
	 * and the ordinary activity save take it.
	 *
	 * The filter projections of this very conversion are merged over it by `Id` and never duplicate an entry:
	 * a node that names `FilterReturnPropertiesMap` in its `ADDITIONAL_RESULT` already got them from the
	 * resolver, in its normalized form, and keeps that one. They are still merged for the sake of a node that
	 * does not name the key: its filter results reached the client before this change and must keep reaching
	 * it.
	 */
	private function composeReturnProperties(array $activity): array
	{
		$properties = [];
		foreach (ReturnPropertiesResolver::resolve($activity) as $property)
		{
			$properties[(string)$property['Id']] = $property;
		}

		foreach ($this->filterReturnPropertyList as $property)
		{
			$properties[(string)$property['Id']] ??= $property;
		}

		return array_values($properties);
	}

	private function prepareActionActivityData(array $activityData, ?string $targetFilterId): array
	{
		$properties = is_array($activityData['Properties'] ?? null) ? $activityData['Properties'] : [];

		if ($targetFilterId !== null)
		{
			$properties[self::TARGET_FILTER_ID_PROPERTY] = $targetFilterId;
		}
		else
		{
			unset($properties[self::TARGET_FILTER_ID_PROPERTY]);
		}

		$activityData['Properties'] = $properties;

		return $activityData;
	}

	private function resolveTargetFilterIdForAction(ActionExpressionDto $expression): ?string
	{
		$document = $expression->document;
		if (!is_string($document) || $document === '')
		{
			return null;
		}

		$parsedExpression = \CBPActivity::parseExpression($document);
		if ($parsedExpression === null)
		{
			return null;
		}

		foreach ([$parsedExpression['object'] ?? null, $parsedExpression['field'] ?? null] as $candidateFilterId)
		{
			if (!is_string($candidateFilterId) || $candidateFilterId === '')
			{
				continue;
			}

			foreach ($this->filterSettings as $filter)
			{
				if (($filter['id'] ?? null) === $candidateFilterId)
				{
					return $candidateFilterId;
				}
			}
		}

		return null;
	}

	/**
	 * Condition entries in the shape properties of an activity carry them. Without a document type the
	 * entries are returned as they arrived: the helper resolves global variables through the document id
	 * and throws on an empty type.
	 */
	private function unConvertNodeCondition(): array
	{
		if ($this->documentType === [])
		{
			return $this->nodeConditionEntries;
		}

		return Helper::unConvertProperties($this->nodeConditionEntries, $this->documentType);
	}

	/** Whether the host node keeps its condition in a property of its own; the same for every container. */
	private function conditionBelongsToNodeProperty(): bool
	{
		return $this->conditionBelongsToNodeProperty ??= BizprocContainer::instance()
			->getUnifiedPanelDescriptorProvider()
			->storesConditionInNodeProperty($this->activity->type)
		;
	}

	/**
	 * Put the condition group that starts at $position into the condition of the host node. Such a node
	 * carries a single group - {@see self::validateSingleConditionGroup()} rejects a second one - so the
	 * entries of the group are the whole condition of the node.
	 *
	 * @param list<ConstructionDto> $constructionList
	 */
	private function collectNodeCondition(int $position, array $constructionList): void
	{
		$this->nodeConditionEntries = [
			...$this->nodeConditionEntries,
			...$this->conditionConverter->convertChain(
				$constructionList,
				$position,
				$this->documentType,
				$this->resolveNodeConditionDocumentType(),
			),
		];
	}

	/**
	 * Document type the `Document` object of the condition of the host node addresses, null while that is the
	 * document type of the template.
	 *
	 * A trigger resolves it from the properties it ends up with, so the base settings host-merged by this very
	 * conversion answer over the properties the node arrived with: they are the same source the merged-properties
	 * check of the controller reads the published type off.
	 */
	private function resolveNodeConditionDocumentType(): ?array
	{
		return $this->conditionConverter->resolveConditionDocumentType(
			$this->activity->type,
			[...$this->activity->properties, ...$this->baseSettingsProperties],
		);
	}

	/**
	 * Wrap the condition group that starts at $position into the child branching activity of a complex node.
	 *
	 * @param list<ConstructionDto> $constructionList
	 */
	private function processIfConditionExpression(
		int $position,
		array $constructionList,
	): ActivityData
	{
		$mixedConditions = $this->conditionConverter->convertChain(
			$constructionList,
			$position,
			$this->documentType,
		);

		$conditionProperties = [
			'Title' => 'ComplexActivityCondition',
			'Conditions' => [
				[
					'Title' => 'ComplexActivityConditionGroup_Positive',
					'mixedcondition' => $mixedConditions,
				],
				[
					'Title' => 'ComplexActivityConditionGroup_Negative',
				],
			],
		];
		$conditionProperties = Helper::unConvertProperties($conditionProperties, $this->documentType);

		return ActivityData::createFromArray([
			'Name' => $this->nameGeneratorService->generate(),
			'Type' => 'IfElseActivity',
			'Properties' => $conditionProperties,
			'Activated' => 'Y',
		]);
	}

	private function processOutputExpression(
		OutputExpressionDto $expression,
		ActivityData $outputActivity,
	): void
	{
		$portId = $expression->portId;
		$portNumber = (int)preg_replace('/\D+/', '', $portId);

		$this->outputNames["$outputActivity->name:o0"] = $portNumber;
	}

	private function makeStubOutputActivity(): ActivityData
	{
		return new ActivityData(
			name: $this->nameGeneratorService->generate(),
			type: 'EmptyBlockActivity',
			activated: true,
			properties: ['Title' => 'ComplexChildrenStubActivity'],
		);
	}
}

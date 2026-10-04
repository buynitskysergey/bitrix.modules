<?php

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service;

use Bitrix\Bizproc\Activity\ActivityDescription;
use Bitrix\Bizproc\Activity\Enum\ActivityNodeType;
use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Internal\AI\Agent\Generator\TemplateBuilder\ActivityRegistry;
use Bitrix\Bizproc\Internal\Service\Activity\ComplexActivityService;
use Bitrix\Bizproc\Internal\Service\Container as BizprocContainer;
use Bitrix\Bizproc\Public\Service\Activity\ActivityNameGeneratorService;
use Bitrix\Bizproc\Runtime\ActivitySearcher\Searcher;
use Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\PortRuleDto;
use Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\Rule\ActionExpressionDto;
use Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\Rule\ConditionExpression\FieldDto;
use Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\Rule\ConditionExpressionDto;
use Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\Rule\ConstructionDto;
use Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\Rule\OutputExpressionDto;
use Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\RuleDto;
use Bitrix\BizprocDesigner\Infrastructure\Enum\ConstructionType;
use Bitrix\BizprocDesigner\Internal\Command\Activity\Complex\ConvertRuleCommand;
use Bitrix\BizprocDesigner\Internal\Command\Activity\Complex\ConvertRuleCommandResult;
use Bitrix\BizprocDesigner\Internal\Exception\CommandValidateException;
use Bitrix\BizprocDesigner\Internal\Entity\ActivityData;
use Bitrix\BizprocDesigner\Internal\Entity\AgentPortDefault;
use Bitrix\BizprocDesigner\Internal\Entity\Block;
use Bitrix\BizprocDesigner\Internal\Entity\FrameData;
use Bitrix\BizprocDesigner\Internal\Entity\Collection\BlockCollection;
use Bitrix\BizprocDesigner\Internal\Entity\Collection\ConnectionCollection;
use Bitrix\BizprocDesigner\Internal\Entity\Collection\PortCollection;
use Bitrix\BizprocDesigner\Internal\Entity\Connection;
use Bitrix\BizprocDesigner\Internal\Entity\NodeType;
use Bitrix\BizprocDesigner\Internal\Entity\Port;
use Bitrix\BizprocDesigner\Internal\Enum\PortDirection;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentBlock;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentComplexConstruction;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentComplexRule;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentBlockCollection;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentConnection;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentConnectionCollection;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentSetting;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Enum\RequestSource;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentSettingCollection;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentTemplate;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\Draft;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Layout\AgentBlockGeometry;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Layout\FrameGeometry;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Layout\TopologyLayoutEngine;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Validator\FrameBlockMatcher;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Diag\LoggerFactory;
use Bitrix\Main\Error;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Main\Security\Random;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class AiAssistantDraftConverterService
{
	use AgentBlockNamingTrait;

	private const BLOCK_WIDTH = 200;
	private const BLOCK_HEIGHT = 50;
	private const PORT_POSITION = 1;
	/**
	 * Titles the complex node's dynamic ports the same way the manual editor does (shared frontend
	 * contract COMPLEX_NODE_PORT_LABELS in the chart extension): input rule ports are G{n}, output rule
	 * ports are E{n}, numbered by position from 1. The node editor matches/parses complex ports by these
	 * titles, so a port without one breaks the editor.
	 */
	private const COMPLEX_PORT_LABEL_INPUT = 'G';
	private const COMPLEX_PORT_LABEL_OUTPUT = 'E';
	private const START_POSITION_X = 300;
	private const START_POSITION_Y = 50;
	private const BLOCK_MARGIN = 100;
	private const TITLE_PROPERTY_NAME = 'Title';

	/** Shared diagnostic channel with the catalog services (no new log channel). */
	private const LOGGER_ID = 'bizprocdesigner.aiassistant.block_catalog';
	private const COMPLEX_OUTCOME_MATERIALIZED = 'materialized';
	private const COMPLEX_OUTCOME_FALLBACK = 'fallback';

	private string $uniqueBlockSalt;
	private ?ActivityRegistry $activityRegistry = null;
	private readonly AgentBlockMetadataResolver $metadataResolver;
	private ?ComplexActivityService $complexActivityService = null;
	private ?ActivityNameGeneratorService $nameGeneratorService = null;
	private ?Searcher $searcher = null;
	private ?LoggerInterface $logger = null;
	/** @var array<string, array<string, ActivityDescription>> preset-applied node actions per block type */
	private array $nodeActionDescriptionCache = [];
	/** @var array<string, list<string>> ADDITIONAL_RESULT marker keys per normalized activity code */
	private array $additionalResultKeysCache = [];

	public function __construct(?AgentBlockMetadataResolver $metadataResolver = null)
	{
		$this->metadataResolver = $metadataResolver ?? new AgentBlockMetadataResolver();
		$this->uniqueBlockSalt = Random::getStringByAlphabet(
			8,
			Random::ALPHABET_ALPHALOWER | Random::ALPHABET_ALPHAUPPER,
		);
	}

	public function covertFromAgentInput(
		int $draftId,
		int $templateId,
		int $userId,
		AgentBlockCollection $blocks,
		AgentConnectionCollection $connections,
		array $documentType = [],
		RequestSource $source = RequestSource::Rest,
	): Draft
	{
		return new Draft(
			draftId: $draftId,
			templateId: $templateId,
			userId: $userId,
			blocks: $this->makeBlocks($blocks, $connections, $documentType, $source),
			connections: $this->makeConnections($connections),
		);
	}

	private function makeBlocks(
		AgentBlockCollection $blocks,
		AgentConnectionCollection $connections,
		array $documentType = [],
		RequestSource $source = RequestSource::Marta,
	): BlockCollection
	{
		$iconMap = $this->metadataResolver->getIconMap();
		$positions = (new TopologyLayoutEngine())->compute($blocks, $connections);
		$blocksById = $this->indexById($blocks);
		$collection = new BlockCollection();

		foreach ($blocks as $block)
		{
			$pos = $positions[$block->id] ?? ['x' => self::START_POSITION_X, 'y' => self::START_POSITION_Y];

			// Multi-preset activities carry their name/icon/color on the preset (key type_presetId), so a
			// block with a preset resolves its visuals from that entry; anything else uses the base type.
			// A preset the catalog does not know stays unapplied: writing it onto the activity would hand the
			// agent back a preset its own validator rejects on the next send.
			$iconKey = mb_strtolower($block->type);
			$presetId = null;
			if ($block->presetId !== null && $block->presetId !== '' && isset($iconMap[$iconKey . '_' . $block->presetId]))
			{
				$iconKey .= '_' . $block->presetId;
				$presetId = $block->presetId;
			}
			$iconEntry = $iconMap[$iconKey] ?? [];
			$systemName = (string)($iconEntry['name'] ?? '');
			$nodeType = $this->makeNodeType($block->type, $block->presetId, $source);
			$activityId = $this->makeActivityId($block->id);
			$activityType = $iconEntry['className'] ?? $block->type;

			// A frame overlay carries no ports/dialog; its geometry is the bounding box of the members it
			// groups (server-computed), so it is assembled by a dedicated branch rather than the activity path.
			if ($nodeType === NodeType::Frame)
			{
				$collection->add(
					$this->makeFrameBlock($block, $activityId, (string)$activityType, $systemName, $iconEntry, $positions, $blocksById),
				);

				continue;
			}

			$isComplex = $nodeType === NodeType::Complex && $block->rules !== null;

			$collection->add(
				new Block(
					id: $activityId,
					type: $nodeType,
					x: $pos['x'],
					y: $pos['y'],
					width: self::BLOCK_WIDTH,
					height: self::BLOCK_HEIGHT,
					title: $systemName,
					icon: $iconEntry['icon'] ?? '',
					ports: $isComplex ? $this->makeComplexPorts($block) : $this->makePortsByBlock($block),
					activityData: $isComplex
						? $this->makeComplexActivityData($block, $activityId, $activityType, $documentType)
						: new ActivityData(
							name: $activityId,
							type: $activityType,
							properties: $this->makeActivityProperties($block, $systemName, $activityId, $activityType, $documentType),
							// The preset is a first-class field of a saved activity (the manual editor stamps it
							// when a node is created from the catalog), so it is persisted rather than left to be
							// guessed back from Document: presets that apply no Document (DYNAMIC,
							// AUTOMATED_SOLUTION, ...) are otherwise unrecoverable on read.
							presetId: $presetId,
						),
					colorIndex: isset($iconEntry['colorIndex']) ? (int)$iconEntry['colorIndex'] : null,
					contentBlockColor: isset($iconEntry['contentBlockColor']) ? (int)$iconEntry['contentBlockColor'] : null,
				),
			);
		}

		return $collection;
	}

	/**
	 * Builds a frame overlay block (NodeType::Frame). Its geometry is the bounding box of the members it
	 * groups plus a fixed padding (ALG-02, {@see FrameGeometry}); it carries no ports (an explicit empty
	 * collection, so the port fallback never synthesises i0/o0), keeps the top-level frame type the frontend
	 * needs for z-order, and its node.frame* styling layers the agent overrides over the shared defaults
	 * ({@see FrameData}). The membership itself is a validated logical set - only the geometry is derived here.
	 */
	private function makeFrameBlock(
		AgentBlock $block,
		string $activityId,
		string $activityType,
		string $systemName,
		array $iconEntry,
		array $positions,
		array $blocksById,
	): Block
	{
		$rect = FrameGeometry::boundingBox($this->collectMemberRects($block->memberBlockIds, $positions, $blocksById));
		$title = $block->title !== '' ? $block->title : $systemName;

		return new Block(
			id: $activityId,
			type: NodeType::Frame,
			x: $rect['x'],
			y: $rect['y'],
			width: $rect['width'],
			height: $rect['height'],
			title: $title,
			icon: (string)($iconEntry['icon'] ?? ''),
			ports: new PortCollection(),
			activityData: new ActivityData(
				name: $activityId,
				type: $activityType,
				properties: $title !== '' ? [self::TITLE_PROPERTY_NAME => $title] : [],
			),
			colorIndex: isset($iconEntry['colorIndex']) ? (int)$iconEntry['colorIndex'] : null,
			contentBlockColor: isset($iconEntry['contentBlockColor']) ? (int)$iconEntry['contentBlockColor'] : null,
			frameData: new FrameData(
				colorName: $block->frameColorName,
				content: $block->frameContent,
			),
		);
	}

	/**
	 * Member rectangles in TopologyLayoutEngine space: each member's top-left position sized by its real
	 * widget type ({@see AgentBlockGeometry::measure}), so the frame bounding box matches the on-canvas render.
	 * The layout is keyed by the raw agent block id (the same key the members reference), NOT by the converted
	 * activity id, so member ids must not be passed through makeActivityId() here. Members with an unknown
	 * position or block are skipped.
	 *
	 * @param list<string> $memberBlockIds
	 * @param array<string, array{x: int, y: int}> $positions
	 * @param array<string, AgentBlock> $blocksById
	 * @return list<array{x: int, y: int, width: int, height: int}>
	 */
	private function collectMemberRects(array $memberBlockIds, array $positions, array $blocksById): array
	{
		$memberRects = [];
		foreach ($memberBlockIds as $memberId)
		{
			if (!isset($positions[$memberId], $blocksById[$memberId]))
			{
				continue;
			}

			$size = AgentBlockGeometry::measure($blocksById[$memberId], $this->getActivityRegistry());
			$memberRects[] = [
				'x' => $positions[$memberId]['x'],
				'y' => $positions[$memberId]['y'],
				'width' => $size['width'],
				'height' => $size['height'],
			];
		}

		return $memberRects;
	}

	/**
	 * Indexes the agent blocks by their raw id, so a frame can size its members by type.
	 *
	 * @return array<string, AgentBlock>
	 */
	private function indexById(AgentBlockCollection $blocks): array
	{
		$index = [];
		foreach ($blocks as $block)
		{
			$index[$block->id] = $block;
		}

		return $index;
	}

	/**
	 * Builds the internal connections, preserving the explicit ports supplied by the agent.
	 *
	 * Loop-back topology is carried through here without special-casing via an explicit targetPortId, so a
	 * back edge is never collapsed into the default entry input. The two loop nodes differ in shape:
	 * - ForEach has a dedicated loop-back input i1 (title "->>"); the body tail closes back into i1,
	 *   while the ordinary entry input is i0.
	 * - While has no separate loop-back input: its loop-back is expressed on the output side (o0, title
	 *   "->>", into the body), and the body tail closes back into the ordinary entry input i0.
	 * The o0/i0 defaults apply only to non-loop connections that omit a port; loop connections always
	 * carry their ports explicitly.
	 */
	private function makeConnections(AgentConnectionCollection $connections): ConnectionCollection
	{
		$collection = new ConnectionCollection();
		foreach ($connections as $connection)
		{
			$sourceBlockId = $this->makeActivityId($connection->sourceBlockId);
			$targetBlockId = $this->makeActivityId($connection->destinationBlockId);
			$sourcePortId = $connection->sourcePortId ?? AgentPortDefault::SOURCE_PORT_ID;
			$targetPortId = $connection->targetPortId ?? AgentPortDefault::TARGET_PORT_ID;
			$collection->add(
				new Connection(
					id: "{$sourceBlockId}_{$sourcePortId}_{$targetBlockId}_{$targetPortId}",
					sourceBlockId: $sourceBlockId,
					sourcePortId: $sourcePortId,
					targetBlockId: $targetBlockId,
					targetPortId: $targetPortId,
				),
			);
		}

		return $collection;
	}

	/**
	 * Builds the block ports from the activity description (NODE_SETTINGS.ports via ActivityRegistry),
	 * so the draft carries the same N-port topology as the node editor. Triggers keep output ports only.
	 */
	private function makePortsByBlock(AgentBlock $block): PortCollection
	{
		$ports = new PortCollection();
		$isTrigger = $this->makeNodeType($block->type) === NodeType::Trigger;

		foreach ($this->getActivityRegistry()->getDefaultPorts($block->type) as $port)
		{
			$direction = PortDirection::tryFrom((string)($port['type'] ?? ''));
			if ($direction === null)
			{
				continue;
			}

			if ($isTrigger && $direction !== PortDirection::Output)
			{
				continue;
			}

			$ports->add(
				new Port(
					id: (string)($port['id'] ?? ''),
					direction: $direction,
					position: self::PORT_POSITION,
				),
			);
		}

		return $ports;
	}

	private function getActivityRegistry(): ActivityRegistry
	{
		return $this->activityRegistry ??= new ActivityRegistry();
	}

	private function getComplexActivityService(): ComplexActivityService
	{
		return $this->complexActivityService ??= BizprocContainer::instance()->getComplexActivityService();
	}

	private function getSearcher(): Searcher
	{
		return $this->searcher ??= BizprocContainer::instance()->getActivitySearcherService();
	}

	private function getNameGeneratorService(): ActivityNameGeneratorService
	{
		return $this->nameGeneratorService ??= ServiceLocator::getInstance()->get('bizproc.service.activity.nameGenerator');
	}

	/**
	 * Ports of a complex node are dynamic: input ports are the rule-projection keys, output ports are
	 * the ids declared by `output` constructions (never the static NODE_SETTINGS.ports of the wrapper).
	 */
	private function makeComplexPorts(AgentBlock $block): PortCollection
	{
		$ports = new PortCollection();
		if ($block->rules === null)
		{
			return $ports;
		}

		$inputNumber = 0;
		foreach ($block->rules->getInputPortIds() as $portId)
		{
			$ports->add(new Port(
				id: $portId,
				direction: PortDirection::Input,
				position: self::PORT_POSITION,
				title: self::COMPLEX_PORT_LABEL_INPUT . (++$inputNumber),
			));
		}

		$outputNumber = 0;
		foreach ($block->rules->getOutputPortIds() as $portId)
		{
			$ports->add(new Port(
				id: $portId,
				direction: PortDirection::Output,
				position: self::PORT_POSITION,
				title: self::COMPLEX_PORT_LABEL_OUTPUT . (++$outputNumber),
			));
		}

		return $ports;
	}

	/**
	 * Builds the complex node's sub-graph by delegating to the rule-engine ({@see ConvertRuleCommand}),
	 * the single source of truth also used by the manual editor - the AI pipeline never assembles the
	 * sub-graph itself (ADR invariant). The agent projection (DTO-01) is mapped to the domain rule
	 * dictionary (presets applied via {@see ComplexActivityService}) and stored on the host as the
	 * canonical `Rules` property; the command produces Children/Links/InputNames/OutputNames (and, for
	 * filters, the host FilterSettings/FilterReturnPropertiesMap/ReturnProperties).
	 */
	private function makeComplexActivityData(
		AgentBlock $block,
		string $activityId,
		string $activityType,
		array $documentType,
	): ActivityData
	{
		$portRuleDtoDictionary = $this->buildPortRuleDtoDictionary($block);

		$properties = [
			self::TITLE_PROPERTY_NAME => $block->title,
			$this->resolveRulePropertyName($activityType) => $this->serializePortRuleDictionary($portRuleDtoDictionary),
		];

		$hostActivity = new ActivityData(
			name: $activityId,
			type: $activityType,
			properties: $properties,
		);

		$portCount = count($portRuleDtoDictionary);
		$ruleCount = $this->countRules($portRuleDtoDictionary);

		try
		{
			$result = (new ConvertRuleCommand(
				activity: $hostActivity,
				portRuleDtoDictionary: $portRuleDtoDictionary,
				documentType: $documentType,
			))->run();

			if ($result instanceof ConvertRuleCommandResult)
			{
				$this->logComplexSubGraphMaterialization(
					$activityType,
					$portCount,
					$ruleCount,
					self::COMPLEX_OUTCOME_MATERIALIZED,
				);

				return $result->activityData;
			}

			$this->logComplexSubGraphMaterialization(
				$activityType,
				$portCount,
				$ruleCount,
				self::COMPLEX_OUTCOME_FALLBACK,
				$this->formatErrorReasons($result->getErrors()),
			);
		}
		catch (CommandValidateException $exception)
		{
			// The rule-engine rejected the projection the same way the manual editor's ConvertRuleCommand
			// would - record the reasons so a divergence between the agent's assembly and the editor is
			// discoverable.
			$this->logComplexSubGraphMaterialization(
				$activityType,
				$portCount,
				$ruleCount,
				self::COMPLEX_OUTCOME_FALLBACK,
				$this->formatErrorReasons($exception->errors),
			);
		}
		catch (\Throwable $exception)
		{
			$this->logComplexSubGraphMaterialization(
				$activityType,
				$portCount,
				$ruleCount,
				self::COMPLEX_OUTCOME_FALLBACK,
				[$exception->getMessage()],
			);
		}

		// Isolate a rule-engine failure: keep the block with its canonical Rules property instead of
		// dropping the whole draft (failure isolation, as in the catalog listing) - the diagnostic marker
		// above never changes this outcome.
		return $hostActivity;
	}

	/**
	 * @param array<string, PortRuleDto> $portRuleDtoDictionary
	 */
	private function countRules(array $portRuleDtoDictionary): int
	{
		$count = 0;
		foreach ($portRuleDtoDictionary as $portRule)
		{
			$count += count($portRule->rules);
		}

		return $count;
	}

	/**
	 * Emits a diagnostic marker for a complex node's sub-graph materialization on the same channel the
	 * catalog uses for skipped blocks ({@see BlockDescriptionService::logSkippedBlock}) - no new channel.
	 * It records the host type, the port/rule counts and the outcome; on a fallback it attaches the
	 * rule-engine reasons, so a divergence between the agent's assembly and the manual editor is
	 * discoverable. Purely observational: it never changes the assembled block nor drops the draft
	 * (failure isolation, as in the catalog listing).
	 *
	 * @param list<string> $reasons
	 */
	private function logComplexSubGraphMaterialization(
		string $activityType,
		int $portCount,
		int $ruleCount,
		string $outcome,
		array $reasons = [],
	): void
	{
		$context = [
			'activity' => $activityType,
			'ports' => $portCount,
			'rules' => $ruleCount,
			'outcome' => $outcome,
		];
		if ($reasons !== [])
		{
			$context['reasons'] = $reasons;
		}

		$message = 'AI complex node: sub-graph materialization';
		if ($outcome === self::COMPLEX_OUTCOME_FALLBACK)
		{
			$this->getLogger()->warning($message, $context);
		}
		else
		{
			$this->getLogger()->info($message, $context);
		}
	}

	/**
	 * @param Error[] $errors
	 * @return list<string>
	 */
	private function formatErrorReasons(array $errors): array
	{
		$reasons = [];
		foreach ($errors as $error)
		{
			if (!$error instanceof Error)
			{
				continue;
			}

			$code = (string)$error->getCode();
			$reasons[] = ($code !== '' && $code !== '0' ? $code . ': ' : '') . $error->getMessage();
		}

		return $reasons;
	}

	private function getLogger(): LoggerInterface
	{
		if ($this->logger === null)
		{
			$this->logger = (new LoggerFactory())->createById(self::LOGGER_ID) ?? new NullLogger();
		}

		return $this->logger;
	}

	/**
	 * Maps the nested agent projection (DTO-01) onto the domain rule dictionary (map<portId, PortRuleDto>)
	 * consumed by {@see ConvertRuleCommand}. This is the symmetric counterpart of the manual editor's
	 * `extractRulePropertyValue`, so the same command produces an identical sub-graph.
	 *
	 * @return array<string, PortRuleDto>
	 */
	private function buildPortRuleDtoDictionary(AgentBlock $block): array
	{
		$dictionary = [];
		foreach ($block->rules->portRules as $portId => $rules)
		{
			$ruleDtos = [];
			foreach ($rules as $rule)
			{
				$ruleDtos[] = new RuleDto($rule->id, $this->buildRuleConstructions($rule, $block));
			}

			$dictionary[$portId] = new PortRuleDto($portId, $ruleDtos);
		}

		return $dictionary;
	}

	/**
	 * @return list<ConstructionDto>
	 */
	private function buildRuleConstructions(AgentComplexRule $rule, AgentBlock $block): array
	{
		$constructions = [];
		$previousWasCondition = false;

		foreach ($rule->constructions as $construction)
		{
			$expression = $construction->expression;

			$constructions[] = match ($construction->type)
			{
				AgentComplexConstruction::TYPE_CONDITION => new ConstructionDto(
					$this->generateConstructionId(),
					$this->resolveConditionConstructionType($expression, $previousWasCondition),
					$this->mapConditionExpression($expression),
				),
				AgentComplexConstruction::TYPE_ACTION => new ConstructionDto(
					$this->generateConstructionId(),
					ConstructionType::ACTION,
					$this->mapActionExpression($expression, $block),
				),
				AgentComplexConstruction::TYPE_FILTER => new ConstructionDto(
					$this->generateConstructionId(),
					ConstructionType::FILTER,
					$this->mapFilterExpression($expression),
				),
				AgentComplexConstruction::TYPE_OUTPUT => new ConstructionDto(
					$this->generateConstructionId(),
					ConstructionType::OUTPUT,
					$this->mapOutputExpression($expression),
				),
				default => null,
			};

			$previousWasCondition = $construction->type === AgentComplexConstruction::TYPE_CONDITION;
		}

		return array_values(array_filter($constructions));
	}

	/**
	 * Expands the DNF joiner of the agent condition contract (DTO-03) back into the domain construction
	 * type: the first condition of a contiguous run is the `if`, later conditions are `and`/`or` by
	 * joiner (`OR` opens a new group). The joiner of the first condition is irrelevant - a group's first
	 * item is always treated as AND, which is exactly what `condition:if` yields downstream.
	 */
	private function resolveConditionConstructionType(array $expression, bool $previousWasCondition): ConstructionType
	{
		if (!$previousWasCondition)
		{
			return ConstructionType::IF_CONDITION;
		}

		return ($expression['joiner'] ?? 'AND') === 'OR'
			? ConstructionType::OR_CONDITION
			: ConstructionType::AND_CONDITION
		;
	}

	private function mapConditionExpression(array $expression): ConditionExpressionDto
	{
		$field = $expression['field'] ?? null;

		return new ConditionExpressionDto(
			field: is_array($field) ? FieldDto::createFromArray($field) : null,
			operator: isset($expression['operator']) ? (string)$expression['operator'] : null,
			value: $expression['value'] ?? null,
		);
	}

	private function mapOutputExpression(array $expression): OutputExpressionDto
	{
		return new OutputExpressionDto(
			isset($expression['portId']) ? (string)$expression['portId'] : null,
			isset($expression['title']) ? (string)$expression['title'] : null,
		);
	}

	/**
	 * Builds the sub-action child activity data from the agent action ({activityCode, settings, document}),
	 * applying the node-action preset via {@see ComplexActivityService} (the same source as the manual
	 * editor's palette): the resolved activity class becomes the child Type, the preset title/id are
	 * stamped, and the agent settings fill the properties. Presets are mandatory - a naive child would
	 * diverge from the editor.
	 */
	private function mapActionExpression(array $expression, AgentBlock $block): ActionExpressionDto
	{
		$activityCode = isset($expression['activityCode']) ? (string)$expression['activityCode'] : '';
		// `document` binds the sub-action to a document via a bizproc expression `{=<sourceBlockId>:<propertyId>}`
		// (e.g. `{=<triggerId>:ReturnDocument}`); downstream ({@see ConvertRuleCommand::resolveTargetFilterIdForAction},
		// the runtime `setDocumentContext(string)`) parses/consumes it as a string. Accept only a non-empty string
		// here - a non-string (e.g. a document-type triplet array, an invalid input) is dropped to null instead of
		// being blindly `(string)`-cast into `"Array"`. Such invalid input is already rejected by
		// {@see AgentComplexRulesValidator::validateAction} before reaching the converter (both the REST and Marta paths).
		$document = (isset($expression['document']) && is_string($expression['document']) && $expression['document'] !== '')
			? $expression['document']
			: null;

		// Optional aux branch of a sub-action (manual-editor feature the reverse path carries back). Restored
		// verbatim so a round-trip of a hand-built template does not drop it; the rule-engine materialises
		// `auxPortId` into the child `Properties.auxPort`. AI-generated sub-actions never carry it (BC).
		$auxPortId = isset($expression['auxPortId']) && $expression['auxPortId'] !== ''
			? (string)$expression['auxPortId']
			: null;
		$auxPortTitle = isset($expression['auxPortTitle']) && $expression['auxPortTitle'] !== ''
			? (string)$expression['auxPortTitle']
			: null;

		$nodeAction = $this->resolveNodeActionDescription($block->type, $activityCode);

		$properties = $this->normalizeSettings($expression['settings'] ?? null);
		if (!array_key_exists(self::TITLE_PROPERTY_NAME, $properties))
		{
			$properties[self::TITLE_PROPERTY_NAME] = $nodeAction?->getName() ?? '';
		}

		// Canonical node-action class (PascalCase) - the same value the manual editor keys its `actions`
		// dictionary by ({@see Complex::loadSettingsAction}). Reused for both the child Type and the
		// construction actionId so the settings dialog's `actions.get(actionId)` resolves. Falls back to the
		// raw agent code only when the node-action is unresolved (an out-of-dictionary code the validator owns).
		$actionClass = $nodeAction?->getClass() ?? $activityCode;

		$activityData = [
			'Name' => $this->generateChildName(),
			'Type' => $actionClass,
			'Activated' => 'Y',
			'Properties' => $properties,
			'ReturnProperties' => [],
			'Document' => $document,
		];

		$presetId = $nodeAction?->get('PRESET_ID');
		if (is_string($presetId) && $presetId !== '')
		{
			$activityData['PresetId'] = $presetId;
		}

		return new ActionExpressionDto(
			actionId: $actionClass,
			rawActivityData: null,
			activityData: $activityData,
			document: $document,
			auxPortId: $auxPortId,
			auxPortTitle: $auxPortTitle,
		);
	}

	/**
	 * Builds the filter backing activity data from the agent filter ({activityCode, settings, filterId?}). The
	 * rule-engine serialises it into the host FilterSettings/FilterReturnPropertiesMap rather than into a
	 * child (see {@see ConvertRuleCommand}); the AI pipeline only carries the backing data through.
	 *
	 * The backing activity `Name` is the filter's stable identity: {@see ConvertRuleCommand::extractFilterSettings}
	 * derives `filterId` from it and consumers reference the filter as `{=<filterId>:Document}`
	 * (`TargetFilterId`). A `filterId` supplied by the reverse projection is reused verbatim so the identity
	 * survives a round-trip and those references keep resolving; a genuinely new filter (no id) still gets a
	 * freshly generated name.
	 */
	private function mapFilterExpression(array $expression): ActionExpressionDto
	{
		$activityCode = isset($expression['activityCode']) ? (string)$expression['activityCode'] : '';
		$settings = $this->normalizeSettings($expression['settings'] ?? null);

		$filterName = isset($expression['filterId']) && is_string($expression['filterId']) && $expression['filterId'] !== ''
			? $expression['filterId']
			: $this->generateChildName();

		$activityData = [
			'Name' => $filterName,
			'Type' => $activityCode,
			'Activated' => 'Y',
			'Properties' => $settings,
			'ReturnProperties' => is_array($settings['ReturnProperties'] ?? null) ? $settings['ReturnProperties'] : [],
		];

		return new ActionExpressionDto(
			actionId: $activityCode,
			rawActivityData: null,
			activityData: $activityData,
			document: null,
		);
	}

	/**
	 * Resolves a preset-applied node-action description for the block's complex node, matched by
	 * normalized activity code against the same dictionary the manual editor uses. Cached per block type.
	 */
	private function resolveNodeActionDescription(string $blockType, string $activityCode): ?ActivityDescription
	{
		$normalizedCode = $this->getSearcher()->normalizeActivityCode($activityCode);
		if ($normalizedCode === '')
		{
			return null;
		}

		$blockKey = mb_strtolower($blockType);
		if (!array_key_exists($blockKey, $this->nodeActionDescriptionCache))
		{
			$descriptions = [];
			foreach ($this->getComplexActivityService()->getCorrespondingNodeActionActivityByName($blockKey) as $description)
			{
				/** @var ActivityDescription $description */
				$descriptions[$this->getSearcher()->normalizeActivityCode((string)$description->getClass())] = $description;
			}

			$this->nodeActionDescriptionCache[$blockKey] = $descriptions;
		}

		return $this->nodeActionDescriptionCache[$blockKey][$normalizedCode] ?? null;
	}

	/**
	 * Serialises the domain rule dictionary into the canonical `Rules` property shape (map<portId, {portId,
	 * ruleCards}>) - the same form the manual editor persists and the reverse converter reads back.
	 *
	 * @param array<string, PortRuleDto> $portRuleDtoDictionary
	 */
	private function serializePortRuleDictionary(array $portRuleDtoDictionary): array
	{
		$encoded = json_encode($portRuleDtoDictionary, JSON_UNESCAPED_UNICODE);
		$decoded = $encoded === false ? null : json_decode($encoded, true);

		return is_array($decoded) ? $decoded : [];
	}

	/**
	 * Rule property name of a node, resolved by name through {@see ComplexActivityService::resolveRulePropertyName()}:
	 * the properties map carries more than one property of type {@see FieldType::RULES}, so its order must not
	 * decide where the rules are written. An activity that does not resolve here keeps the canonical name.
	 */
	private function resolveRulePropertyName(string $activityType): string
	{
		try
		{
			$configurator = \CBPActivity::createConfigurator($activityType, []);
			$name = $this->getComplexActivityService()->resolveRulePropertyName($configurator);
			if ($name !== null)
			{
				return $name;
			}
		}
		catch (\Throwable)
		{
		}

		return ComplexActivityService::RULES_PARAM;
	}

	private function normalizeSettings(mixed $settings): array
	{
		return is_array($settings) ? $settings : [];
	}

	private function generateChildName(): string
	{
		return $this->getNameGeneratorService()->generate();
	}

	private function generateConstructionId(): string
	{
		return Random::getStringByAlphabet(16, Random::ALPHABET_ALPHALOWER | Random::ALPHABET_NUM);
	}

	/**
	 * Lays out block properties, keeping the user-facing overrides separate from the system node name.
	 *
	 * Title/EditorComment mirror the manual node editor: an override is written only when it carries
	 * information beyond the system name. An empty title, or a title equal (whitespace-insensitive) to the
	 * system name, adds no Properties.Title; an empty/absent description adds no Properties.EditorComment.
	 *
	 * The user-facing Title/EditorComment are laid out AFTER the settings, so a setting that happens to be
	 * named "Title"/"EditorComment" can never overwrite the block's name/description. Finally the computed
	 * ADDITIONAL_RESULT descriptors (e.g. a CRM trigger's `Return`) are synthesised via applyAdditionalResult.
	 */
	private function makeActivityProperties(
		AgentBlock $block,
		string $systemName,
		string $activityId,
		string $activityType,
		array $documentType,
	): array
	{
		$map = [];

		foreach ($block->settings as $setting)
		{
			$map[$setting->name] = $setting->value;
		}

		if ($block->title !== '' && !$this->isSameNodeName($block->title, $systemName))
		{
			$map[self::TITLE_PROPERTY_NAME] = $block->title;
		}

		if ((string)$block->description !== '')
		{
			$map[self::COMMENT_PROPERTY_NAME] = $block->description;
		}

		return $this->applyAdditionalResult($map, $activityId, $activityType, $documentType);
	}

	/**
	 * Synthesises the computed ADDITIONAL_RESULT descriptors (e.g. a CRM trigger's `Return`) through the same
	 * domain entry the manual editor uses ({@see \Bitrix\BizprocDesigner\Public\Command\Activity\Settings\SaveCommandHandler}):
	 * `getPropertiesDialogValues` recomputes them from the activity's own settings and writes them onto the
	 * activity node. The AI/REST path otherwise maps settings flatly and never runs the dialog, so a downstream
	 * CRM block reading `Return.ReturnDocument` would find it empty until the user re-saved the trigger by hand.
	 *
	 * Only carriers of the marker are processed - its `currentValues` shape and document context are
	 * trigger-specific, so running the dialog for every activity would be wrong. Only the freshly computed
	 * marker keys are lifted back onto the agent-provided settings: `getPropertiesDialogValues` overwrites the
	 * activity `Properties` wholesale, so the service properties the agent set (Title, Name and the flat
	 * settings) are preserved here rather than re-derived (contract of {@see SaveCommandHandler}). A dialog
	 * failure (required Document/Fields missing -> false/errors) leaves the settings untouched instead of
	 * dropping the block: the descriptor is best-effort, the push never fails.
	 */
	private function applyAdditionalResult(
		array $properties,
		string $activityId,
		string $activityType,
		array $documentType,
	): array
	{
		$markerKeys = $this->getAdditionalResultKeys($activityType);
		if ($markerKeys === [])
		{
			return $properties;
		}

		$resolved = $this->resolveDialogProperties($activityId, $activityType, $documentType, $properties);
		if ($resolved === null)
		{
			return $properties;
		}

		foreach ($markerKeys as $key)
		{
			if (is_array($resolved[$key] ?? null))
			{
				$properties[$key] = $resolved[$key];
			}
		}

		return $properties;
	}

	/**
	 * ADDITIONAL_RESULT marker keys declared by the activity's `.description.php` (e.g. `['Return']` for CRM
	 * triggers), or [] when the activity is not a carrier. Resolved by the same catalog lookup
	 * ({@see Searcher::searchByCode}) that keys the block catalog, on the normalized activity code, and cached
	 * per code.
	 *
	 * @return list<string>
	 */
	private function getAdditionalResultKeys(string $activityType): array
	{
		$code = $this->getSearcher()->normalizeActivityCode($activityType);
		if ($code === '')
		{
			return [];
		}

		if (!array_key_exists($code, $this->additionalResultKeysCache))
		{
			$keys = $this->getSearcher()->searchByCode($code)?->getAdditionalResult();
			$this->additionalResultKeysCache[$code] = is_array($keys)
				? array_values(array_filter($keys, 'is_string'))
				: [];
		}

		return $this->additionalResultKeysCache[$code];
	}

	/**
	 * Runs the activity's `getPropertiesDialogValues` exactly as the manual editor does (same document context,
	 * currentValues and by-ref single-node workflow template), returning the recomputed `Properties` of the
	 * activity, or null when the dialog signals a validation error (false result or non-empty errors) so the
	 * caller isolates the failure. The activity file is included first so the static method resolves.
	 *
	 * @return array<string, mixed>|null
	 */
	private function resolveDialogProperties(
		string $activityId,
		string $activityType,
		array $documentType,
		array $currentValues,
	): ?array
	{
		$this->getSearcher()->includeActivityFile($activityType);

		if (!method_exists('CBP' . $activityType, 'getPropertiesDialogValues'))
		{
			return null;
		}

		$template = [[
			'Name' => $activityId,
			'Type' => $activityType,
			'Properties' => $currentValues,
		]];
		$parameters = [];
		$variables = [];
		$constants = [];
		$errors = [];

		try
		{
			$result = \CBPActivity::callStaticMethod(
				$activityType,
				'getPropertiesDialogValues',
				[
					$documentType,
					$activityId,
					&$template,
					&$parameters,
					&$variables,
					$currentValues,
					&$errors,
					$constants,
				],
			);
		}
		catch (\Throwable)
		{
			return null;
		}

		if ($result === false || $errors !== [])
		{
			return null;
		}

		$activity = &\CBPWorkflowTemplateLoader::findActivityByName($template, $activityId);

		return is_array($activity['Properties'] ?? null) ? $activity['Properties'] : null;
	}

	/**
	 * Resolves the node type from the activity description (NODE_TYPE / complex-wrapper class), not from
	 * the class-name suffix: complex nodes (NODE_TYPE=COMPLEX, a BaseComplexActivity subclass) become
	 * {@see NodeType::Complex}; triggers and everything else keep their previous Trigger/Simple mapping.
	 *
	 * A frame overlay is recognised first, but only for the REST agent ($source) and by the shared
	 * {@see FrameBlockMatcher} predicate (EmptyBlockActivity + preset FRAME) - the exact identity the input
	 * validator uses, so the converter and the validator never disagree on what a frame is. Matching by that
	 * predicate rather than by the raw NODE_TYPE is deliberate: EmptyBlockActivity's NODE_TYPE=frame is
	 * collapsed to SIMPLE by ActivityRegistry::getNodeType, and only the FRAME-preset variant is a real frame.
	 */
	private function makeNodeType(
		string $agentBlockType,
		?string $presetId = null,
		RequestSource $source = RequestSource::Marta,
	): NodeType
	{
		if ($source === RequestSource::Rest && FrameBlockMatcher::matches($agentBlockType, $presetId))
		{
			return NodeType::Frame;
		}

		$registry = $this->getActivityRegistry();

		if ($registry->isComplexWrapper($agentBlockType))
		{
			return NodeType::Complex;
		}

		return match ($registry->getNodeType($agentBlockType))
		{
			ActivityNodeType::TRIGGER => NodeType::Trigger,
			default => NodeType::Simple,
		};
	}

	private function makeActivityId(string $agentBlockId): string
	{
		return is_numeric($agentBlockId) ? "MartaGeneratedId-{$this->uniqueBlockSalt}-{$agentBlockId}" : $agentBlockId;
	}

	public function convertDraftToAgentTemplate(Draft $draft): AgentTemplate
	{
		return new AgentTemplate(
			blocks: $this->convertToAgentBlocks($draft->blocks),
			connections: $this->convertToAgentConnections($draft->connections),
		);
	}

	private function convertToAgentBlocks(BlockCollection $blocks): AgentBlockCollection
	{
		$agentBlocks = new AgentBlockCollection();
		foreach ($blocks as $block)
		{
			$agentBlocks->add($this->convertToAgentBlock($block));
		}

		return $agentBlocks;
	}

	private function convertToAgentBlock(Block $block): AgentBlock
	{
		$properties = $block->activityData->properties;

		return new AgentBlock(
			type: $block->activityData->type,
			// Пользовательское имя — из Properties.Title (не из системного node.title). Механика та же, что
			// в прод-обратном пути (AiAssistantWorkflowTemplateConverterService), но нормализация против
			// системного имени тут не нужна: прямой путь пишет Properties.Title только как настоящий
			// override. Ручной шаблон кладёт туда и системное имя, поэтому прод-reverse его нормализует.
			title: (string)($properties[self::TITLE_PROPERTY_NAME] ?? ''),
			id: $block->id,
			settings: $this->convertToAgentSettings($block),
			description: $this->extractDescription($properties),
			// The saved PresetId is the first source: the forward path stamps it on the activity exactly as the
			// manual editor does. Recovering it from Properties.Document stays the fallback for nodes saved
			// before the field was written - a preset applying no Document is not recoverable that way at all.
			presetId: $this->normalizePresetId($block->activityData->presetId)
				?? $this->metadataResolver->resolvePresetId(
					$block->activityData->type,
					$this->extractDocument($properties),
				),
		);
	}

	private function convertToAgentSettings(Block $block): AgentSettingCollection
	{
		$settings = new AgentSettingCollection();

		foreach ($block->activityData->properties as $name => $value)
		{
			// Title и EditorComment уходят агенту отдельными полями (title/description),
			// поэтому не дублируются в общей куче настроек блока.
			if ($name === self::TITLE_PROPERTY_NAME || $name === self::COMMENT_PROPERTY_NAME)
			{
				continue;
			}

			$settings->add(new AgentSetting(
				name: $name,
				value: $value,
			));
		}

		return $settings;
	}

	private function convertToAgentConnections(ConnectionCollection $connections): AgentConnectionCollection
	{
		$agentConnections = new AgentConnectionCollection();
		foreach ($connections as $connection)
		{
			$agentConnections->add($this->convertToAgentConnection($connection));
		}

		return $agentConnections;
	}

	private function convertToAgentConnection(Connection $connection): AgentConnection
	{
		return new AgentConnection(
			destinationBlockId: $connection->targetBlockId,
			sourceBlockId: $connection->sourceBlockId,
			sourcePortId: $connection->sourcePortId !== '' ? $connection->sourcePortId : null,
			targetPortId: $connection->targetPortId !== '' ? $connection->targetPortId : null,
		);
	}
}

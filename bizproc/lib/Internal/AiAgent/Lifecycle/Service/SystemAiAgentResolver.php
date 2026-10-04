<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\AiAgent\Lifecycle\Service;

use Bitrix\Bizproc\Activity\Enum\ActivityType;
use Bitrix\Bizproc\Api\Enum\Template\WorkflowTemplateSection;
use Bitrix\Bizproc\Api\Enum\Template\WorkflowTemplateType;
use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Internal\AiAgent\Lifecycle\ValueObject\ManagedAgentIdentity;
use Bitrix\Bizproc\Internal\Entity\AiAgent\ManagedAgentResourceType;
use Bitrix\Bizproc\Internal\Service\AiAgentGrid\Version\TemplateRevisionService;
use Bitrix\Bizproc\Public\Command\AiAgent\Dto\SystemAiAgentActivationParameters;
use Bitrix\Bizproc\Public\Service\AiAgent\NodeAvailabilityServiceInterface;
use Bitrix\Bizproc\Public\Service\AiAgent\RegionAvailabilityServiceInterface;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateSectionTable;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Resolves a system code into the one installed system AI agent template that may be activated
 * programmatically, and describes the resources its graph is able to produce.
 *
 * The source must be a Nodes template of the AI_AGENT section with a system code and without an activation
 * timestamp, so a user template, a launched copy and a template of another section or type are rejected.
 * Exactly one source has to be found: a missing or a duplicated system code both mean the agent is not
 * available and are never resolved by row order. Being hidden in the user grid does not restrict this API,
 * therefore any installed system template counts, including one delivered by an external nodes directory.
 *
 * The system code is not re-validated here: it arrives already checked inside {@see ManagedAgentIdentity},
 * which is built before the catalog is touched, and is compared with the stored value by strict equality in
 * PHP, so the result does not depend on the collation of the database or on an implicit conversion.
 *
 * The resolution also confirms that the agent may run at all: the portal region and the AI nodes are
 * available, every activity and trigger of the graph is installed, and every activation declaration carries
 * a usable name and a resolvable field type. Codes of the agents themselves are never hardcoded.
 *
 * Resource producers are derived from the graph through the type descriptors of activities and triggers, not
 * from conditions on the system code. Two facts are returned: {@see self::DATA_PRODUCERS} - the normalized
 * producers with a resource type, its synchronicity and the way ownership is proven, and
 * {@see self::DATA_NODE_TYPES} - every distinct node type of the graph. The capability contract of P2 needs
 * both: the first to match a producer with a cleanup participant, the second to recognize a node type its
 * own policy does not know.
 */
final class SystemAiAgentResolver
{
	public const ERROR_AGENT_NOT_AVAILABLE = 'AI_AGENT_SOURCE_NOT_AVAILABLE';

	public const REASON_REGION_UNAVAILABLE = 'region_unavailable';

	public const REASON_NODE_UNAVAILABLE = 'node_unavailable';

	public const REASON_SOURCE_NOT_RESOLVED = 'source_not_resolved';

	public const REASON_DEPENDENCY_MISSING = 'dependency_missing';

	public const REASON_ACTIVATION_SCHEMA_INVALID = 'activation_schema_invalid';

	public const DATA_TEMPLATE_ID = 'templateId';

	public const DATA_SYSTEM_CODE = 'systemCode';

	public const DATA_DOCUMENT_TYPE = 'documentType';

	public const DATA_INSTALLED_REVISION = 'installedRevision';

	public const DATA_SECTIONS = 'sections';

	public const DATA_PRODUCERS = 'producers';

	public const DATA_NODE_TYPES = 'nodeTypes';

	public const SECTION_DECLARATIONS = 'declarations';

	public const SECTION_FIELD = 'field';

	public const SECTION_MAX_SERIALIZED_LENGTH = 'maxSerializedLength';

	public const PRODUCER_NODE_TYPE = 'nodeType';

	public const PRODUCER_RESOURCE_TYPE = 'resourceType';

	public const PRODUCER_SYNCHRONOUS = 'synchronous';

	public const PRODUCER_OWNERSHIP_PROOF = 'ownershipProof';

	/**
	 * Schedules of the managed copy prove that a schedule row still belongs to the instance.
	 */
	public const PROOF_TEMPLATE_SCHEDULE = 'template_schedule';

	/**
	 * An active instance of the managed copy proves that a registered workflow is still alive.
	 */
	public const PROOF_WORKFLOW_INSTANCE = 'workflow_instance';

	/**
	 * A bot code reserved before the start proves ownership together with the id of the bot first seen under it,
	 * which is pinned by the cleanup and has to match still.
	 */
	public const PROOF_RESERVED_BOT_CODE = 'reserved_bot_code';

	/**
	 * Rows of the shared storage that carry the template id of the managed copy prove ownership of the data.
	 */
	public const PROOF_TEMPLATE_STORAGE_RECORDS = 'template_storage_records';

	/**
	 * Declaration source of every activation section: the field of the system template that declares its
	 * names and the standard limit of that field once the effective values are written into the copy.
	 *
	 * A section introduced later is one more row here, so neither the resolver nor
	 * {@see SystemAiAgentConfigurationResolver} names sections one by one.
	 */
	private const SECTIONS = [
		SystemAiAgentActivationParameters::SECTION_PARAMETERS => [
			self::SECTION_FIELD => 'PARAMETERS',
			self::SECTION_MAX_SERIALIZED_LENGTH => \CBPWorkflowTemplateLoader::MAX_PARAMETERS_LENGTH,
		],
		SystemAiAgentActivationParameters::SECTION_CONSTANTS => [
			self::SECTION_FIELD => 'CONSTANTS',
			self::SECTION_MAX_SERIALIZED_LENGTH => \CBPWorkflowTemplateLoader::MAX_CONSTANTS_LENGTH,
		],
	];

	/**
	 * Node types that own a durable resource, described as [resource type, synchronicity, ownership proof].
	 *
	 * Synchronicity tells whether the resource appears inside the lifecycle of a registered workflow; a
	 * producer that is not synchronous needs a direct creation barrier instead. A trigger additionally owns
	 * the workflows it starts, which is added by {@see self::describeProducers()} for every trigger type.
	 *
	 * A node type absent here and absent from {@see SystemAiAgentCapabilityValidator::UNSUPPORTED_NODE_TYPES} is
	 * inert by the policy of the gate; the node types of the agents this module ships are walked by a test of
	 * that policy, so a producer added later cannot stay unclassified silently.
	 */
	private const DURABLE_RESOURCE_PRODUCERS = [
		'ScheduledTrigger' => [
			[ManagedAgentResourceType::Schedule, false, self::PROOF_TEMPLATE_SCHEDULE],
		],
		'ImBotCreateBotActivity' => [
			[ManagedAgentResourceType::BizprocBot, true, self::PROOF_RESERVED_BOT_CODE],
		],
		'ImOpenLinesBotSettingsActivity' => [
			[ManagedAgentResourceType::OpenLinesBot, true, self::PROOF_RESERVED_BOT_CODE],
		],
		'CreateStorageNode' => [
			[ManagedAgentResourceType::StorageScope, true, self::PROOF_TEMPLATE_STORAGE_RECORDS],
		],
		'WriteDataStorageActivity' => [
			[ManagedAgentResourceType::StorageScope, true, self::PROOF_TEMPLATE_STORAGE_RECORDS],
		],
	];

	private const NAME_PATTERN = '/^[A-Za-z0-9_]+$/D';

	private readonly RegionAvailabilityServiceInterface $regionAvailabilityService;

	private readonly NodeAvailabilityServiceInterface $nodeAvailabilityService;

	public function __construct(
		?RegionAvailabilityServiceInterface $regionAvailabilityService = null,
		?NodeAvailabilityServiceInterface $nodeAvailabilityService = null,
		private readonly TemplateRevisionService $templateRevisionService = new TemplateRevisionService(),
	)
	{
		$locator = ServiceLocator::getInstance();

		$this->regionAvailabilityService = $regionAvailabilityService
			?? $locator->get(RegionAvailabilityServiceInterface::class)
		;
		$this->nodeAvailabilityService = $nodeAvailabilityService
			?? $locator->get(NodeAvailabilityServiceInterface::class)
		;
	}

	/**
	 * Resolves the source of the identity, or fails with {@see self::ERROR_AGENT_NOT_AVAILABLE} and a stable
	 * reason in the custom data of the error. No caller value is carried by a rejection.
	 *
	 * Successful data:
	 * <ul>
	 * <li> templateId: int, id of the installed system template
	 * <li> systemCode: string, exactly as stored
	 * <li> documentType: array{0: string, 1: string, 2: string}, complex document type of the source
	 * <li> installedRevision: string, revision of the source logic, which the copy stores as its installed one
	 * <li> sections: array&lt;string, array{declarations: array, field: string, maxSerializedLength: int}&gt;
	 * <li> producers: list&lt;array{nodeType: string, resourceType: ManagedAgentResourceType,
	 *      synchronous: bool, ownershipProof: string}&gt;
	 * <li> nodeTypes: array&lt;string, bool&gt;, node type of the graph =&gt; whether it is a trigger
	 * </ul>
	 */
	public function resolve(ManagedAgentIdentity $identity): Result
	{
		if (!$this->regionAvailabilityService->isAvailable())
		{
			return self::fail(self::REASON_REGION_UNAVAILABLE);
		}

		if (!$this->nodeAvailabilityService->isAvailable())
		{
			return self::fail(self::REASON_NODE_UNAVAILABLE);
		}

		$systemCode = $identity->getSystemCode();

		$templateId = $this->findSourceTemplateId($systemCode);
		if ($templateId === null)
		{
			return self::fail(self::REASON_SOURCE_NOT_RESOLVED);
		}

		$row = $this->loadSourceRow($templateId);
		if ($row === null || $row['SYSTEM_CODE'] !== $systemCode)
		{
			return self::fail(self::REASON_SOURCE_NOT_RESOLVED);
		}

		$template = is_array($row['TEMPLATE']) ? $row['TEMPLATE'] : [];
		if ($template === [])
		{
			return self::fail(self::REASON_SOURCE_NOT_RESOLVED);
		}

		if (!\CBPWorkflowTemplateLoader::checkTemplateActivities($template))
		{
			return self::fail(self::REASON_DEPENDENCY_MISSING);
		}

		$nodeTypes = $this->collectNodeTypes($template);
		if ($nodeTypes === null)
		{
			return self::fail(self::REASON_DEPENDENCY_MISSING);
		}

		$documentType = [
			(string)$row['MODULE_ID'],
			(string)$row['ENTITY'],
			(string)$row['DOCUMENT_TYPE'],
		];

		$sections = $this->describeSections($row, $documentType);
		if ($sections === null)
		{
			return self::fail(self::REASON_ACTIVATION_SCHEMA_INVALID);
		}

		return (new Result())->setData([
			self::DATA_TEMPLATE_ID => $templateId,
			self::DATA_SYSTEM_CODE => $systemCode,
			self::DATA_DOCUMENT_TYPE => $documentType,
			self::DATA_INSTALLED_REVISION => $this->templateRevisionService->calculateRevision($template),
			self::DATA_SECTIONS => $sections,
			self::DATA_PRODUCERS => self::describeProducers($nodeTypes),
			self::DATA_NODE_TYPES => $nodeTypes,
		]);
	}

	/**
	 * Id of the only copyable system template that carries the code, or null when there is none or more
	 * than one. Letter case is left to the database here on purpose: a case insensitive collation returns
	 * every variant of the code, which is a duplicate and is rejected, while the exact match of the
	 * remaining single row is confirmed in PHP by the caller.
	 */
	private function findSourceTemplateId(string $systemCode): ?int
	{
		$rows = WorkflowTemplateSectionTable::query()
			->setSelect(['TEMPLATE_ID'])
			->where('SECTION_ID', WorkflowTemplateSection::AiAgent->value)
			->where('TEMPLATE.TYPE', WorkflowTemplateType::Nodes->value)
			->where('TEMPLATE.SYSTEM_CODE', $systemCode)
			->whereNull('TEMPLATE.ACTIVATED_AT')
			->setLimit(3)
			->fetchAll()
		;

		$templateIds = [];
		foreach ($rows as $row)
		{
			$templateId = (int)$row['TEMPLATE_ID'];
			if ($templateId > 0)
			{
				$templateIds[$templateId] = $templateId;
			}
		}

		if (count($templateIds) !== 1)
		{
			return null;
		}

		return (int)array_key_first($templateIds);
	}

	/**
	 * @return array{SYSTEM_CODE: ?string, MODULE_ID: ?string, ENTITY: ?string, DOCUMENT_TYPE: ?string,
	 *  TEMPLATE: mixed, PARAMETERS: mixed, CONSTANTS: mixed}|null
	 */
	private function loadSourceRow(int $templateId): ?array
	{
		$row = WorkflowTemplateTable::query()
			->setSelect(['SYSTEM_CODE', 'MODULE_ID', 'ENTITY', 'DOCUMENT_TYPE', 'TEMPLATE', 'PARAMETERS', 'CONSTANTS'])
			->where('ID', $templateId)
			->setLimit(1)
			->fetch()
		;

		return $row === false ? null : $row;
	}

	/**
	 * Declaration source of every section with its checked declarations, or null when a declaration cannot
	 * be used for a programmatic activation: its name is not a usable field name, it declares no type or the
	 * type class is not resolvable, which happens when the module owning the type is not installed.
	 *
	 * @return array<string, array{declarations: array, field: string, maxSerializedLength: int}>|null
	 */
	private function describeSections(array $row, array $documentType): ?array
	{
		$documentService = \CBPRuntime::getRuntime()->getDocumentService();

		$sections = [];
		foreach (self::SECTIONS as $name => $source)
		{
			$raw = $row[$source[self::SECTION_FIELD]] ?? null;
			$declarations = [];

			foreach (is_array($raw) ? $raw : [] as $declarationName => $declaration)
			{
				$declarationName = (string)$declarationName;
				if (preg_match(self::NAME_PATTERN, $declarationName) !== 1)
				{
					return null;
				}

				$normalized = FieldType::normalizeProperty($declaration);
				if ((string)$normalized['Type'] === '')
				{
					return null;
				}

				if ($documentService->getFieldTypeObject($documentType, $normalized) === null)
				{
					return null;
				}

				$declarations[$declarationName] = is_array($declaration) ? $declaration : $normalized;
			}

			$sections[$name] = $source + [self::SECTION_DECLARATIONS => $declarations];
		}

		return $sections;
	}

	/**
	 * Distinct node types of the graph mapped to whether the type is a trigger, or null when the portal has
	 * no usable descriptor for a node type of the graph.
	 *
	 * @return array<string, bool>|null
	 */
	private function collectNodeTypes(array $template): ?array
	{
		$runtime = \CBPRuntime::getRuntime();

		$nodeTypes = [];
		foreach (self::walkGraph($template) as $nodeType)
		{
			if (array_key_exists($nodeType, $nodeTypes))
			{
				continue;
			}

			$description = $runtime->getActivityDescription($nodeType);
			if ($description === null || !empty($description['EXCLUDED']))
			{
				return null;
			}

			$nodeTypes[$nodeType] = self::isTriggerType($nodeType, $description);
		}

		return $nodeTypes;
	}

	/**
	 * Whether the node type is a trigger, hence a producer of the workflows it starts.
	 *
	 * The implementation of the type is the primary signal: the module itself collects the triggers of a
	 * template by the {@see \IBPTriggerActivity} contract, and the activity file of every node type is
	 * already loaded by the dependency check. Not every trigger declares its kind in the descriptor, so the
	 * declared kind only completes the answer instead of replacing it.
	 */
	private static function isTriggerType(string $nodeType, array $description): bool
	{
		if (is_subclass_of('CBP' . $nodeType, \IBPTriggerActivity::class))
		{
			return true;
		}

		$declaredTypes = is_array($description['TYPE'] ?? null) ? $description['TYPE'] : [];

		return in_array(ActivityType::TRIGGER->value, $declaredTypes, true);
	}

	/**
	 * Yields the type of every node of the graph, including triggers, which are stored among the children
	 * of the root activity.
	 *
	 * @return \Generator<string>
	 */
	private static function walkGraph(array $nodes): \Generator
	{
		foreach ($nodes as $node)
		{
			if (!is_array($node))
			{
				continue;
			}

			$nodeType = $node['Type'] ?? null;
			if (is_string($nodeType) && $nodeType !== '')
			{
				yield $nodeType;
			}

			if (is_array($node['Children'] ?? null))
			{
				yield from self::walkGraph($node['Children']);
			}
		}
	}

	/**
	 * @param array<string, bool> $nodeTypes
	 * @return list<array{nodeType: string, resourceType: ManagedAgentResourceType, synchronous: bool,
	 *  ownershipProof: string}>
	 */
	private static function describeProducers(array $nodeTypes): array
	{
		$producers = [];
		foreach ($nodeTypes as $nodeType => $isTrigger)
		{
			$contracts = self::DURABLE_RESOURCE_PRODUCERS[$nodeType] ?? [];
			if ($isTrigger)
			{
				$contracts[] = [ManagedAgentResourceType::Workflow, false, self::PROOF_WORKFLOW_INSTANCE];
			}

			foreach ($contracts as [$resourceType, $synchronous, $ownershipProof])
			{
				$producers[] = [
					self::PRODUCER_NODE_TYPE => (string)$nodeType,
					self::PRODUCER_RESOURCE_TYPE => $resourceType,
					self::PRODUCER_SYNCHRONOUS => $synchronous,
					self::PRODUCER_OWNERSHIP_PROOF => $ownershipProof,
				];
			}
		}

		return $producers;
	}

	private static function fail(string $reason): Result
	{
		return (new Result())->addError(
			new Error('System AI agent source is not available', self::ERROR_AGENT_NOT_AVAILABLE, [
				'reason' => $reason,
			]),
		);
	}
}

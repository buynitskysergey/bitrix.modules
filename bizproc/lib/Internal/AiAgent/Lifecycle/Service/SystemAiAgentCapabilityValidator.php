<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\AiAgent\Lifecycle\Service;

use Bitrix\Bizproc\Internal\AiAgent\Lifecycle\Cleanup\ManagedResourceCleanupInterface;
use Bitrix\Bizproc\Internal\Entity\AiAgent\ManagedAgentResourceType;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable;
use Bitrix\Main\ArgumentTypeException;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Confirms that every durable resource the graph of a system AI agent can produce is covered by a resource type
 * of the module, by a protection against a late creation and by a repeatable cleanup participant.
 *
 * The check runs before any side effect: before the managed instance, the copy of the template and the first
 * resource row exist, so an agent whose graph cannot be removed completely is never enabled at all.
 *
 * Two facts of the resolved source are used, because neither of them answers the question alone. The ownership
 * contracts describe the producers the module knows: each of them is matched with a cleanup participant of its
 * resource type, with the way its ownership is proven and, when the resource is not bounded by the lifecycle of
 * a registered workflow, with a direct creation barrier. The node types of the graph are then walked against the
 * policy of {@see self::UNSUPPORTED_NODE_TYPES}: only a producer owns an ownership contract, therefore the
 * absence of a contract cannot be read as an unsupported resource on its own, and a node type known to produce
 * work outside these contracts has to be named to be refused. Every remaining node type is inert.
 *
 * A produced bot is owned through the code reserved before the start, therefore the code has to be computable
 * before the start and unique for the copy of the template: a code built from a foreign reference or without
 * {@see self::COPY_UNIQUE_REFERENCE} cannot be reserved or would be shared with another context, and both are
 * unsupported. A bot that already exists is an external dependency of the agent: it is never owned and never
 * removed by this API.
 */
final class SystemAiAgentCapabilityValidator
{
	public const ERROR_UNSUPPORTED_RESOURCE = 'AI_AGENT_UNSUPPORTED_PRODUCER';

	public const ERROR_SOURCE_UNREADABLE = 'AI_AGENT_SOURCE_UNREADABLE';

	public const CUSTOM_DATA_REASON = 'reason';

	public const REASON_MISSING_PARTICIPANT = 'missing_cleanup_participant';

	public const REASON_UNPROVABLE_OWNERSHIP = 'unprovable_ownership';

	public const REASON_LATE_CREATION_UNPROTECTED = 'late_creation_unprotected';

	public const REASON_ARBITRARY_CODE = 'arbitrary_code_producer';

	public const REASON_FOREIGN_LAUNCHER = 'foreign_launcher';

	public const REASON_BOT_CODE_NOT_RESERVABLE = 'bot_code_not_reservable';

	public const REASON_BOT_CODE_DYNAMIC = 'bot_code_dynamic';

	public const REASON_BOT_CODE_NOT_ISOLATED = 'bot_code_not_isolated';

	/**
	 * The only reference a produced bot code may carry: it is known before the start, because the copy already
	 * exists by then, and it differs for every copy, so two contexts of one agent never share a bot.
	 *
	 * The reservation of the code substitutes the reference with the id of the copy.
	 */
	public const COPY_UNIQUE_REFERENCE = '{=Workflow:TemplateId}';

	/**
	 * Node types that own or change a durable lifecycle resource this API cannot compensate repeatably.
	 *
	 * Arbitrary code may produce anything, so its resources cannot be described at all; a node that starts a
	 * workflow of another template or a mass script keeps launching after the agent is removed, and the produced
	 * work belongs to a template this instance does not own. An application result of an already finished
	 * workflow is not such a resource and is not listed here.
	 *
	 * The policy of this list and of {@see SystemAiAgentResolver::DURABLE_RESOURCE_PRODUCERS} together means that
	 * every node type outside them is inert. A node type reaches the gate only through the graph of an installed
	 * agent, therefore the node types of the agents this module ships are walked by a test of the policy: one
	 * appearing there without a classification fails the build instead of passing the gate unnoticed.
	 */
	private const UNSUPPORTED_NODE_TYPES = [
		'CodeActivity' => self::REASON_ARBITRARY_CODE,
		'CodeCondition' => self::REASON_ARBITRARY_CODE,
		'StartWorkflowActivity' => self::REASON_FOREIGN_LAUNCHER,
		'StartScriptActivity' => self::REASON_FOREIGN_LAUNCHER,
	];

	private const BOT_CODE_PROPERTY = 'botCode';

	private const BOT_CODE_LITERAL_PATTERN = '/^[A-Za-z0-9_.-]*$/D';

	private const MAX_BOT_CODE_LENGTH = 128;

	private const REFERENCE_OPENING = '{=';

	/**
	 * @var array<string, true> resource type value => a cleanup participant of that type is registered
	 */
	private array $supportedTypes = [];

	/**
	 * @param iterable<ManagedResourceCleanupInterface> $cleanupParticipants the very participants a removal pass
	 *  uses, so a resource type is supported here exactly while it can be cleaned up there
	 * @throws ArgumentTypeException
	 */
	public function __construct(iterable $cleanupParticipants)
	{
		foreach ($cleanupParticipants as $participant)
		{
			if (!$participant instanceof ManagedResourceCleanupInterface)
			{
				throw new ArgumentTypeException('cleanupParticipants', ManagedResourceCleanupInterface::class);
			}

			$this->supportedTypes[$participant->type()->value] = true;
		}
	}

	/**
	 * Confirms the capability contract of the resolved source, or fails with a stable internal code and a stable
	 * reason in the custom data of the error. No value of the caller is carried by a rejection.
	 *
	 * @param array $source data of {@see SystemAiAgentResolver::resolve()}
	 */
	public function validate(array $source): Result
	{
		$nodeTypes = $source[SystemAiAgentResolver::DATA_NODE_TYPES] ?? null;
		$producers = $source[SystemAiAgentResolver::DATA_PRODUCERS] ?? null;
		if (!is_array($nodeTypes) || $nodeTypes === [] || !is_array($producers))
		{
			return self::fail(self::ERROR_SOURCE_UNREADABLE);
		}

		$refusal = $this->validateProducers($producers);
		if ($refusal !== null)
		{
			return $refusal;
		}

		$refusal = self::validateNodeTypes($nodeTypes);
		if ($refusal !== null)
		{
			return $refusal;
		}

		$templateId = (int)($source[SystemAiAgentResolver::DATA_TEMPLATE_ID] ?? 0);
		$refusal = self::validateBotCodes($templateId, $producers);

		return $refusal ?? new Result();
	}

	/**
	 * Refusal of the first producer whose ownership contract is not supported, or null when every contract holds.
	 *
	 * A producer is supported only while it is synchronously bounded by the lifecycle of a registered workflow
	 * or has a direct creation barrier, and while its resource type has both a way to prove ownership and a
	 * cleanup participant.
	 */
	private function validateProducers(array $producers): ?Result
	{
		foreach ($producers as $producer)
		{
			$type = is_array($producer) ? ($producer[SystemAiAgentResolver::PRODUCER_RESOURCE_TYPE] ?? null) : null;
			if (!$type instanceof ManagedAgentResourceType)
			{
				return self::reject(self::REASON_MISSING_PARTICIPANT);
			}

			if (!isset($this->supportedTypes[$type->value]))
			{
				return self::reject(self::REASON_MISSING_PARTICIPANT);
			}

			$proof = $producer[SystemAiAgentResolver::PRODUCER_OWNERSHIP_PROOF] ?? null;
			if ($proof !== self::describeOwnershipProof($type))
			{
				return self::reject(self::REASON_UNPROVABLE_OWNERSHIP);
			}

			$synchronous = ($producer[SystemAiAgentResolver::PRODUCER_SYNCHRONOUS] ?? null) === true;
			if (!$synchronous && !self::hasDirectCreationBarrier($type))
			{
				return self::reject(self::REASON_LATE_CREATION_UNPROTECTED);
			}
		}

		return null;
	}

	/**
	 * Refusal of the first node type that owns or changes a durable lifecycle resource this API cannot
	 * compensate, or null when every node type outside the ownership contracts is inert.
	 *
	 * The refusal is unconditional: a node type of {@see self::UNSUPPORTED_NODE_TYPES} produces work no single
	 * ownership contract can describe, therefore a contract of the same node type would not make it supported.
	 */
	private static function validateNodeTypes(array $nodeTypes): ?Result
	{
		foreach (array_keys($nodeTypes) as $nodeType)
		{
			$reason = self::UNSUPPORTED_NODE_TYPES[(string)$nodeType] ?? null;
			if ($reason !== null)
			{
				return self::reject($reason);
			}
		}

		return null;
	}

	/**
	 * Refusal of the first produced bot code that cannot be reserved before the start or would not be unique for
	 * the copy, or null when the graph produces no bot at all.
	 */
	private static function validateBotCodes(int $templateId, array $producers): ?Result
	{
		$botNodeTypes = self::collectBotNodeTypes($producers);
		if ($botNodeTypes === [])
		{
			return null;
		}

		try
		{
			$template = self::loadTemplateGraph($templateId);
		}
		catch (\Throwable)
		{
			return self::fail(self::ERROR_SOURCE_UNREADABLE);
		}

		if ($template === null)
		{
			return self::fail(self::ERROR_SOURCE_UNREADABLE);
		}

		foreach (self::walkGraph($template) as [$nodeType, $properties])
		{
			if (!isset($botNodeTypes[$nodeType]))
			{
				continue;
			}

			$refusal = self::checkBotCode($properties[self::BOT_CODE_PROPERTY] ?? null);
			if ($refusal !== null)
			{
				return $refusal;
			}
		}

		return null;
	}

	/**
	 * @return array<string, true> node types whose ownership contract is a reserved bot code
	 */
	private static function collectBotNodeTypes(array $producers): array
	{
		$botNodeTypes = [];
		foreach ($producers as $producer)
		{
			if (!is_array($producer))
			{
				continue;
			}

			$type = $producer[SystemAiAgentResolver::PRODUCER_RESOURCE_TYPE] ?? null;
			$nodeType = $producer[SystemAiAgentResolver::PRODUCER_NODE_TYPE] ?? null;
			if ($type instanceof ManagedAgentResourceType && is_string($nodeType) && self::ownsBot($type))
			{
				$botNodeTypes[$nodeType] = true;
			}
		}

		return $botNodeTypes;
	}

	/**
	 * Refusal of a bot code that cannot be owned, or null when the code may be reserved before the start.
	 */
	private static function checkBotCode(mixed $code): ?Result
	{
		if (is_array($code))
		{
			$code = reset($code);
		}

		if (!is_string($code) || trim($code) === '' || strlen($code) > self::MAX_BOT_CODE_LENGTH)
		{
			return self::reject(self::REASON_BOT_CODE_NOT_RESERVABLE);
		}

		if (!str_contains($code, self::COPY_UNIQUE_REFERENCE))
		{
			return self::reject(self::REASON_BOT_CODE_NOT_ISOLATED);
		}

		$literal = str_replace(self::COPY_UNIQUE_REFERENCE, '', $code);
		if (str_contains($literal, self::REFERENCE_OPENING))
		{
			return self::reject(self::REASON_BOT_CODE_DYNAMIC);
		}

		if (preg_match(self::BOT_CODE_LITERAL_PATTERN, $literal) !== 1)
		{
			return self::reject(self::REASON_BOT_CODE_NOT_RESERVABLE);
		}

		return null;
	}

	private static function ownsBot(ManagedAgentResourceType $type): bool
	{
		return $type === ManagedAgentResourceType::BizprocBot
			|| $type === ManagedAgentResourceType::OpenLinesBot
		;
	}

	/**
	 * Way the ownership of a resource type is proven while the registry is reconciled with the actual tables.
	 *
	 * The match is exhaustive on purpose: a resource type added without a proof fails loudly instead of being
	 * accepted with an arbitrary one.
	 */
	private static function describeOwnershipProof(ManagedAgentResourceType $type): string
	{
		return match ($type)
		{
			ManagedAgentResourceType::Schedule => SystemAiAgentResolver::PROOF_TEMPLATE_SCHEDULE,
			ManagedAgentResourceType::Workflow => SystemAiAgentResolver::PROOF_WORKFLOW_INSTANCE,
			ManagedAgentResourceType::BizprocBot,
			ManagedAgentResourceType::OpenLinesBot => SystemAiAgentResolver::PROOF_RESERVED_BOT_CODE,
			ManagedAgentResourceType::StorageScope => SystemAiAgentResolver::PROOF_TEMPLATE_STORAGE_RECORDS,
		};
	}

	/**
	 * Whether a resource of this type passes a barrier of its own before it appears, which is what a producer
	 * outside the lifecycle of a registered workflow requires.
	 *
	 * A workflow is registered by the creation event before its state exists, a schedule row inside the
	 * transaction that saves it. The remaining types appear synchronously inside a registered workflow or are
	 * written by the operation itself, therefore they have no barrier of their own.
	 */
	private static function hasDirectCreationBarrier(ManagedAgentResourceType $type): bool
	{
		return match ($type)
		{
			ManagedAgentResourceType::Schedule,
			ManagedAgentResourceType::Workflow => true,
			ManagedAgentResourceType::BizprocBot,
			ManagedAgentResourceType::OpenLinesBot,
			ManagedAgentResourceType::StorageScope => false,
		};
	}

	private static function loadTemplateGraph(int $templateId): ?array
	{
		if ($templateId <= 0)
		{
			return null;
		}

		$row = WorkflowTemplateTable::query()
			->setSelect(['TEMPLATE'])
			->where('ID', $templateId)
			->setLimit(1)
			->fetch()
		;

		if ($row === false || !is_array($row['TEMPLATE'] ?? null) || $row['TEMPLATE'] === [])
		{
			return null;
		}

		return $row['TEMPLATE'];
	}

	/**
	 * Yields the type and the declared properties of every node of the graph, including the triggers stored
	 * among the children of the root activity.
	 *
	 * @return \Generator<array{0: string, 1: array}>
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
				yield [$nodeType, is_array($node['Properties'] ?? null) ? $node['Properties'] : []];
			}

			if (is_array($node['Children'] ?? null))
			{
				yield from self::walkGraph($node['Children']);
			}
		}
	}

	private static function reject(string $reason): Result
	{
		return (new Result())->addError(
			new Error('Durable resource of the system AI agent is not supported', self::ERROR_UNSUPPORTED_RESOURCE, [
				self::CUSTOM_DATA_REASON => $reason,
			]),
		);
	}

	private static function fail(string $code): Result
	{
		return (new Result())->addError(new Error('System AI agent source cannot be verified', $code));
	}
}

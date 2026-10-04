<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\AiAgent\Lifecycle\Service;

use Bitrix\Bizproc\Internal\AiAgent\Lifecycle\ValueObject\ManagedAgentIdentity;
use Bitrix\Bizproc\Internal\AiAgent\Lifecycle\ValueObject\ManagedAgentResourcePayload;
use Bitrix\Bizproc\Internal\Container;
use Bitrix\Bizproc\Internal\Entity\AiAgent\ManagedAgentInstance;
use Bitrix\Bizproc\Internal\Entity\AiAgent\ManagedAgentInstanceState;
use Bitrix\Bizproc\Internal\Entity\AiAgent\ManagedAgentResource;
use Bitrix\Bizproc\Internal\Entity\AiAgent\ManagedAgentResourceType;
use Bitrix\Bizproc\Internal\Model\AiAgent\ManagedAgentInstanceTable;
use Bitrix\Bizproc\Internal\Model\AiAgent\ManagedAgentResourceTable;
use Bitrix\Bizproc\Internal\Repository\AiAgent\ManagedAgentInstanceRepositoryInterface;
use Bitrix\Bizproc\Internal\Repository\AiAgent\ManagedAgentResourceRepositoryInterface;
use Bitrix\Bizproc\Public\Command\AiAgent\Dto\SystemAiAgentContext;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Error;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\Result;
use Bitrix\Main\Security\Random;
use Bitrix\Main\SystemException;

/**
 * Creation barrier and registration point of the resources owned by managed system AI agent instances.
 *
 * A resource row is written only for a template of the managed registry, therefore every producer starts by
 * classifying its template: a managed copy, an existing unmanaged template or a template that is already gone.
 * Only the existing unmanaged template gets an immediate exit without any resource row. The classification of
 * one template is answered by a single indexed read and is remembered for the current request only, so the
 * repeated events of one template do not touch the database again and no persistent cache is introduced; the
 * lifecycle state, unlike the immutable identity, is always reread under the logical lock.
 *
 * The barrier decides whether a resource may appear at all:
 * <ul>
 * <li> enabled - the instance works normally and a resource of any supported type may be registered;
 * <li> enabling - the copy is not published yet, therefore a workflow may only start with the one time marker
 *      of the current operation, and any other producer has to run inside that operation;
 * <li> deleting and cleanup_pending - direct creation is refused, so nothing can appear between the final
 *      reconciliation and the removal of the service link.
 * </ul>
 *
 * A caller of the current lifecycle operation reuses the logical lock the orchestrator already holds and
 * announces it by {@see self::enterOperation()}; an external producer takes the very same lock itself, rereads
 * the instance and only then registers or refuses. If the first classification recognized a managed copy, an
 * instance that disappeared while the lock was awaited never turns the producer onto the unmanaged path: the
 * creation is refused instead, so a workflow is never left without an owner.
 *
 * Registering an already registered logical key is a safe operation and never inserts a second row. The
 * technical payload passes the closed schema of {@see ManagedAgentResourcePayload}, which owns it for both sides
 * of the row: it carries a version and the technical details of a repeatable cleanup only, never parameters,
 * constants, class names or callable handlers.
 */
final class ManagedAgentResourceRegistry
{
	/**
	 * Service code of the shared instance.
	 *
	 * The operation scope, the one time markers and the classification of a template live in the memory of one
	 * object for the current request, therefore every consumer resolves this one service instead of building an
	 * own registry: an event handler with a registry of its own would not see the operation of the orchestrator.
	 */
	public const SERVICE_CODE = 'bizproc.ai_agent.lifecycle.resource_registry';

	/**
	 * Logical lock of the identity: the prefix plus the Base64url identity hash is 59 ASCII characters, which
	 * fits the name limit of MySQL. The orchestrator of the operation locks the very same name.
	 */
	public const LOCK_NAME_PREFIX = 'bp_system_agent_';

	public const LOCK_TIMEOUT_SECONDS = 10;

	/**
	 * Workflow parameter that carries the one time marker of the current enabling operation.
	 *
	 * The marker is issued internally, is never accepted from a public DTO and is removed from the parameters
	 * of a managed template by {@see self::registerWorkflowCreation()} before the workflow is initialized, so
	 * it is never persisted. The parameters of a workflow of any other template are left untouched: the name
	 * is reserved for this module, but the event hands them over after the declared defaults are already in.
	 */
	public const PARAMETER_OPERATION_MARKER = '__ManagedAgentOperationMarker';

	/**
	 * Registered resource of a successful registration, absent when the template is not managed.
	 */
	public const DATA_RESOURCE = 'resource';

	public const ERROR_CREATION_REFUSED = 'AI_AGENT_RESOURCE_CREATION_REFUSED';

	public const ERROR_LOCK_CONFLICT = 'AI_AGENT_RESOURCE_LOCK_CONFLICT';

	public const ERROR_REGISTRY_FAILURE = 'AI_AGENT_RESOURCE_REGISTRY_FAILURE';

	public const ERROR_RESOURCE_DATA_INVALID = 'AI_AGENT_RESOURCE_DATA_INVALID';

	public const REASON_TEMPLATE_ABSENT = 'template_absent';

	public const REASON_INSTANCE_ABSENT = 'instance_absent';

	public const REASON_IDENTITY_UNUSABLE = 'identity_unusable';

	public const REASON_CLEANUP_IN_PROGRESS = 'cleanup_in_progress';

	public const REASON_START_NOT_AUTHORIZED = 'start_not_authorized';

	public const REASON_OPERATION_REQUIRED = 'operation_required';

	public const CUSTOM_DATA_REASON = 'reason';

	private const KIND_ABSENT = 'absent';

	private const KIND_UNMANAGED = 'unmanaged';

	private const KIND_MANAGED = 'managed';

	private const CLASSIFICATION_KIND = 'kind';

	private const CLASSIFICATION_INSTANCE_ID = 'instanceId';

	private const CLASSIFICATION_IDENTITY = 'identity';

	private const MODULE_ID = 'bizproc';

	/**
	 * Option that remembers the confirmed presence of both registry tables.
	 *
	 * The schema is asked about on the creation and on the completion path of every workflow of the portal, and
	 * two SHOW TABLES cost more than the indexed read they guard. A positive answer is a permanent property of
	 * the schema once the update has run, therefore it is stored and afterwards read from the options of the
	 * module the request has loaded anyway. The migration that creates the tables writes it itself
	 * (install/migrations/tables.php and the updater), so no request of the portal has to probe the schema and
	 * store the answer while it is starting a workflow. A negative answer is deliberately never stored: it is
	 * the state of the update window and has to disappear with the very first request that runs after the
	 * migration.
	 *
	 * A positive answer is never taken back either, and that is a decision and not an omission. Before the
	 * update, tables that are absent mean no managed instance can exist, hence an unmanaged template; after it,
	 * tables that are gone are an emergency, and the fail-closed answer of {@see self::classify()} is the
	 * intended one - self-healing back to "nothing is managed" would let a managed copy start workflows nobody
	 * owns. The option and the tables live in one database, so an ordinary restore brings them back together;
	 * the module also drops its tables and keeps the option only while it is uninstalled, where nothing of the
	 * registry runs at all and a reinstall writes both again. Removing the option by hand is what turns a
	 * restored portal back to the probe.
	 */
	private const SCHEMA_READY_OPTION = 'ai_agent_registry_schema_ready';

	private const OPTION_YES = 'Y';

	private const INSTANCE_JOIN = 'MANAGED_INSTANCE';

	private const MARKER_BYTE_LENGTH = 32;

	/**
	 * Same limit as the RESOURCE_ID column, so an id that cannot be stored is refused before the write.
	 */
	private const MAX_RESOURCE_ID_LENGTH = 128;

	private readonly ?ManagedAgentInstanceRepositoryInterface $instanceRepository;

	private readonly ?ManagedAgentResourceRepositoryInterface $resourceRepository;

	/**
	 * @var array<int, array{kind: string, instanceId: int, identity: ?ManagedAgentIdentity}>
	 */
	private array $classifications = [];

	/**
	 * @var bool|null answer of the schema check of the registry tables, kept for the current request only
	 */
	private ?bool $tablesExist = null;

	/**
	 * @var array<string, true> identity hash => the current operation holds the logical lock of that identity
	 */
	private array $heldOperations = [];

	/**
	 * @var array<string, true> identity hash => the logical lock is held by the request, without an operation
	 */
	private array $heldLocks = [];

	/**
	 * @var array<string, list<string>> identity hash => markers issued by the operation and not consumed yet
	 */
	private array $startMarkers = [];

	public function __construct(
		?ManagedAgentInstanceRepositoryInterface $instanceRepository = null,
		?ManagedAgentResourceRepositoryInterface $resourceRepository = null,
	)
	{
		$this->instanceRepository = $instanceRepository ?? Container::getManagedAgentInstanceRepository();
		$this->resourceRepository = $resourceRepository ?? Container::getManagedAgentResourceRepository();
	}

	/**
	 * Name of the logical lock both the orchestrator of the operation and the barrier of an external producer
	 * acquire, so the name is never spelled out twice.
	 */
	public static function buildLockName(ManagedAgentIdentity $identity): string
	{
		return self::LOCK_NAME_PREFIX . $identity->getBase64UrlHash();
	}

	/**
	 * Announces that the current lifecycle operation holds the logical lock of the identity, therefore the
	 * producers it calls reuse that lock instead of acquiring it again.
	 *
	 * Called by the orchestrator right after the lock is taken; {@see self::leaveOperation()} belongs to the
	 * same finally block that releases the lock.
	 */
	public function enterOperation(ManagedAgentIdentity $identity): void
	{
		$this->heldOperations[$identity->getHash()] = true;
	}

	/**
	 * Ends the operation scope and drops the markers it did not consume, because a marker is bound to the
	 * identity and to the operation that issued it.
	 */
	public function leaveOperation(ManagedAgentIdentity $identity): void
	{
		$hash = $identity->getHash();

		unset($this->heldOperations[$hash], $this->startMarkers[$hash]);
	}

	/**
	 * Takes the logical lock of the template of a managed copy up front, so that a producer writing several rows
	 * inside one transaction takes it once for the whole batch instead of once per row: every registration of
	 * that batch then sees the lock as held and asks the database for nothing.
	 *
	 * The batch therefore writes its rows under one lock, and the state of the instance cannot change between
	 * them. What this does not promise is a wait that holds no row locks: on the production path the producer
	 * runs inside a transaction its caller has already opened - the ORM events of a template save - so that
	 * transaction may hold row locks of its own before this method is reached. What is still gained there is the
	 * absence of a lock request between the writes of the batch; the wait before no row locks at all happens
	 * only when the producer is called outside a transaction.
	 *
	 * Unlike {@see self::enterOperation()} this opens no operation scope: the barrier of an enabling instance
	 * keeps refusing a producer that does not belong to the operation of the orchestrator. Answers the identity
	 * the lock was taken for, or null when there is nothing to lock, when the request holds the lock already, or
	 * when the lock could not be taken - the registration of the resource then answers the conflict on its own,
	 * exactly as it does without this call. {@see self::releaseTemplateLock()} belongs to the finally block of
	 * the caller.
	 */
	public function acquireTemplateLock(int $templateId): ?ManagedAgentIdentity
	{
		$classification = $this->classify($templateId);
		if ($classification === null || $classification[self::CLASSIFICATION_KIND] !== self::KIND_MANAGED)
		{
			return null;
		}

		$identity = $classification[self::CLASSIFICATION_IDENTITY];
		if ($identity === null)
		{
			return null;
		}

		$hash = $identity->getHash();
		if (isset($this->heldOperations[$hash]) || isset($this->heldLocks[$hash]))
		{
			return null;
		}

		try
		{
			$locked = Application::getConnection()->lock(self::buildLockName($identity), self::LOCK_TIMEOUT_SECONDS);
		}
		catch (\Throwable)
		{
			return null;
		}

		if (!$locked)
		{
			return null;
		}

		$this->heldLocks[$hash] = true;

		return $identity;
	}

	/**
	 * Releases the logical lock {@see self::acquireTemplateLock()} took, and does nothing for an identity this
	 * request did not lock that way.
	 *
	 * The release belongs to the finally block of its caller, therefore a failed one is swallowed the same way
	 * a failed acquisition is: the caller has to learn the reason its own work was refused for and not the way
	 * the lock was given back. A lock that could not be released dies with the connection at the end of the
	 * request anyway, and the request stops asking for it right here.
	 */
	public function releaseTemplateLock(ManagedAgentIdentity $identity): void
	{
		$hash = $identity->getHash();
		if (!isset($this->heldLocks[$hash]))
		{
			return;
		}

		unset($this->heldLocks[$hash]);

		try
		{
			Application::getConnection()->unlock(self::buildLockName($identity));
		}
		catch (\Throwable)
		{
		}
	}

	/**
	 * Issues the cryptographically random one time marker that authorizes exactly one workflow start while the
	 * instance is still enabling.
	 *
	 * The marker lives in the memory of the request only and dies with the operation scope, which is what binds
	 * it to the current operation.
	 *
	 * @throws SystemException when no operation scope of this identity is open
	 */
	public function issueStartMarker(ManagedAgentIdentity $identity): string
	{
		$hash = $identity->getHash();
		if (!isset($this->heldOperations[$hash]))
		{
			throw new SystemException('A start marker requires an open managed agent operation');
		}

		$marker = rtrim(strtr(base64_encode(Random::getBytes(self::MARKER_BYTE_LENGTH)), '+/', '-_'), '=');
		$this->startMarkers[$hash][] = $marker;

		return $marker;
	}

	/**
	 * Barrier of a starting workflow, called by the OnCreateWorkflow handler before CBPWorkflow::initialize()
	 * and CBPStateService::addWorkflow(), so the future workflow id is owned before its state exists.
	 *
	 * The one time marker of the operation is taken out of the start parameters and consumed here, hence it
	 * never reaches the initialized workflow and cannot authorize a second start. A refusal has to abort the
	 * creation of the workflow.
	 *
	 * The marker is taken out only after the template is confirmed to be a managed one. The event hands the
	 * parameters over after the declared defaults have been substituted and the very same array goes on to
	 * CBPWorkflow::initialize(), so an unconditional removal would take a value of that name away from a
	 * foreign workflow. The classification of the template is memoized, therefore asking for it here costs
	 * the barrier no additional query.
	 *
	 * @param array $parameters start parameters of the workflow, passed by reference by the event
	 */
	public function registerWorkflowCreation(int $templateId, string $workflowId, array &$parameters): Result
	{
		$marker = null;
		if (($this->classify($templateId)[self::CLASSIFICATION_KIND] ?? null) === self::KIND_MANAGED)
		{
			$marker = $parameters[self::PARAMETER_OPERATION_MARKER] ?? null;
			unset($parameters[self::PARAMETER_OPERATION_MARKER]);
		}

		return $this->registerForTemplate(
			$templateId,
			ManagedAgentResourceType::Workflow,
			$workflowId,
			[],
			is_string($marker) ? $marker : null,
		);
	}

	/**
	 * Barrier and registration of a resource produced outside the lifecycle of a registered workflow: a
	 * schedule row inside the transaction that saves it, the storage scope right after the copy is bound and
	 * the reservation of a bot code before the start.
	 *
	 * A resource created synchronously inside a registered workflow is covered by the registration of that
	 * workflow and does not pass this barrier.
	 *
	 * @param array $data technical payload of the closed schema of $type, without its version key
	 */
	public function register(
		int $templateId,
		ManagedAgentResourceType $type,
		string $resourceId,
		array $data = [],
	): Result
	{
		return $this->registerForTemplate($templateId, $type, $resourceId, $data, null);
	}

	/**
	 * Drops the ownership row of a resource that is no longer live, which is what the workflow completion and
	 * the workflow kill events do.
	 *
	 * One indexed read over (TYPE, RESOURCE_ID) answers whether the resource is managed at all, therefore an
	 * unmanaged workflow finishes immediately. The logical lock is not needed: the row of a resource that has
	 * already ended cannot be restored by a reconciliation.
	 *
	 * Registry tables that are not installed yet are the same exception as in {@see self::classify()} and not
	 * a failed read: without them no resource can be owned, so there is nothing to release.
	 */
	public function releaseByResourceId(ManagedAgentResourceType $type, string $resourceId): Result
	{
		if ($this->resourceRepository === null)
		{
			return self::fail(self::ERROR_REGISTRY_FAILURE);
		}

		if (!$this->tablesExist())
		{
			return new Result();
		}

		try
		{
			$resourceRowId = $this->resourceRepository->findIdByTypeAndResourceId($type, $resourceId);
			if ($resourceRowId === null)
			{
				return new Result();
			}

			$this->resourceRepository->delete($resourceRowId);
		}
		catch (\Throwable)
		{
			return self::fail(self::ERROR_REGISTRY_FAILURE);
		}

		return new Result();
	}

	/**
	 * Drops the remembered classification of one template, so the next producer of it reads the registry again.
	 *
	 * A template may become managed after it has already been classified: the copy of an enabling instance is
	 * created before its binding is written, and a producer that ran during the copy has answered itself
	 * "unmanaged" for the rest of the request. The orchestrator calls this right after the binding, otherwise the
	 * barrier and every later registration of that template would silently stay a no-op.
	 */
	public function forgetClassification(int $templateId): void
	{
		unset($this->classifications[$templateId]);
	}

	/**
	 * Whether the template belongs to a managed instance, answered by the same single indexed read and the same
	 * memory of the request as every registration of a resource.
	 *
	 * A producer that has to behave differently inside a managed copy asks this instead of reading the registry
	 * tables on its own; a template that cannot be classified at all is never read as managed.
	 */
	public function isManagedTemplate(int $templateId): bool
	{
		$classification = $this->classify($templateId);

		return $classification !== null && $classification[self::CLASSIFICATION_KIND] === self::KIND_MANAGED;
	}

	private function registerForTemplate(
		int $templateId,
		ManagedAgentResourceType $type,
		string $resourceId,
		array $data,
		?string $marker,
	): Result
	{
		if ($resourceId === '' || strlen($resourceId) > self::MAX_RESOURCE_ID_LENGTH)
		{
			return self::fail(self::ERROR_RESOURCE_DATA_INVALID);
		}

		if ($this->instanceRepository === null || $this->resourceRepository === null)
		{
			return self::fail(self::ERROR_REGISTRY_FAILURE);
		}

		$payload = ManagedAgentResourcePayload::normalize($data, $type);
		if ($payload === null)
		{
			return self::fail(self::ERROR_RESOURCE_DATA_INVALID);
		}

		$classification = $this->classify($templateId);
		if ($classification === null)
		{
			return self::fail(self::ERROR_REGISTRY_FAILURE);
		}

		$kind = $classification[self::CLASSIFICATION_KIND];
		if ($kind === self::KIND_UNMANAGED)
		{
			return new Result();
		}

		if ($kind === self::KIND_ABSENT)
		{
			return self::refuse(self::REASON_TEMPLATE_ABSENT);
		}

		$identity = $classification[self::CLASSIFICATION_IDENTITY];
		if ($identity === null)
		{
			return self::refuse(self::REASON_IDENTITY_UNUSABLE);
		}

		return $this->registerUnderLock(
			$identity,
			$classification[self::CLASSIFICATION_INSTANCE_ID],
			$type,
			$resourceId,
			$payload,
			$marker,
		);
	}

	private function registerUnderLock(
		ManagedAgentIdentity $identity,
		int $instanceId,
		ManagedAgentResourceType $type,
		string $resourceId,
		array $payload,
		?string $marker,
	): Result
	{
		$connection = Application::getConnection();
		$lockName = self::buildLockName($identity);
		$hash = $identity->getHash();
		$lockHeldOutside = isset($this->heldOperations[$hash]) || isset($this->heldLocks[$hash]);

		try
		{
			$locked = $lockHeldOutside || $connection->lock($lockName, self::LOCK_TIMEOUT_SECONDS);
		}
		catch (\Throwable)
		{
			// A lock that could not be requested is an infrastructure failure, not a busy lock.
			return self::fail(self::ERROR_REGISTRY_FAILURE);
		}

		if (!$locked)
		{
			return self::fail(self::ERROR_LOCK_CONFLICT);
		}

		try
		{
			// The state is reread only now: the instance may have been removed while the lock was awaited.
			$instance = $this->instanceRepository->getById($instanceId);
			if ($instance === null)
			{
				return self::refuse(self::REASON_INSTANCE_ABSENT);
			}

			if (!$identity->matchesStoredComponents($instance))
			{
				return self::refuse(self::REASON_IDENTITY_UNUSABLE);
			}

			$refusal = $this->passBarrier($instance, $identity->getHash(), $type, $marker);
			if ($refusal !== null)
			{
				return $refusal;
			}

			return $this->persistRegistration($instanceId, $type, $resourceId, $payload);
		}
		catch (\Throwable)
		{
			return self::fail(self::ERROR_REGISTRY_FAILURE);
		}
		finally
		{
			// Best effort exactly as in releaseTemplateLock(): the barrier of a starting workflow runs inside
			// OnCreateWorkflow of every workflow of the portal, so an exception of unlock() would abort the
			// creation of a foreign workflow and would hide an already persisted registration.
			if (!$lockHeldOutside)
			{
				try
				{
					$connection->unlock($lockName);
				}
				catch (\Throwable)
				{
				}
			}
		}
	}

	/**
	 * Refusal of the barrier, or null when the resource may be registered.
	 */
	private function passBarrier(
		ManagedAgentInstance $instance,
		string $identityHash,
		ManagedAgentResourceType $type,
		?string $marker,
	): ?Result
	{
		return match ($instance->getState())
		{
			ManagedAgentInstanceState::Enabled => null,
			ManagedAgentInstanceState::Deleting,
			ManagedAgentInstanceState::CleanupPending => self::refuse(self::REASON_CLEANUP_IN_PROGRESS),
			ManagedAgentInstanceState::Enabling => $this->passEnablingBarrier($identityHash, $type, $marker),
		};
	}

	/**
	 * Barrier of an instance that is still being created: a workflow starts only with the one time marker of
	 * the operation, and any other producer has to belong to that operation, which holds the logical lock.
	 */
	private function passEnablingBarrier(
		string $identityHash,
		ManagedAgentResourceType $type,
		?string $marker,
	): ?Result
	{
		if ($type === ManagedAgentResourceType::Workflow)
		{
			return $this->consumeStartMarker($identityHash, $marker)
				? null
				: self::refuse(self::REASON_START_NOT_AUTHORIZED)
			;
		}

		return isset($this->heldOperations[$identityHash])
			? null
			: self::refuse(self::REASON_OPERATION_REQUIRED)
		;
	}

	/**
	 * Spends the one time marker of the operation, comparing it in constant time and leaving no way to spend
	 * the same marker twice.
	 */
	private function consumeStartMarker(string $identityHash, ?string $marker): bool
	{
		if ($marker === null)
		{
			return false;
		}

		foreach ($this->startMarkers[$identityHash] ?? [] as $index => $issued)
		{
			if (hash_equals($issued, $marker))
			{
				unset($this->startMarkers[$identityHash][$index]);

				return true;
			}
		}

		return false;
	}

	/**
	 * Writes the ownership row, or keeps the stored one when the logical key is already registered.
	 *
	 * A repeated registration never inserts a second row. Technical data are merged over the stored payload
	 * only after they passed the closed schema; a stored payload that does not pass it is replaced instead of
	 * being merged with, because its shape is unknown.
	 */
	private function persistRegistration(
		int $instanceId,
		ManagedAgentResourceType $type,
		string $resourceId,
		array $payload,
	): Result
	{
		$stored = $this->resourceRepository->findByLogicalKey($instanceId, $type, $resourceId);
		if ($stored === null)
		{
			$resource = $this->resourceRepository->save(
				new ManagedAgentResource(null, $instanceId, $type, $resourceId, $payload),
			);
		}
		else
		{
			$merged = ManagedAgentResourcePayload::matchesSchema($stored->getData(), $type)
				? array_replace($stored->getData(), $payload)
				: $payload
			;

			$resource = $merged === $stored->getData()
				? $stored
				: $this->resourceRepository->save($stored->withData($merged))
			;
		}

		return (new Result())->setData([self::DATA_RESOURCE => $resource]);
	}

	/**
	 * Classification of the template, remembered for the current request only, or null when the registry
	 * cannot be read at all. A failed classification is never remembered and never read as an unmanaged
	 * template.
	 *
	 * Registry tables that are not installed yet are the one exception, and not a failed read: without the
	 * tables no managed instance can exist, so nothing is able to break the invariant that no workflow is left
	 * without an owner. The barrier sits on the creation path of every workflow of the portal, therefore
	 * refusing them all while the files of an update are copied but its script has not run yet would be a far
	 * worse answer than the unmanaged path.
	 *
	 * @return array{kind: string, instanceId: int, identity: ?ManagedAgentIdentity}|null
	 */
	private function classify(int $templateId): ?array
	{
		if (isset($this->classifications[$templateId]))
		{
			return $this->classifications[$templateId];
		}

		if (!$this->tablesExist())
		{
			return self::describeClassification(self::KIND_UNMANAGED);
		}

		try
		{
			$row = $this->loadClassificationRow($templateId);
		}
		catch (\Throwable)
		{
			return null;
		}

		$instanceId = $row === null ? 0 : (int)($row['INSTANCE_ID'] ?? 0);

		if ($row === null)
		{
			$classification = self::describeClassification(self::KIND_ABSENT);
		}
		elseif ($instanceId <= 0)
		{
			$classification = self::describeClassification(self::KIND_UNMANAGED);
		}
		else
		{
			$classification = self::describeClassification(
				self::KIND_MANAGED,
				$instanceId,
				self::buildIdentity($row),
			);
		}

		$this->classifications[$templateId] = $classification;

		return $classification;
	}

	/**
	 * Tells whether both registry tables are already in the schema.
	 *
	 * The confirmed presence outlives the request in {@see self::SCHEMA_READY_OPTION}, so the schema is asked
	 * about once in the life of the portal instead of once per request. Absence never outlives the request, and
	 * a check that could not be made is remembered for the current request alone, so that a failing schema never
	 * costs a second attempt on the same hit.
	 */
	private function tablesExist(): bool
	{
		if ($this->tablesExist !== null)
		{
			return $this->tablesExist;
		}

		try
		{
			if (Option::get(self::MODULE_ID, self::SCHEMA_READY_OPTION, 'N') === self::OPTION_YES)
			{
				$this->tablesExist = true;

				return true;
			}

			$connection = Application::getConnection();
			$exists =
				$connection->isTableExists(ManagedAgentInstanceTable::getTableName())
				&& $connection->isTableExists(ManagedAgentResourceTable::getTableName())
			;
		}
		catch (\Throwable)
		{
			// A check that could not be made is no proof of absence: fall through to the fail-closed read. The
			// stored answer is read inside this block as well, because the callers of the check answer by a
			// Result and a throw of the bookkeeping would break the creation of any workflow of the portal.
			$this->tablesExist = true;

			return true;
		}

		if ($exists)
		{
			self::rememberSchema();
		}

		$this->tablesExist = $exists;

		return $exists;
	}

	/**
	 * Stores the confirmed presence of the schema, which happens once in the life of the portal.
	 */
	private static function rememberSchema(): void
	{
		try
		{
			Option::set(self::MODULE_ID, self::SCHEMA_READY_OPTION, self::OPTION_YES);
		}
		catch (\Throwable)
		{
			// A flag that could not be stored only means the next request asks the schema again.
		}
	}

	/**
	 * One indexed read that tells a managed copy, an existing unmanaged template and a template that is
	 * already deleted apart: the primary key of the template joined with the unique template key of the
	 * instance. The immutable identity components are read together with it, because the logical lock has to be
	 * acquired before the state of the instance may be reread.
	 */
	private function loadClassificationRow(int $templateId): ?array
	{
		if ($templateId <= 0)
		{
			return null;
		}

		$row = WorkflowTemplateTable::query()
			->registerRuntimeField(
				self::INSTANCE_JOIN,
				new Reference(
					self::INSTANCE_JOIN,
					ManagedAgentInstanceTable::class,
					Join::on('this.ID', 'ref.TEMPLATE_ID'),
					['join_type' => 'LEFT'],
				),
			)
			->setSelect([
				'ID',
				'INSTANCE_ID' => self::INSTANCE_JOIN . '.ID',
				'INSTANCE_SYSTEM_CODE' => self::INSTANCE_JOIN . '.SYSTEM_CODE',
				'INSTANCE_NAMESPACE' => self::INSTANCE_JOIN . '.CONTEXT_NAMESPACE',
				'INSTANCE_TYPE' => self::INSTANCE_JOIN . '.CONTEXT_TYPE',
				'INSTANCE_CONTEXT_ID' => self::INSTANCE_JOIN . '.CONTEXT_ID',
			])
			->where('ID', $templateId)
			->setLimit(1)
			->fetch()
		;

		return $row === false ? null : $row;
	}

	/**
	 * Identity of the stored components, or null when they cannot form one, which is never read as an
	 * unmanaged template.
	 */
	private static function buildIdentity(array $row): ?ManagedAgentIdentity
	{
		try
		{
			return ManagedAgentIdentity::create(
				(string)($row['INSTANCE_SYSTEM_CODE'] ?? ''),
				new SystemAiAgentContext(
					namespace: (string)($row['INSTANCE_NAMESPACE'] ?? ''),
					type: (string)($row['INSTANCE_TYPE'] ?? ''),
					id: (string)($row['INSTANCE_CONTEXT_ID'] ?? ''),
				),
			);
		}
		catch (\Throwable)
		{
			return null;
		}
	}

	/**
	 * @return array{kind: string, instanceId: int, identity: ?ManagedAgentIdentity}
	 */
	private static function describeClassification(
		string $kind,
		int $instanceId = 0,
		?ManagedAgentIdentity $identity = null,
	): array
	{
		return [
			self::CLASSIFICATION_KIND => $kind,
			self::CLASSIFICATION_INSTANCE_ID => $instanceId,
			self::CLASSIFICATION_IDENTITY => $identity,
		];
	}

	private static function refuse(string $reason): Result
	{
		return (new Result())->addError(
			new Error('Creation of a managed agent resource is refused', self::ERROR_CREATION_REFUSED, [
				self::CUSTOM_DATA_REASON => $reason,
			]),
		);
	}

	private static function fail(string $code): Result
	{
		return (new Result())->addError(new Error('Managed agent resource registry failure', $code));
	}
}

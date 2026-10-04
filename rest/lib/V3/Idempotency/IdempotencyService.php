<?php

namespace Bitrix\Rest\V3\Idempotency;

use Bitrix\Main\Application;
use Bitrix\Main\Data\Storage\PersistentStorageInterface;
use Bitrix\Main\Engine\Action;
use Bitrix\Main\Web\HttpHeaders;
use Bitrix\Rest\V3\Attribute\Idempotent;
use Bitrix\Rest\V3\Attribute\NotIdempotent;
use Bitrix\Rest\V3\CacheManager;
use Bitrix\Rest\V3\Controller\RestController;
use Bitrix\Rest\V3\Exception\InvalidIdempotencyKeyException;
use Bitrix\Rest\V3\Interaction\Request\AddRequest;
use Bitrix\Rest\V3\Interaction\Request\DeleteRequest;
use Bitrix\Rest\V3\Interaction\Request\UpdateRequest;
use Bitrix\Rest\V3\Schema\SchemaManager;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

/**
 * Facade over PersistentStorageInterface for the REST V3 idempotency feature.
 *
 * Responsibilities:
 * - shouldApplyForReflection / ttlForReflection — pure reflection-based decisions
 *   that don't touch storage (used in unit tests and when building the action cache).
 * - getActionConfig / shouldApplyForAction — cached per actionUri decision for the runtime filter.
 * - find / save — read/write of {@see IdempotencyEntry} through the injected
 *   storage. Storage exceptions are logged and swallowed; the action proceeds
 *   without idempotency in that case (graceful degradation).
 *
 * Race-window trade-off: storage has no atomic setIfNotExists. Two truly concurrent
 * requests with the same key that both pass through lookup() cache-miss before
 * either has called save() will both execute the action. The second save() wins.
 * See design doc "Trade-off: accepted race window" for rationale.
 */
final class IdempotencyService
{
	public const DEFAULT_TTL = 86400;
	public const HEADER_NAME = 'Idempotency-Key';
	public const REPLAY_HEADER_NAME = 'Idempotent-Replayed';
	private const HEADER_REGEX = '/\A[\x21-\x7E]{1,255}\z/';
	private const ACTION_CONFIG_CACHE_PREFIX = 'rest.v3.idempotency.actionConfig.';

	/** Request DTO classes that auto-enable idempotency when used as the only action parameter. */
	private const AUTO_DETECT_TYPES = [
		AddRequest::class,
		UpdateRequest::class,
		DeleteRequest::class,
	];

	public function __construct(
		private readonly PersistentStorageInterface $storage,
		private readonly ?SchemaManager $schemaManager = null,
	) {
	}

	/**
	 * Returns true if the given action method should participate in idempotency:
	 * - #[NotIdempotent] always disables (highest precedence).
	 * - #[Idempotent] always enables.
	 * - Otherwise auto-detect: ANY parameter is of an auto-detected request type
	 *   (AddRequest / UpdateRequest / DeleteRequest or a subclass).
	 */
	public function shouldApplyForReflection(ReflectionMethod $method): bool
	{
		return $this->evaluateShouldApply($method);
	}

	/**
	 * Returns the TTL (seconds) for the given action method.
	 * #[Idempotent(ttl: N)] -> N; otherwise DEFAULT_TTL.
	 */
	public function ttlForReflection(ReflectionMethod $method): int
	{
		return $this->resolveTtlForReflection($method);
	}

	/**
	 * @return array{apply: bool, ttl: int}
	 */
	public function getActionConfig(Action $action): array
	{
		return $this->resolveActionConfig($action);
	}

	public function shouldApplyForAction(Action $action): bool
	{
		return $this->getActionConfig($action)['apply'];
	}

	public function ttlForAction(string $actionUri): int
	{
		$cached = CacheManager::get($this->buildActionConfigCacheKey($actionUri));
		if (is_array($cached) && isset($cached['ttl']))
		{
			return (int)$cached['ttl'];
		}

		return self::DEFAULT_TTL;
	}

	public function find(string $storageKey): ?IdempotencyEntry
	{
		try
		{
			$raw = $this->storage->get($storageKey);
		}
		catch (Throwable $e)
		{
			$this->logStorageFailure('find', $e);

			return null;
		}

		if (!is_array($raw))
		{
			return null;
		}

		try
		{
			return IdempotencyEntry::fromArray($raw);
		}
		catch (Throwable $e)
		{
			$this->logStorageFailure('decode', $e);

			return null;
		}
	}

	public function save(string $storageKey, IdempotencyEntry $entry, int $ttl): void
	{
		try
		{
			$this->storage->set($storageKey, $entry->toArray(), $ttl);
		}
		catch (Throwable $e)
		{
			$this->logStorageFailure('save', $e);
		}
	}

	public function extractHeaderValue(HttpHeaders $headers): ?string
	{
		$headerValue = $headers->get(self::HEADER_NAME);
		if ($headerValue === null)
		{
			return null;
		}

		if (!preg_match(self::HEADER_REGEX, $headerValue))
		{
			throw new InvalidIdempotencyKeyException();
		}

		return $headerValue;
	}

	/**
	 * @return array{apply: bool, ttl: int}
	 */
	private function resolveActionConfig(Action $action): array
	{
		$controller = $action->getController();

		if (!$controller instanceof RestController || $this->schemaManager === null)
		{
			return ['apply' => false, 'ttl' => self::DEFAULT_TTL];
		}

		$server = $controller->getServer();
		// V3 transport only (CRestApiServer / DeferredRestApiServer); skip AJAX and legacy CRestServer.
		if (!$server instanceof \CRestApiServer)
		{
			return ['apply' => false, 'ttl' => self::DEFAULT_TTL];
		}

		$methodDescription = $this->schemaManager->getMethodDescription($server->getMethod());
		if ($methodDescription === null)
		{
			return ['apply' => false, 'ttl' => self::DEFAULT_TTL];
		}

		$cacheKey = $this->buildActionConfigCacheKey($methodDescription->actionUri);
		$cached = CacheManager::get($cacheKey);
		if (is_array($cached) && isset($cached['apply'], $cached['ttl']))
		{
			return [
				'apply' => (bool)$cached['apply'],
				'ttl' => (int)$cached['ttl'],
			];
		}

		$reflectionMethod = new ReflectionMethod($controller, $action->getName() . 'Action');
		$config = [
			'apply' => $this->evaluateShouldApply($reflectionMethod),
			'ttl' => $this->resolveTtlForReflection($reflectionMethod),
		];

		CacheManager::set($cacheKey, $config);

		return $config;
	}

	private function evaluateShouldApply(ReflectionMethod $method): bool
	{
		if ($method->getAttributes(NotIdempotent::class) !== [])
		{
			return false;
		}

		if ($method->getAttributes(Idempotent::class) !== [])
		{
			return true;
		}

		foreach ($method->getParameters() as $param)
		{
			$type = $param->getType();
			if (!$type instanceof ReflectionNamedType || $type->isBuiltin())
			{
				continue;
			}

			$typeName = ltrim($type->getName(), '\\');
			foreach (self::AUTO_DETECT_TYPES as $allowed)
			{
				if ($typeName === $allowed || is_subclass_of($typeName, $allowed))
				{
					return true;
				}
			}
		}

		return false;
	}

	private function resolveTtlForReflection(ReflectionMethod $method): int
	{
		$idempotentAttributes = $method->getAttributes(Idempotent::class);
		if ($idempotentAttributes === [])
		{
			return self::DEFAULT_TTL;
		}

		/** @var Idempotent $instance */
		$instance = $idempotentAttributes[0]->newInstance();

		return $instance->ttl ?? self::DEFAULT_TTL;
	}

	private function buildActionConfigCacheKey(string $actionUri): string
	{
		return self::ACTION_CONFIG_CACHE_PREFIX . $actionUri;
	}

	private function logStorageFailure(string $stage, Throwable $e): void
	{
		try
		{
			Application::getInstance()->getExceptionHandler()->writeToLog($e);
		}
		catch (Throwable)
		{
			// Last-resort safety net: we MUST NOT propagate from here.
		}
	}
}

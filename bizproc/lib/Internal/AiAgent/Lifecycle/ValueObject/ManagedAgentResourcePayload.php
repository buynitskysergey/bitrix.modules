<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\AiAgent\Lifecycle\ValueObject;

use Bitrix\Bizproc\Internal\Entity\AiAgent\ManagedAgentResourceType;

/**
 * Closed schema of the technical payload an ownership row carries, and the only place that builds one.
 *
 * The payload exists for one purpose: to let a cleanup of a resource be repeated without asking the producer
 * again. It is therefore versioned and closed - a version the build does not know and a key outside the schema
 * of the type are both refused, so a participant never deletes anything by data nobody verified.
 *
 * The schema owns no state and is used by both sides of the row: the registry normalizes what a producer offers
 * before it is written, and every cleanup participant checks what it read back. It lives apart from the registry
 * so that a participant needs the schema alone instead of the whole registration service.
 */
final class ManagedAgentResourcePayload
{
	public const VERSION_KEY = 'v';

	public const VERSION = 1;

	/**
	 * Id of the created bot, pinned by the first observation of the reservation: the reserved code proves
	 * ownership only together with this id, therefore the cleanup never replaces a stored one.
	 */
	public const KEY_BOT_ID = 'botId';

	private const TYPE_INT = 'int';

	/**
	 * Payload of a type that needs no technical details of its own.
	 */
	public static function build(): array
	{
		return [self::VERSION_KEY => self::VERSION];
	}

	/**
	 * Payload of a bot reservation, which pins the id of the bot the reserved code was first seen to hold.
	 */
	public static function buildForBot(int $botId): array
	{
		return [
			self::VERSION_KEY => self::VERSION,
			self::KEY_BOT_ID => $botId,
		];
	}

	/**
	 * Tells whether the payload passes the closed schema of its type, hence may be used to repeat a cleanup.
	 */
	public static function matchesSchema(array $data, ManagedAgentResourceType $type): bool
	{
		return self::normalize($data, $type) !== null;
	}

	/**
	 * Payload of the closed schema of the type with its version, or null when a key, a value or the stored
	 * version is outside the schema.
	 */
	public static function normalize(array $data, ManagedAgentResourceType $type): ?array
	{
		$schema = self::describeSchema($type);

		$payload = [self::VERSION_KEY => self::VERSION];
		foreach ($data as $key => $value)
		{
			if ($key === self::VERSION_KEY)
			{
				if (!is_int($value) || $value !== self::VERSION)
				{
					return null;
				}

				continue;
			}

			if (($schema[$key] ?? null) !== self::TYPE_INT || !is_int($value) || $value <= 0)
			{
				return null;
			}

			$payload[$key] = $value;
		}

		return $payload;
	}

	/**
	 * Allowed keys of a resource type and their scalar types.
	 *
	 * A type without technical details of a repeatable cleanup keeps the version alone. The match is exhaustive
	 * on purpose: a resource type added without a schema fails loudly instead of silently accepting anything.
	 *
	 * @return array<string, string>
	 */
	private static function describeSchema(ManagedAgentResourceType $type): array
	{
		return match ($type)
		{
			ManagedAgentResourceType::BizprocBot,
			ManagedAgentResourceType::OpenLinesBot => [self::KEY_BOT_ID => self::TYPE_INT],
			ManagedAgentResourceType::Schedule,
			ManagedAgentResourceType::Workflow,
			ManagedAgentResourceType::StorageScope => [],
		};
	}
}

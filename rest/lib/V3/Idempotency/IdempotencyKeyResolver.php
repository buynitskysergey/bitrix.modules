<?php

namespace Bitrix\Rest\V3\Idempotency;

/**
 * Builds the storage key for an idempotency record.
 *
 * The key is a sha256 of (clientId, userId, method, headerValue) prefixed with
 * "rest.idempotency.". The composition guarantees:
 *
 * - Two different OAuth apps with the same header value -> independent records
 *   (no cross-tenant leak).
 * - The same app on behalf of two users with the same header value -> independent
 *   records (no cross-user leak).
 * - The same (app, user, header) calling two different methods -> independent
 *   records (no cross-method silent replay).
 *
 * The output is always 84 bytes (prefix 20 + sha256 hex 64), which fits within
 * `b_persistent_storage.KEY VARCHAR(255)`.
 */
final class IdempotencyKeyResolver
{
	public const PREFIX = 'rest.v3.idempotency.';

	public function resolve(
		string $clientId,
		string $userId,
		string $method,
		string $headerValue,
	): string
	{
		$payload = $clientId . ':' . $userId . ':' . $method . ':' . $headerValue;

		return self::PREFIX . hash('sha256', $payload);
	}
}

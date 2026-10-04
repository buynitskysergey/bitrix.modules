<?php

namespace Bitrix\Rest\V3\Idempotency;

use Bitrix\Main\Type\DateTime;
use InvalidArgumentException;

/**
 * Stored payload for a single idempotency record.
 *
 * One instance describes the result of one previously-executed write action: the
 * sha256 of the original request body (used to reject reuse with a different
 * body), the HTTP status code to restore on replay, the serialised response, and
 * a debug-only timestamp.
 *
 * Round-trips through `toArray()` / `fromArray()` so it can be stored as JSON
 * via `PersistentStorageInterface`.
 */
final class IdempotencyEntry
{
	public function __construct(
		public readonly string $bodyHash,
		public readonly int $statusCode,
		public readonly array $response,
		public readonly DateTime $savedAt,
		public readonly bool $showRawData = false,
		public readonly bool $showDebugInfo = true,
	) {
	}

	public function toArray(): array
	{
		return [
			'bodyHash' => $this->bodyHash,
			'statusCode' => $this->statusCode,
			'response' => $this->response,
			'savedAt' => $this->savedAt->format(DATE_ATOM),
			'showRawData' => $this->showRawData,
			'showDebugInfo' => $this->showDebugInfo,
		];
	}

	public static function fromArray(array $data): self
	{
		$requiredFields = ['bodyHash', 'statusCode', 'response', 'savedAt'];
		foreach ($requiredFields as $required)
		{
			if (!array_key_exists($required, $data))
			{
				throw new InvalidArgumentException(
					"IdempotencyEntry::fromArray: missing required key '{$required}'",
				);
			}
		}

		if (!is_array($data['response']))
		{
			throw new InvalidArgumentException(
				"IdempotencyEntry::fromArray: 'response' must be an array",
			);
		}

		return new self(
			bodyHash: (string)$data['bodyHash'],
			statusCode: (int)$data['statusCode'],
			response: $data['response'],
			savedAt: DateTime::createFromPhp(new \DateTime($data['savedAt'])),
			showRawData: (bool)($data['showRawData'] ?? false),
			showDebugInfo: (bool)($data['showDebugInfo'] ?? true),
		);
	}
}

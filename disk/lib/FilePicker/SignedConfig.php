<?php

declare(strict_types=1);

namespace Bitrix\Disk\FilePicker;

use Bitrix\Main\Result;
use Bitrix\Main\Security\Sign\BadSignatureException;
use Bitrix\Main\Security\Sign\Signer;
use Bitrix\Main\Web\Json;

final class SignedConfig
{
	public const VERSION = 1;

	public const SELECTION_MODE_SINGLE = 'single';
	public const SELECTION_MODE_MULTIPLE = 'multiple';

	private const MAX_ITEMS = 100;
	private const SIGNATURE_SALT = 'disk.filePicker.config';
	private const ALLOWED_SELECTION_MODES = [
		self::SELECTION_MODE_SINGLE,
		self::SELECTION_MODE_MULTIPLE,
	];
	private const PAYLOAD_KEYS = [
		'version' => true,
		'selectionMode' => true,
		'maxItems' => true,
		'allowedFileTypes' => true,
		'initialStage' => true,
		'expiresAt' => true,
		'userId' => true,
		'siteId' => true,
	];

	private function __construct(
		private readonly array $payload,
	)
	{
	}

	public static function createFromSignedArray(
		array $descriptor,
		int $currentUserId,
		?string $currentSiteId,
		?int $currentTimestamp = null,
	): Result
	{
		$result = new Result();
		$signature = $descriptor['signature'] ?? null;
		if (!\is_string($signature) || $signature === '')
		{
			return $result->addError(Error::create(Error::INVALID_CONTEXT));
		}

		unset($descriptor['signature']);
		$descriptor = self::normalizeTransportPayload($descriptor);

		$payload = self::normalizePayload($descriptor);
		if ($payload === null || !self::validateSignature($payload, $signature))
		{
			return $result->addError(Error::create(Error::INVALID_CONTEXT));
		}

		if (!self::validateRuntimeBinding($payload, $currentUserId, $currentSiteId, $currentTimestamp ?? time()))
		{
			return $result->addError(Error::create(Error::INVALID_CONTEXT));
		}

		$result->setData([
			'signedConfig' => new self($payload),
		]);

		return $result;
	}

	public static function signPayload(array $payload): Result
	{
		$result = new Result();
		$payload = self::normalizePayload($payload);
		if ($payload === null)
		{
			return $result->addError(Error::create(Error::INVALID_CONTEXT));
		}

		$result->setData([
			'signedConfig' => [
				...$payload,
				'signature' => self::createSignature($payload),
			],
		]);

		return $result;
	}

	public function getPayload(): array
	{
		return $this->payload;
	}

	public function getInitialStage(): ?array
	{
		return $this->payload['initialStage'];
	}

	public function getSelectionMode(): string
	{
		return $this->payload['selectionMode'];
	}

	public function getAllowedFileTypes(): array
	{
		return $this->payload['allowedFileTypes'];
	}

	public function getMaxItemsLimit(): int
	{
		if ($this->payload['selectionMode'] === self::SELECTION_MODE_SINGLE)
		{
			return 1;
		}

		return $this->payload['maxItems'] ?? self::MAX_ITEMS;
	}

	private static function normalizePayload(array $payload): ?array
	{
		if (!self::hasOnlyKnownKeys($payload))
		{
			return null;
		}

		$normalized = [
			'version' => self::normalizePositiveInt($payload['version'] ?? null),
			'selectionMode' => self::normalizeString($payload['selectionMode'] ?? null),
			'maxItems' => self::normalizeMaxItems($payload['maxItems'] ?? null),
			'allowedFileTypes' => self::normalizeAllowedFileTypes($payload['allowedFileTypes'] ?? []),
			'initialStage' => self::normalizeInitialStage($payload['initialStage'] ?? null),
			'expiresAt' => self::normalizeNullablePositiveInt($payload['expiresAt'] ?? null),
			'userId' => self::normalizeNullablePositiveInt($payload['userId'] ?? null),
			'siteId' => self::normalizeNullableString($payload['siteId'] ?? null),
		];

		if (
			$normalized['version'] === false
			|| $normalized['version'] !== self::VERSION
			|| $normalized['selectionMode'] === false
			|| !\in_array($normalized['selectionMode'], self::ALLOWED_SELECTION_MODES, true)
			|| $normalized['maxItems'] === false
			|| $normalized['allowedFileTypes'] === null
			|| $normalized['initialStage'] === false
			|| $normalized['expiresAt'] === false
			|| $normalized['userId'] === false
			|| $normalized['siteId'] === false
		)
		{
			return null;
		}

		return $normalized;
	}

	private static function hasOnlyKnownKeys(array $payload): bool
	{
		foreach ($payload as $key => $_)
		{
			if (!isset(self::PAYLOAD_KEYS[$key]))
			{
				return false;
			}
		}

		return true;
	}

	private static function normalizeTransportPayload(array $payload): array
	{
		foreach (['version', 'maxItems', 'expiresAt', 'userId'] as $field)
		{
			if (isset($payload[$field]) && \is_string($payload[$field]))
			{
				$payload[$field] = self::normalizeTransportPositiveInt($payload[$field]);
			}
		}

		if (isset($payload['initialStage']) && \is_array($payload['initialStage']))
		{
			foreach (['storageId', 'folderId'] as $field)
			{
				if (isset($payload['initialStage'][$field]) && \is_string($payload['initialStage'][$field]))
				{
					$payload['initialStage'][$field]
						= self::normalizeTransportPositiveInt($payload['initialStage'][$field]);
				}
			}
		}

		return $payload;
	}

	private static function normalizeTransportPositiveInt(string $value): int|string
	{
		if (preg_match('/^[1-9][0-9]*$/D', $value) !== 1)
		{
			return $value;
		}

		$normalized = filter_var($value, FILTER_VALIDATE_INT, [
			'options' => ['min_range' => 1],
		]);

		return \is_int($normalized) ? $normalized : $value;
	}

	private static function normalizeString(mixed $value): string|false
	{
		if (!\is_string($value))
		{
			return false;
		}

		$value = trim($value);

		return $value === '' ? false : $value;
	}

	private static function normalizeNullableString(mixed $value): string|false|null
	{
		if ($value === null)
		{
			return null;
		}

		return self::normalizeString($value);
	}

	private static function normalizePositiveInt(mixed $value): int|false
	{
		if (!\is_int($value) || $value <= 0)
		{
			return false;
		}

		return $value;
	}

	private static function normalizeNullablePositiveInt(mixed $value): int|false|null
	{
		if ($value === null)
		{
			return null;
		}

		return self::normalizePositiveInt($value);
	}

	private static function normalizeMaxItems(mixed $value): int|false|null
	{
		if ($value === null)
		{
			return null;
		}

		if (!\is_int($value) || $value <= 0)
		{
			return false;
		}

		return min($value, self::MAX_ITEMS);
	}

	private static function normalizeAllowedFileTypes(mixed $value): ?array
	{
		if (!\is_array($value))
		{
			return null;
		}

		$filterResult = Filter::create(Filter::OBJECT_TYPE_FILES, $value, []);
		if (!$filterResult->isSuccess())
		{
			return null;
		}

		$fileTypes = $filterResult->getData()['filter']->getAllowedFileTypes();
		sort($fileTypes);

		return $fileTypes;
	}

	private static function normalizeInitialStage(mixed $value): array|false|null
	{
		if ($value === null)
		{
			return null;
		}

		if (!\is_array($value))
		{
			return false;
		}

		$fields = ['type' => true, 'storageId' => true, 'folderId' => true];
		if (
			!array_key_exists('type', $value)
			|| array_diff_key($value, $fields) !== []
		)
		{
			return false;
		}

		$type = self::normalizeString($value['type'] ?? null);
		if (!\in_array($type, [
			Provider::INITIAL_STAGE_RECENT,
			Provider::INITIAL_STAGE_FOLDER,
			Provider::INITIAL_STAGE_SOURCES,
		], true))
		{
			return false;
		}

		$storageId = self::normalizeNullablePositiveInt($value['storageId'] ?? null);
		$folderId = self::normalizeNullablePositiveInt($value['folderId'] ?? null);
		if ($storageId === false || $folderId === false)
		{
			return false;
		}

		if ($type === Provider::INITIAL_STAGE_FOLDER && ($storageId === null || $folderId === null))
		{
			return false;
		}

		if ($type !== Provider::INITIAL_STAGE_FOLDER && ($storageId !== null || $folderId !== null))
		{
			return false;
		}

		return [
			'type' => $type,
			'storageId' => $storageId,
			'folderId' => $folderId,
		];
	}

	private static function validateRuntimeBinding(
		array $payload,
		int $currentUserId,
		?string $currentSiteId,
		int $currentTimestamp,
	): bool
	{
		if ($payload['expiresAt'] !== null && $payload['expiresAt'] <= $currentTimestamp)
		{
			return false;
		}

		if ($payload['userId'] !== null && $payload['userId'] !== $currentUserId)
		{
			return false;
		}

		return $payload['siteId'] === null || $payload['siteId'] === $currentSiteId;
	}

	private static function createSignature(array $payload): string
	{
		return (new Signer())->getSignature(self::encodePayload($payload), self::SIGNATURE_SALT);
	}

	private static function validateSignature(array $payload, string $signature): bool
	{
		try
		{
			return (new Signer())->validate(self::encodePayload($payload), $signature, self::SIGNATURE_SALT);
		}
		catch (BadSignatureException)
		{
			return false;
		}
	}

	private static function encodePayload(array $payload): string
	{
		self::sortRecursively($payload);

		return Json::encode($payload);
	}

	private static function sortRecursively(array &$payload): void
	{
		foreach ($payload as &$value)
		{
			if (\is_array($value))
			{
				self::sortRecursively($value);
			}
		}
		unset($value);

		if (!array_is_list($payload))
		{
			ksort($payload);
		}
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\AiAgent\Lifecycle\ValueObject;

use Bitrix\Main\ArgumentOutOfRangeException;
use Bitrix\Main\ArgumentTypeException;
use Bitrix\Main\Security\Sign\Signer;

/**
 * Effective configuration of a managed system AI agent instance and its fingerprint.
 *
 * The fingerprint is an HMAC-SHA256 over the canonical form of everything that makes two activations of the
 * same identity the same: the format version, the identity hash, the system code, the launching user, the
 * installed revision of the managed copy, the normalized declarations and the effective values of every
 * activation section. The current revision of the delivered source is deliberately absent, so an update of
 * the source is not a conflict while a changed user or a changed effective value is.
 *
 * The key is the stable portal wide secret of the standard {@see Signer} taken under a purpose of its own;
 * the module never derives a node or random key. The material is fed to the hash as fragments, therefore a
 * second full copy of the configuration is never built in memory. Comparison goes through hash_equals().
 *
 * Canonicalization distinguishes scalar types, sorts the keys of an associative array by bytes and keeps the
 * order of a list, so equal configurations always produce equal fingerprints. Anything that cannot be
 * canonicalized without ambiguity is rejected: an invalid UTF-8 string, a non finite float, an object, a
 * resource and nesting over the depth limit, which is also what a cyclic array reference runs into.
 *
 * The object keeps the effective values, because the copy of the template is filled from them, but it never
 * exposes them as a whole and no rejection carries a checked value or a caller supplied key.
 */
final class EffectiveConfiguration
{
	/**
	 * Signing purpose of the standard Signer and, as the leading canonical fragment, the format version of
	 * the fingerprint: a new version is a new purpose, which changes both the key and the material.
	 */
	public const SIGNATURE_PURPOSE = 'bizproc.system_ai_agent.configuration.v1';

	public const MAX_DEPTH = 16;

	/**
	 * Limit of the canonical form of the data an external module passed, in bytes.
	 *
	 * The number of values themselves stays limited by the declarations of the agent and of the template.
	 */
	public const MAX_CALLER_CANONICAL_SIZE = 1048576;

	/**
	 * Parameter a rejection of the declarations of the source carries, and the prefix a rejection of a section of
	 * the caller puts before the name of that section.
	 *
	 * The canonicalization refuses a value through the standard argument exceptions of the kernel, which name the
	 * parameter and nothing else, therefore the caller of this class tells the two rejections apart by these two
	 * names. They are public for that reason alone: one owner of the contract instead of a second private copy on
	 * the reading side, which would drift silently.
	 */
	public const DECLARATIONS_PARAMETER = 'declarations';

	public const SECTION_PARAMETER_PREFIX = 'activation.';

	/**
	 * @param array<string, array<string, mixed>> $sections
	 */
	private function __construct(
		private readonly int $userId,
		private readonly array $sections,
		private readonly string $fingerprint,
	)
	{
	}

	/**
	 * @param string $installedRevision revision of the managed copy, not of the delivered source
	 * @param array<string, mixed> $declarations normalized declarations the values were validated against
	 * @param array<string, array<string, mixed>> $sections effective values of every activation section
	 * @throws ArgumentTypeException when a value cannot be canonicalized without ambiguity
	 * @throws ArgumentOutOfRangeException when the nesting depth or the canonical size limit is exceeded
	 */
	public static function create(
		ManagedAgentIdentity $identity,
		int $userId,
		string $installedRevision,
		array $declarations,
		array $sections,
	): self
	{
		$sections = self::normalizeSections($sections);

		return new self(
			userId: $userId,
			sections: $sections,
			fingerprint: self::calculateFingerprint(
				$identity,
				$userId,
				$installedRevision,
				$declarations,
				$sections,
			),
		);
	}

	/**
	 * Returns the hexadecimal fingerprint stored as CONFIG_FINGERPRINT.
	 */
	public function getFingerprint(): string
	{
		return $this->fingerprint;
	}

	/**
	 * Tells whether a stored fingerprint belongs to this configuration.
	 */
	public function matches(string $fingerprint): bool
	{
		return hash_equals($this->fingerprint, $fingerprint);
	}

	/**
	 * Returns the user the instance is launched by, which is a part of the signed configuration.
	 */
	public function getUserId(): int
	{
		return $this->userId;
	}

	/**
	 * @return array<string, mixed> effective values of the section, empty when the section carries none
	 */
	public function getSection(string $name): array
	{
		return $this->sections[$name] ?? [];
	}

	/**
	 * @return string[]
	 */
	public function getSectionNames(): array
	{
		return array_map('strval', array_keys($this->sections));
	}

	/**
	 * @param array<string, array<string, mixed>> $sections
	 * @return array<string, array<string, mixed>>
	 */
	private static function normalizeSections(array $sections): array
	{
		$normalized = [];
		foreach ($sections as $name => $values)
		{
			$name = (string)$name;
			if (!is_array($values))
			{
				throw new ArgumentTypeException(self::SECTION_PARAMETER_PREFIX . $name, 'array');
			}

			$normalized[$name] = $values;
		}

		return $normalized;
	}

	/**
	 * @param array<string, mixed> $declarations
	 * @param array<string, array<string, mixed>> $sections
	 */
	private static function calculateFingerprint(
		ManagedAgentIdentity $identity,
		int $userId,
		string $installedRevision,
		array $declarations,
		array $sections,
	): string
	{
		$context = hash_init('sha256');

		self::writeField($context, self::SIGNATURE_PURPOSE);
		self::writeField($context, $identity->getHash());
		self::writeField($context, $identity->getSystemCode());
		self::writeField($context, (string)$userId);
		self::writeField($context, $installedRevision);
		self::writeValue($context, $declarations, self::DECLARATIONS_PARAMETER, 1, PHP_INT_MAX);

		$budget = self::MAX_CALLER_CANONICAL_SIZE;
		foreach (self::selectSignedSections($sections) as $name => $values)
		{
			$name = (string)$name;
			$parameter = self::SECTION_PARAMETER_PREFIX . $name;

			self::writeField($context, $name);
			$budget -= self::writeValue($context, $values, $parameter, 1, $budget);
		}

		return (new Signer())->getSignature(hash_final($context), self::SIGNATURE_PURPOSE);
	}

	/**
	 * Keeps only the sections that carry values and orders them by name.
	 *
	 * An empty section contributes nothing, so a section introduced later leaves the fingerprint of the
	 * instances that do not use it unchanged, and the order of the declared sections cannot shift it.
	 *
	 * @param array<string, array<string, mixed>> $sections
	 * @return array<string, array<string, mixed>>
	 */
	private static function selectSignedSections(array $sections): array
	{
		$signed = array_filter($sections, static fn(array $values): bool => $values !== []);
		ksort($signed, SORT_STRING);

		return $signed;
	}

	/**
	 * Writes one fixed field of the material as its byte length and its bytes.
	 */
	private static function writeField(\HashContext $context, string $value): void
	{
		hash_update($context, strlen($value) . ':' . $value);
	}

	/**
	 * Writes the canonical form of a value and returns how many bytes it took.
	 */
	private static function writeValue(
		\HashContext $context,
		mixed $value,
		string $parameter,
		int $depth,
		int $limit,
	): int
	{
		if ($depth > self::MAX_DEPTH)
		{
			throw new ArgumentOutOfRangeException($parameter, 1, self::MAX_DEPTH);
		}

		if (is_array($value))
		{
			return array_is_list($value)
				? self::writeList($context, $value, $parameter, $depth, $limit)
				: self::writeMap($context, $value, $parameter, $depth, $limit)
			;
		}

		$fragment = match (true)
		{
			$value === null => 'n;',
			is_bool($value) => $value ? 'b:1;' : 'b:0;',
			is_int($value) => 'i:' . $value . ';',
			is_float($value) => self::encodeFloat($value, $parameter),
			is_string($value) => self::encodeString($value, $parameter),
			default => throw new ArgumentTypeException($parameter, 'null, bool, int, float, string or array'),
		};

		return self::writeFragment($context, $fragment, $parameter, $limit);
	}

	private static function writeList(
		\HashContext $context,
		array $values,
		string $parameter,
		int $depth,
		int $limit,
	): int
	{
		$written = self::writeFragment($context, 'l:' . count($values) . ':', $parameter, $limit);
		foreach ($values as $item)
		{
			$written += self::writeValue($context, $item, $parameter, $depth + 1, $limit - $written);
		}
		$written += self::writeFragment($context, ';', $parameter, $limit - $written);

		return $written;
	}

	private static function writeMap(
		\HashContext $context,
		array $values,
		string $parameter,
		int $depth,
		int $limit,
	): int
	{
		$keys = array_map('strval', array_keys($values));
		sort($keys, SORT_STRING);

		$written = self::writeFragment($context, 'm:' . count($keys) . ':', $parameter, $limit);
		foreach ($keys as $key)
		{
			$encodedKey = self::encodeKey($key, $parameter);

			$written += self::writeFragment($context, $encodedKey, $parameter, $limit - $written);
			$written += self::writeValue($context, $values[$key], $parameter, $depth + 1, $limit - $written);
		}
		$written += self::writeFragment($context, ';', $parameter, $limit - $written);

		return $written;
	}

	private static function writeFragment(
		\HashContext $context,
		string $fragment,
		string $parameter,
		int $limit,
	): int
	{
		$length = strlen($fragment);
		if ($length > $limit)
		{
			throw new ArgumentOutOfRangeException($parameter, null, self::MAX_CALLER_CANONICAL_SIZE);
		}

		hash_update($context, $fragment);

		return $length;
	}

	private static function encodeFloat(float $value, string $parameter): string
	{
		if (!is_finite($value))
		{
			throw new ArgumentTypeException($parameter, 'finite float');
		}

		return 'd:' . sprintf('%.17G', $value) . ';';
	}

	private static function encodeString(string $value, string $parameter): string
	{
		self::assertUtf8($value, $parameter);

		return 's:' . strlen($value) . ':' . $value . ';';
	}

	private static function encodeKey(string $key, string $parameter): string
	{
		self::assertUtf8($key, $parameter);

		return 'k:' . strlen($key) . ':' . $key . ';';
	}

	private static function assertUtf8(string $value, string $parameter): void
	{
		if (!mb_check_encoding($value, 'UTF-8'))
		{
			throw new ArgumentTypeException($parameter, 'valid UTF-8 string');
		}
	}
}

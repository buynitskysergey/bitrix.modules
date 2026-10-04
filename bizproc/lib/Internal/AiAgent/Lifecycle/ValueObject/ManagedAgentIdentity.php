<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\AiAgent\Lifecycle\ValueObject;

use Bitrix\Bizproc\Internal\Entity\AiAgent\ManagedAgentInstance;
use Bitrix\Bizproc\Public\Command\AiAgent\Dto\SystemAiAgentContext;
use Bitrix\Main\ArgumentOutOfRangeException;
use Bitrix\Main\ArgumentTypeException;

/**
 * Logical identity of a managed system AI agent instance: its system code plus its external context.
 *
 * The identity can only exist in a valid state, therefore the checked lengths repeat the columns of
 * b_bp_managed_agent_instance and a built identity always fits the table. Both context tokens are
 * normalized to lower case before they are checked and hashed, so the identity of an object does not
 * depend on the letter case its owner passed.
 *
 * The hash is SHA-256 over a versioned sequence of length prefixed fields: every field is encoded together
 * with its byte length, so no combination of separators inside a value can produce the sequence of another
 * combination of fields, and the explicit version keeps a future change of the sequence distinguishable.
 * The hash alone never confirms an instance: {@see self::matchesStoredComponents()} compares the components
 * stored next to it.
 *
 * No rejection carries the checked value, because the identity is built from caller input and neither
 * errors nor the log are allowed to expose it.
 */
final class ManagedAgentIdentity
{
	public const HASH_FORMAT_VERSION = 'bizproc.system_ai_agent.identity.v1';

	public const MAX_SYSTEM_CODE_LENGTH = 50;

	public const MAX_NAMESPACE_LENGTH = 64;

	public const MAX_TYPE_LENGTH = 64;

	public const MAX_CONTEXT_ID_LENGTH = 128;

	private const TOKEN_PATTERN = '/^[a-z0-9._-]+$/D';

	private const CONTROL_CHARACTER_PATTERN = '/[\p{Cc}\p{Cf}]/u';

	private const UPPERCASE_ASCII = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';

	private const LOWERCASE_ASCII = 'abcdefghijklmnopqrstuvwxyz';

	private readonly string $hash;

	private readonly string $base64UrlHash;

	private function __construct(
		private readonly string $systemCode,
		private readonly string $namespace,
		private readonly string $type,
		private readonly string $contextId,
	)
	{
		$binaryHash = hash('sha256', $this->buildHashMaterial(), true);

		$this->hash = bin2hex($binaryHash);
		$this->base64UrlHash = rtrim(strtr(base64_encode($binaryHash), '+/', '-_'), '=');
	}

	/**
	 * Builds the identity of the pair the public commands accept.
	 *
	 * @throws ArgumentTypeException when a field is not UTF-8, holds a control character or breaks the token alphabet
	 * @throws ArgumentOutOfRangeException when the length of a field is outside the range of the stored column
	 */
	public static function create(string $systemCode, SystemAiAgentContext $context): self
	{
		self::assertText($systemCode, 'systemCode', self::MAX_SYSTEM_CODE_LENGTH);
		self::assertText($context->id, 'context.id', self::MAX_CONTEXT_ID_LENGTH);

		return new self(
			systemCode: $systemCode,
			namespace: self::normalizeToken($context->namespace, 'context.namespace', self::MAX_NAMESPACE_LENGTH),
			type: self::normalizeToken($context->type, 'context.type', self::MAX_TYPE_LENGTH),
			contextId: $context->id,
		);
	}

	public function getSystemCode(): string
	{
		return $this->systemCode;
	}

	public function getNamespace(): string
	{
		return $this->namespace;
	}

	public function getType(): string
	{
		return $this->type;
	}

	public function getContextId(): string
	{
		return $this->contextId;
	}

	/**
	 * Returns the hexadecimal hash stored as IDENTITY_HASH.
	 */
	public function getHash(): string
	{
		return $this->hash;
	}

	/**
	 * Returns the same hash in Base64url without padding: 43 ASCII characters for a name of a bounded length.
	 */
	public function getBase64UrlHash(): string
	{
		return $this->base64UrlHash;
	}

	/**
	 * Confirms that an instance found by the identity hash really belongs to this identity.
	 *
	 * The comparison happens in PHP by strict equality, so it does not depend on the collation of the
	 * database, on letter case or on an implicit conversion.
	 */
	public function matchesStoredComponents(ManagedAgentInstance $instance): bool
	{
		return $instance->getIdentityHash() === $this->hash
			&& $instance->getSystemCode() === $this->systemCode
			&& $instance->getContextNamespace() === $this->namespace
			&& $instance->getContextType() === $this->type
			&& $instance->getContextId() === $this->contextId
		;
	}

	private function buildHashMaterial(): string
	{
		return self::encodeField(self::HASH_FORMAT_VERSION)
			. self::encodeField($this->systemCode)
			. self::encodeField($this->namespace)
			. self::encodeField($this->type)
			. self::encodeField($this->contextId)
		;
	}

	private static function encodeField(string $value): string
	{
		return strlen($value) . ':' . $value;
	}

	private static function normalizeToken(string $value, string $parameter, int $maxLength): string
	{
		self::assertText($value, $parameter, $maxLength);

		$token = strtr($value, self::UPPERCASE_ASCII, self::LOWERCASE_ASCII);
		if (preg_match(self::TOKEN_PATTERN, $token) !== 1)
		{
			throw new ArgumentTypeException($parameter, 'token of [a-z0-9._-] characters');
		}

		return $token;
	}

	private static function assertText(string $value, string $parameter, int $maxLength): void
	{
		if (!mb_check_encoding($value, 'UTF-8'))
		{
			throw new ArgumentTypeException($parameter, 'valid UTF-8 string');
		}

		if (preg_match(self::CONTROL_CHARACTER_PATTERN, $value) !== 0)
		{
			throw new ArgumentTypeException($parameter, 'string without control characters');
		}

		$length = mb_strlen($value);
		if ($length < 1 || $length > $maxLength)
		{
			throw new ArgumentOutOfRangeException($parameter, 1, $maxLength);
		}
	}
}

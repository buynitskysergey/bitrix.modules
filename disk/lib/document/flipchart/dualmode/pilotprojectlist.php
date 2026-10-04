<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Flipchart\DualMode;

use Bitrix\Main\ArgumentException;

/**
 * Parsed value of the flipchart.new_service_group_ids option.
 *
 * Two kinds of corruption with different scopes: a record whose group id parses but whose state does
 * not is Blocked for that group id only, while a token with an unparsable group id makes the whole
 * option corrupted: the project it belonged to is unknown by construction, so it cannot be blocked
 * addressably. Dropping such a token would be fail-open: an already active project would silently
 * resolve to the old instance and its boards would be lost.
 */
final class PilotProjectList
{
	/**
	 * @param array<int, PilotProjectState> $states
	 * @param string[] $corruptedTokens
	 */
	private function __construct(
		private readonly array $states,
		private readonly array $corruptedTokens,
		private readonly bool $hasTokens,
	)
	{
	}

	public static function fromRaw(string $raw): self
	{
		$states = [];
		$corruptedTokens = [];
		$hasTokens = false;

		foreach (explode(',', $raw) as $token)
		{
			$token = trim($token);
			if ($token === '')
			{
				continue;
			}

			$hasTokens = true;

			$parts = explode(':', $token);
			$groupId = self::parseGroupId($parts[0]);
			if ($groupId === null)
			{
				$corruptedTokens[] = $token;

				continue;
			}

			$state = self::parseState($parts);
			if (isset($states[$groupId]) && $states[$groupId] !== $state)
			{
				$state = PilotProjectState::Blocked;
			}

			$states[$groupId] = $state;
		}

		ksort($states);

		return new self($states, $corruptedTokens, $hasTokens);
	}

	public function isEmpty(): bool
	{
		return !$this->hasTokens;
	}

	public function isGloballyCorrupted(): bool
	{
		return $this->corruptedTokens !== [];
	}

	/**
	 * @return string[]
	 */
	public function getCorruptedTokens(): array
	{
		return $this->corruptedTokens;
	}

	public function getState(int $groupId): ?PilotProjectState
	{
		return $this->states[$groupId] ?? null;
	}

	/**
	 * @return array<int, PilotProjectState>
	 */
	public function getStates(): array
	{
		return $this->states;
	}

	/**
	 * Whether the raw option was understood completely: the pilot command refuses to rewrite a
	 * configuration it cannot parse.
	 */
	public function isParsedCompletely(): bool
	{
		if ($this->isGloballyCorrupted())
		{
			return false;
		}

		return !in_array(PilotProjectState::Blocked, $this->states, true);
	}

	/**
	 * Refuses to write on top of a list that was not parsed completely: silently accepting the
	 * mutation would be fail-open and could carry a corrupted or blocked record forward as healthy.
	 *
	 * @throws ArgumentException if the list is not parsed completely, or if $state is Blocked.
	 */
	public function withState(int $groupId, PilotProjectState $state): self
	{
		if (!$this->isParsedCompletely())
		{
			throw new ArgumentException('Pilot project list is not parsed completely, it cannot be rewritten.');
		}

		if ($state === PilotProjectState::Blocked)
		{
			throw new ArgumentException('Blocked is a parser-only state and must never be written back.', 'state');
		}

		$states = $this->states;
		$states[$groupId] = $state;
		ksort($states);

		return new self($states, $this->corruptedTokens, true);
	}

	/**
	 * Refuses to write on top of a list that was not parsed completely: silently accepting the
	 * mutation would be fail-open and could carry a corrupted or blocked record forward as healthy.
	 *
	 * @throws ArgumentException if the list is not parsed completely.
	 */
	public function withoutGroup(int $groupId): self
	{
		if (!$this->isParsedCompletely())
		{
			throw new ArgumentException('Pilot project list is not parsed completely, it cannot be rewritten.');
		}

		$states = $this->states;
		unset($states[$groupId]);

		return new self($states, $this->corruptedTokens, $states !== []);
	}

	/**
	 * Refuses to serialise a list that was not parsed completely: a naive join would silently drop
	 * corrupted tokens, turning a corrupted option into a seemingly healthy one.
	 *
	 * @throws ArgumentException if the list is not parsed completely.
	 */
	public function toRaw(): string
	{
		if (!$this->isParsedCompletely())
		{
			throw new ArgumentException('Pilot project list is not parsed completely, it cannot be rewritten.');
		}

		$tokens = [];
		foreach ($this->states as $groupId => $state)
		{
			$tokens[] = $groupId . ':' . $state->value;
		}

		return implode(',', $tokens);
	}

	/**
	 * The single definition of the group id grammar: the option and the --group-id argument of the
	 * console command must accept exactly the same values.
	 *
	 * Stricter than Vibeoffice\Configuration::normalizeGroupIds(), which checks is_numeric() and would
	 * accept 1e2 as 100, 12.5 as 12 and +12 as 12.
	 */
	public static function parseGroupId(string $value): ?int
	{
		$value = trim($value);
		if (preg_match('/^[0-9]+$/D', $value) !== 1)
		{
			return null;
		}

		$canonical = ltrim($value, '0');
		if ($canonical === '')
		{
			return null;
		}

		$id = (int)$canonical;
		if ($id <= 0 || (string)$id !== $canonical)
		{
			return null;
		}

		return $id;
	}

	/**
	 * @param string[] $parts
	 */
	private static function parseState(array $parts): PilotProjectState
	{
		if (count($parts) > 2)
		{
			return PilotProjectState::Blocked;
		}

		if (!isset($parts[1]))
		{
			return PilotProjectState::Pending;
		}

		return match (trim($parts[1]))
		{
			'pending' => PilotProjectState::Pending,
			'active' => PilotProjectState::Active,
			default => PilotProjectState::Blocked,
		};
	}
}

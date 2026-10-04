<?php

declare(strict_types=1);

namespace Bitrix\Crm\Copilot\CallScriptMaintenance;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Web\Json;

final class FirstScriptCreatedFlag
{
	public const KIND_CREATED = 'created';
	public const KIND_ENRICHED = 'enriched';

	private const STATE_OPTION = 'aha_moment_call_scoring_v2_first_script';

	public function isReached(): bool
	{
		return $this->getScriptId() > 0;
	}

	public function markCreated(int $scriptId): void
	{
		$this->mark(self::KIND_CREATED, $scriptId);
	}

	public function markEnriched(int $scriptId): void
	{
		$this->mark(self::KIND_ENRICHED, $scriptId);
	}

	public function getScriptId(): int
	{
		return (int)($this->getState()['id'] ?? 0);
	}

	public function getKind(): string
	{
		$kind = (string)($this->getState()['kind'] ?? '');

		return in_array($kind, [self::KIND_CREATED, self::KIND_ENRICHED], true) ? $kind : '';
	}

	public function reset(): void
	{
		Option::delete('crm', ['name' => self::STATE_OPTION]);
	}

	private function mark(string $kind, int $scriptId): void
	{
		if ($scriptId <= 0 || $this->isReached())
		{
			return;
		}

		Option::set('crm', self::STATE_OPTION, Json::encode(['kind' => $kind, 'id' => $scriptId]));
	}

	private function getState(): array
	{
		$raw = Option::get('crm', self::STATE_OPTION, '');
		if ($raw === '')
		{
			return [];
		}

		try
		{
			$decoded = Json::decode($raw);
		}
		catch (\Throwable)
		{
			return [];
		}

		return is_array($decoded) ? $decoded : [];
	}
}
<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\AI\Dto\Scoring;

use Bitrix\Crm\Dto\Caster;
use Bitrix\Crm\Dto\Dto;

final class GroupSuspiciousCallsPayload extends Dto
{
	/** @var array<int, array{scriptId: int|null, groupName: string|null, items: int[]}> */
	public array $groups = [];

	public function getCastByPropertyName(string $propertyName): ?Caster
	{
		return match ($propertyName)
		{
			'groups' => new Caster\RawArrayCaster(),
			default => null,
		};
	}
}

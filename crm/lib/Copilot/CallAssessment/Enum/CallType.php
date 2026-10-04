<?php

namespace Bitrix\Crm\Copilot\CallAssessment\Enum;

use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Main\Localization\Loc;

enum CallType: int
{
	case ALL = 1;
	case INCOMING = 2;
	case OUTGOING = 3;

	public static function fromName(string $name): int
	{
		foreach (self::cases() as $status)
		{
			if ($name === $status->name)
			{
				return $status->value;
			}
		}

		throw new \ValueError("$name is not a valid backing value for enum " . self::class);
	}

	public static function getTitle(int $value): ?string
	{
		if ($value === self::ALL->value)
		{
			return Loc::getMessage('CRM_COPILOT_CALL_ASSESSMENT_CALL_TYPE_ALL_MSGVER_1');
		}

		$isCallScoringV2 = AIManager::isCallScoringV2Enabled();

		if ($value === self::INCOMING->value)
		{
			$code = 'CRM_COPILOT_CALL_ASSESSMENT_CALL_TYPE_INCOMING_MSGVER_';
		}
		elseif ($value === self::OUTGOING->value)
		{
			$code = 'CRM_COPILOT_CALL_ASSESSMENT_CALL_TYPE_OUTGOING_MSGVER_';
		}
		else
		{
			return null;
		}

		return Loc::getMessage($code . ($isCallScoringV2 ? '2' : '1'));
	}

	public static function toArray(): array
	{
		return array_column(
			array_map(
				static fn($case) => ['value' => $case->value, 'title' => $case->getTitle($case->value)],
				self::cases(),
			),
			'title',
			'value',
		);
	}
}

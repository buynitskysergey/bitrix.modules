<?php

namespace Bitrix\Crm\Copilot\CallAssessment\Enum;

use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Main\Localization\Loc;

enum ClientType: int
{
	case NEW = 1;
	case IN_WORK = 2;
	case REPEATED_APPROACH = 3;
	case RETURN_CUSTOMER = 4;
	case ANY = 10;

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
		if ($value === self::ANY->value)
		{
			return Loc::getMessage('CRM_COPILOT_CALL_ASSESSMENT_CLIENT_TYPE_ANY');
		}

		$isCallScoringV2 = AIManager::isCallScoringV2Enabled();

		if ($value === self::NEW->value)
		{
			$code = 'CRM_COPILOT_CALL_ASSESSMENT_CLIENT_TYPE_NEW';
		}
		elseif ($value === self::IN_WORK->value)
		{
			$code = 'CRM_COPILOT_CALL_ASSESSMENT_CLIENT_TYPE_IN_WORK';
		}
		elseif ($value === self::REPEATED_APPROACH->value)
		{
			$code = 'CRM_COPILOT_CALL_ASSESSMENT_CLIENT_TYPE_REPEATED_APPROACH';
		}
		elseif ($value === self::RETURN_CUSTOMER->value)
		{
			$code = 'CRM_COPILOT_CALL_ASSESSMENT_CLIENT_TYPE_RETURN_CUSTOMER';
		}
		else
		{
			return null;
		}

		return Loc::getMessage($isCallScoringV2 ? $code . '_MSGVER_1' : $code);
	}

	public static function getTitleList(array $values): array
	{
		$titles = [];

		foreach ($values as $value)
		{
			$title = self::getTitle($value);
			if ($title === null)
			{
				continue;
			}

			$titles[] = $title;
		}

		return $titles;
	}

	public static function implodeTitles(array $values, string $separator = ', '): string
	{
		$titles = self::getTitleList($values);

		return implode($separator, $titles);
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

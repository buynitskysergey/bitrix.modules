<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Activity\Enum;

use Bitrix\Main\Localization\Loc;

enum ActionSource: string
{
	case CURRENT_OBJECT = 'current_object';
	case FILTER_RESULT = 'filter_result';
	case VARIABLE = 'variable';
	case PROCESS_PARAMETER = 'process_parameter';
	case PREVIOUS_BLOCK_RESULT = 'previous_block_result';
	case GROUP_RESULT = 'group_result';
	case MANUAL = 'manual';

	public function getTitle(): string
	{
		return (string)(Loc::getMessage('BIZPROC_ACTION_SOURCE_' . mb_strtoupper($this->value)) ?? '');
	}

	/** @return array{id: string, title: string} */
	public function toArray(): array
	{
		return ['id' => $this->value, 'title' => $this->getTitle()];
	}
}

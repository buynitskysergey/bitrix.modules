<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Activity\Enum;

use Bitrix\Main\Localization\Loc;
use Bitrix\Ui\Public\Enum\IconSet\Outline;

enum ActionArea: string
{
	case CRM = 'crm';
	case SMART_PROCESS = 'smart_process';
	case TASKS = 'tasks';
	case BIZPROC = 'bizproc';
	case DISK = 'disk';
	case CALENDAR = 'calendar';
	case MAIL = 'mail';
	case MESSENGER = 'messenger';
	case OPEN_LINES = 'open_lines';
	case ECOMMERCE = 'ecommerce';
	case CATALOG = 'catalog';
	case LISTS = 'lists';
	case HR = 'hr';
	case SIGN = 'sign';
	case TIMEMAN = 'timeman';
	case VIDEOCALLS = 'videocalls';
	case EXTERNAL = 'external';

	/** Module gate (scope 7): an area is offered only when its module is installed. */
	public function getModuleId(): string
	{
		return match ($this)
		{
			// SMART_PROCESS lives inside crm (dynamic types).
			self::CRM, self::SMART_PROCESS => 'crm',
			self::TASKS => 'tasks',
			self::BIZPROC => 'bizproc',
			self::DISK => 'disk',
			self::CALENDAR => 'calendar',
			self::MAIL => 'mail',
			// VIDEOCALLS are served by the im module (calls subsystem).
			self::MESSENGER, self::VIDEOCALLS => 'im',
			self::OPEN_LINES => 'imopenlines',
			self::ECOMMERCE => 'sale',
			self::CATALOG => 'catalog',
			self::LISTS => 'lists',
			self::HR => 'humanresources',
			self::SIGN => 'sign',
			self::TIMEMAN => 'timeman',
			self::EXTERNAL => 'rest',
		};
	}

	public function getTitle(): string
	{
		return (string)(Loc::getMessage('BIZPROC_ACTION_AREA_' . mb_strtoupper($this->value)) ?? '');
	}

	public function getIcon(): string
	{
		// Illustrative Outline names; final icon mapping to confirm with design.
		return match ($this)
		{
			self::CRM, self::SMART_PROCESS => Outline::CRM->name,
			self::TASKS => Outline::TASK->name,
			self::CALENDAR => Outline::CALENDAR_WITH_SLOTS->name,
			self::MESSENGER, self::OPEN_LINES, self::VIDEOCALLS => Outline::CHATS->name,
			self::DISK, self::SIGN, self::MAIL => Outline::FILE->name,
			default => '',
		};
	}

	/** @return array{id: string, title: string, icon: string} */
	public function toArray(): array
	{
		return ['id' => $this->value, 'title' => $this->getTitle(), 'icon' => $this->getIcon()];
	}
}

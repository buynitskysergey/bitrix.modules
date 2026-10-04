<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Activity\Enum;

use Bitrix\Main\Localization\Loc;
use Bitrix\Ui\Public\Enum\IconSet\Outline;

enum ActionGroup: string
{
	case CREATE = 'create';
	case UPDATE = 'update';
	case CHANGE_STATUS = 'change_status';
	case ASSIGN = 'assign';
	case ADD = 'add';
	case SEND = 'send';
	case GET = 'get';
	case RUN = 'run';
	case WRITE = 'write';
	case STOP = 'stop';
	case GRANT_ACCESS = 'grant_access';
	case REVOKE_ACCESS = 'revoke_access';
	case ATTACH = 'attach';
	case CALL = 'call';
	// Excluded forever (PRD §14): "Связать"/"Отвязать" → "Связи" block, "Найти" → "Фильтр" block.

	public function getTitle(): string
	{
		// Backend-side titles for catalog/AI consumers. The UI menu currently reads the
		// frontend keys BIZPROCDESIGNER_EDITOR_NODE_SETTINGS_ACTION_GROUP_* (Errata E-1).
		return (string)(Loc::getMessage('BIZPROC_ACTION_GROUP_' . mb_strtoupper($this->value)) ?? '');
	}

	public function getIcon(): string
	{
		// Illustrative Outline names (Q-AFC-8); final icon mapping to confirm with design.
		return match ($this)
		{
			self::CREATE => Outline::PLUS_M->name,
			self::UPDATE => Outline::EDIT_M->name,
			self::CHANGE_STATUS => Outline::STAGES->name,
			self::ASSIGN => Outline::PERSON->name,
			self::ADD => Outline::CIRCLE_PLUS->name,
			self::SEND => Outline::NOTIFICATION->name,
			self::GET => Outline::DOWNLOAD->name,
			self::RUN => Outline::ROCKET->name,
			self::WRITE => Outline::DATABASE->name,
			self::STOP => Outline::STOP_M->name,
			self::GRANT_ACCESS => Outline::UNLOCK_M->name,
			self::REVOKE_ACCESS => Outline::LOCK_M->name,
			self::ATTACH => Outline::ATTACH->name,
			self::CALL => Outline::WEBHOOK->name,
		};
	}
}

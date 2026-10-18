<?php

namespace Bitrix\Im\V2\Integration\AI\TaskCreation;

enum AutoTaskType: string
{
	case TaskCreation = 'taskCreation';
	case TaskCreationMulti = 'taskCreationMulti';

	public function buildEntityId(array $messageIds): string
	{
		return match ($this)
		{
			self::TaskCreation => (string)(int)current($messageIds),
			self::TaskCreationMulti => self::buildMessageSetHash($messageIds),
		};
	}

	private static function buildMessageSetHash(array $messageIds): string
	{
		$ids = array_unique(array_map('intval', $messageIds));
		sort($ids);

		return sha1(implode(',', $ids));
	}
}

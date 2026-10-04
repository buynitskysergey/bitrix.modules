<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Access;

use Bitrix\Main\Access\AccessibleItem;
use Bitrix\Main\Access\BaseAccessController;
use Bitrix\Main\Access\Exception\UnknownActionException;
use Bitrix\Main\Access\User\AccessibleUser;
use Bitrix\Note\Internal\Access\Model\CollectionAccessItem;
use Bitrix\Note\Internal\Access\Model\UserAccessItem;
use Bitrix\Note\Internal\Access\Rule\Factory\NoteRuleFactory;

final class AccessController extends BaseAccessController
{
	public function __construct(int $userId)
	{
		parent::__construct($userId);

		$this->user = $this->loadUser($userId);
		$this->ruleFactory = new NoteRuleFactory();
	}

	protected function loadItem(?int $itemId = null): ?AccessibleItem
	{
		if ($itemId)
		{
			return CollectionAccessItem::createFromId($itemId);
		}

		return null;
	}

	protected function loadUser(int $userId): AccessibleUser
	{
		return UserAccessItem::createFromId($userId);
	}

	public static function getCurrent(): self
	{
		global $USER;

		$userId = 0;
		if (isset($USER) && $USER instanceof \CUser)
		{
			$userId = (int)$USER->GetID();
		}

		return static::getInstance($userId);
	}

	/**
	 * @throws UnknownActionException
	 */
	public function check(string $action, ?AccessibleItem $item = null, $params = null): bool
	{
		if ($this->isPortalAdmin())
		{
			return true;
		}

		if (
			$action !== ActionDictionary::ACTION_NOTE_ACCESS
			&& !parent::check(ActionDictionary::ACTION_NOTE_ACCESS, $item, ['action' => ActionDictionary::ACTION_NOTE_ACCESS])
		)
		{
			return false;
		}

		$params ??= [];
		if (is_array($params))
		{
			$params['action'] = $action;
		}

		return parent::check($action, $item, $params);
	}

	private function isPortalAdmin(): bool
	{
		return (bool)$this->user?->isAdmin();
	}
}

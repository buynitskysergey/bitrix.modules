<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Access;

use Bitrix\Bizproc\Internal\Access\Model\UserModel;
use Bitrix\Bizproc\Internal\Access\Rule\Factory\RuleFactory;
use Bitrix\Main\Access\AccessibleItem;
use Bitrix\Main\Access\BaseAccessController;
use Bitrix\Main\Loader;

class AccessController extends BaseAccessController
{
	public function __construct(int $userId)
	{
		parent::__construct($userId);

		$this->ruleFactory = new RuleFactory();
	}

	public static function getCurrent(): static
	{
		global $USER;

		$userId = 0;
		if (isset($USER) && $USER instanceof \CUser)
		{
			$userId = (int)$USER->GetID();
		}

		return static::getInstance($userId);
	}

	public function check(string $action, ?AccessibleItem $item = null, $params = null): bool
	{
		if ($this->isAdmin())
		{
			return true;
		}

		$params ??= [];
		if (is_array($params))
		{
			$params['action'] = $action;
		}

		return parent::check($action, $item, $params);
	}

	/**
	 * Effective access = portal administrator OR a bizproc access role. There is no OR with the legacy
	 * `CreateWorkflow` delegation: for the default `WORKFLOW` document type it returns true for everyone
	 * and would nullify this ACL.
	 */
	public function isAdmin(): bool
	{
		return $this->user->isAdmin()
			|| (Loader::includeModule('bitrix24') && \CBitrix24::isPortalAdmin($this->user->getUserId()));
	}

	protected function loadItem(?int $itemId = null): ?AccessibleItem
	{
		return null;
	}

	protected function loadUser(int $userId): UserModel
	{
		return UserModel::createFromId($userId);
	}
}

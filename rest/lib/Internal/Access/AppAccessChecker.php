<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Access;

use Bitrix\Rest\Internal\Access\App\AppAction;
use Bitrix\Rest\Internal\Access\App\AppAccessController;
use Bitrix\Rest\Internal\Access\App\Model\AppModel;
use Bitrix\Rest\Internal\Entity;

class AppAccessChecker
{
	private AppAccessController $controller;

	public function __construct(private readonly int $userId)
	{
		$this->controller = AppAccessController::getInstance($this->userId);
	}

	public function canInstallLocal(): bool
	{
		return $this->controller->check(AppAction::InstallLocalApp);
	}

	public function canUninstallLocal(Entity\Application\App $app): bool
	{
		return $this->controller->check(
			AppAction::UninstallLocalApp,
			AppModel::createFromApp($app),
		);
	}

	public function canInstallPersonal(): bool
	{
		return $this->controller->check(AppAction::InstallPersonalApp);
	}

	public function canUninstallPersonal(Entity\Application\App $app): bool
	{
		return $this->controller->check(
			AppAction::UninstallPersonalApp,
			AppModel::createFromApp($app),
		);
	}

	public function canAccessApp(Entity\Application\App $app): bool
	{
		return $this->controller->check(
			AppAction::UseApp,
			AppModel::createFromApp($app),
		);
	}

	public function canManageAppAccess(Entity\Application\App $app): bool
	{
		return $this->controller->check(
			AppAction::ManageAppAccess,
			AppModel::createFromApp($app),
		);
	}

	public function canInstallEmbedding(Entity\Application\App $app): bool
	{
		if ($app->isPersonal())
		{
			$canInstall = ($this->isAdmin() || $app->isOwnedBy($this->userId))
				&& $this->canInstallPersonal();
		}
		else
		{
			$canInstall = $this->canInstallLocal()
				&& ($this->isAdmin() || $app->getOrigin() === Entity\Application\AppOrigin::Local);
		}

		return $canInstall && $this->canAccessApp($app);
	}

	public function canUninstallEmbedding(Entity\Application\App $app): bool
	{
		if ($app->isPersonal())
		{
			$canUninstall = $this->canUninstallPersonal($app);
		}
		else
		{
			$canUninstall = $this->canUninstallLocal($app)
				&& ($this->isAdmin() || $app->getOrigin() === Entity\Application\AppOrigin::Local);
		}

		return $canUninstall && $this->canAccessApp($app);
	}

	public function canViewEmbeddingList(Entity\Application\App $app): bool
	{
		return $this->canAccessApp($app);
	}

	public function canViewAllEmbeddings(Entity\Application\App $app): bool
	{
		return $this->controller->check(
			AppAction::ViewAllEmbeddings,
			AppModel::createFromApp($app),
		);
	}

	public function canViewPlacementList(Entity\Application\App $app): bool
	{
		return $this->canAccessApp($app);
	}

	public function canViewInstalledList(): bool
	{
		return $this->controller->check(AppAction::ViewInstalledList);
	}

	private function isAdmin(): bool
	{
		return \CRestUtil::isAdmin($this->userId);
	}
}

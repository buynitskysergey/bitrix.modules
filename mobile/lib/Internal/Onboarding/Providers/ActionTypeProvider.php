<?php

namespace Bitrix\Mobile\Internal\Onboarding\Providers;

use Bitrix\Mobile\Context;
use Bitrix\Mobile\Internal\Onboarding\PushType;
use Bitrix\Mobile\Tab\Manager;

class ActionTypeProvider
{
	private ?Manager $tabManager = null;

	public function __construct(private readonly int $userId)
	{}

	public function getActionType(PushType $type): string
	{
		$manager = $this->getTabManager();

		if ($manager !== null)
		{
			try
			{
				if ($manager->isTabActive($type->value))
				{
					return $type->getActionType();
				}
			}
			catch (\Throwable $e)
			{
				AddMessage2Log('Onboarding push: isTabActive failed — ' . $e->getMessage(), 'mobile');
			}
		}

		$actionMoreType = $type->getActionMoreType();
		if ($actionMoreType)
		{
			return $actionMoreType;
		}

		return $type->getActionType();
	}

	private function getTabManager(): ?Manager
	{
		if ($this->tabManager !== null)
		{
			return $this->tabManager;
		}

		if (!class_exists(Context::class) || !class_exists(Manager::class))
		{
			return null;
		}

		try
		{
			$context = new Context(['userId' => $this->userId]);
			$this->tabManager = new Manager($context);
		}
		catch (\Throwable $e)
		{
			AddMessage2Log('Onboarding push: tab manager init failed — ' . $e->getMessage(), 'mobile');

			return null;
		}

		return $this->tabManager;
	}
}

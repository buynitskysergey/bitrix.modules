<?php

declare(strict_types=1);

namespace Bitrix\Market\Application;

use Bitrix\Market\Internal\Integration\Bitrix24\VibePlusUpsellAdapter;
use Bitrix\Market\Internal\Integration\Rest\VibePlusLimitAdapter;

final class VibePlusApplicationLimit
{
	public const INSTALLED_LIST_URL = '/market/installed/?vibe_plus_limit=Y';

	private readonly VibePlusLimitAdapter $restAdapter;
	private readonly VibePlusUpsellAdapter $bitrix24Adapter;

	public function __construct(
		?VibePlusLimitAdapter $restAdapter = null,
		?VibePlusUpsellAdapter $bitrix24Adapter = null,
		private readonly ?bool $canManageApplications = null,
	)
	{
		$this->restAdapter = $restAdapter ?? new VibePlusLimitAdapter();
		$this->bitrix24Adapter = $bitrix24Adapter ?? new VibePlusUpsellAdapter();
	}

	public function getProjection(): array
	{
		$limit = $this->restAdapter->getLimitProjection();
		$transition = $this->bitrix24Adapter->getTransitionState();
		$actions = [];

		if ($this->shouldProvideActions($limit, $transition))
		{
			try
			{
				$canManageApplications = $this->canManageApplications ?? InstalledListAccess::isAdmin();
			}
			catch (\Throwable)
			{
				$canManageApplications = false;
			}

			if ($canManageApplications)
			{
				$actions[] = [
					'type' => 'list',
					'target' => self::INSTALLED_LIST_URL,
				];
			}

			$upsellAction = $this->bitrix24Adapter->getUpsellAction();
			if ($upsellAction !== null)
			{
				$actions[] = $upsellAction;
			}
		}

		return [
			'limit' => $limit,
			'transition' => $transition,
			'actions' => $actions,
		];
	}

	/**
	 * @return string[]
	 */
	public function getCountedApplicationCodes(): array
	{
		return $this->restAdapter->getCountedApplicationCodes();
	}

	private function shouldProvideActions(array $limit, array $transition): bool
	{
		if ($limit['installationBlocked'] === true)
		{
			return true;
		}

		return
			$transition['active'] === true
			&& $transition['endsAt'] !== null
			&& $limit['state'] === 'unlimited'
			&& is_int($limit['count'])
			&& is_int($limit['configuredLimit'])
			&& $limit['configuredLimit'] >= 0
			&& $limit['count'] > $limit['configuredLimit'];
	}
}

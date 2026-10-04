<?php

namespace Bitrix\Mobile\Controller;

use Bitrix\Main\Engine\ActionFilter\CloseSession;
use Bitrix\Main\Engine\JsonController;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Socialnetwork\Promotion\ProjectAi;

final class ProjectPromotion extends JsonController
{
	public function configureActions(): array
	{
		return [
			'shouldShow' => [
				'+prefilters' => [
					new CloseSession(),
				],
			],
			'setViewed' => [
				'+prefilters' => [
					new CloseSession(),
				],
			],
		];
	}

	/**
	 * Returns whether the new projects promotion should be shown to the current user.
	 *
	 * @restMethod mobile.ProjectPromotion.shouldShow
	 * @return bool
	 * @throws LoaderException
	 */
	public function shouldShowAction(): bool
	{
		Loader::requireModule('socialnetwork');

		$userId = (int)$this->getCurrentUser()->getId();

		return (new ProjectAi())->shouldShow($userId);
	}

	/**
	 * Marks the new projects promotion as viewed by the current user.
	 *
	 * @restMethod mobile.ProjectPromotion.setViewed
	 * @return bool
	 * @throws LoaderException
	 */
	public function setViewedAction(): bool
	{
		Loader::requireModule('socialnetwork');

		$userId = (int)$this->getCurrentUser()->getId();

		return (new ProjectAi())->setViewed($userId);
	}
}

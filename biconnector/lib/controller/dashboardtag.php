<?php

namespace Bitrix\BIConnector\Controller;

use Bitrix\BIConnector\Integration\Superset\Integrator\IntegratorResponse;
use Bitrix\BIConnector\Integration\Superset\Integrator\ProxyIntegrator;
use Bitrix\BIConnector\Integration\Superset\Model;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTagTable;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetTagTable;
use Bitrix\BIConnector\Integration\Superset\SupersetController;
use Bitrix\BIConnector\Superset\Dashboard\EmbeddedFilter;
use Bitrix\BIConnector\Integration\Superset\SupersetInitializer;
use Bitrix\BIConnector\Superset\Grid\DashboardGrid;
use Bitrix\BIConnector\Superset\Grid\Settings\DashboardSettings;
use Bitrix\BIConnector\Superset\Logger\Logger;
use Bitrix\BIConnector\Superset\MarketDashboardManager;
use Bitrix\Intranet\ActionFilter\IntranetUser;
use Bitrix\Main\Application;
use Bitrix\Main\Engine\ActionFilter\Scope;
use Bitrix\Main\Engine\AutoWire\ExactParameter;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\Response\Converter;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\Type\Date;
use Bitrix\Main\Web\Uri;

class DashboardTag extends Controller
{
	/**
	 * @return array
	 */
	protected function getDefaultPreFilters(): array
	{
		$additionalFilters = [
			new Scope(Scope::AJAX),
		];

		if (Loader::includeModule('intranet'))
		{
			$additionalFilters[] = new IntranetUser();
		}

		return [
			...parent::getDefaultPreFilters(),
			...$additionalFilters,
		];
	}

	/**
	 * @param string $title
	 *
	 * @return array|null
	 */
	public function addAction(string $title): ?array
	{
		$userId = $this->getCurrentUser()->getId();

		$userTag = SupersetTagTable::getRow([
			'filter' => [
				'=USER_ID' => $userId,
				'=TITLE' => $title,
			],
		]);

		if ($userTag)
		{
			$this->addError(new Error(Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_TAG_SAVE_ERROR_HAS_EXIST_TAG')));

			return null;
		}

		$result = SupersetTagTable::add([
			'USER_ID' => $userId,
			'TITLE' => $title,
		]);


		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return [
			'ID' => $result->getId(),
			'TITLE' => $title,
		];
	}

	/**
	 * @param int $id
	 * @param string $title
	 *
	 * @return bool|null
	 */
	public function renameAction(int $id, string $title): ?bool
	{
		$userId = $this->getCurrentUser()->getId();

		$tag = SupersetTagTable::getList([
				'filter' => [
					'=USER_ID' => $userId,
					'=ID' => $id,
				],
			])
			->fetchObject()
		;

		if (!$tag)
		{
			$this->addError(new Error(Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_TAG_SAVE_ERROR_EMPTY_TAG')));

			return null;
		}

		$existedTitle = SupersetTagTable::getRow([
			'filter' => [
				'=USER_ID' => $userId,
				'=TITLE' => $title,
			],
			'select' => ['ID'],
		]);

		if ($existedTitle && $id !== (int)$existedTitle['ID'])
		{
			$this->addError(new Error(Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_TAG_SAVE_ERROR_HAS_EXIST_TAG')));

			return null;
		}

		$tag->setTitle($title);

		$result = $tag->save();
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return true;
	}

	/**
	 * @param int $id
	 *
	 * @return bool|null
	 */
	public function deleteAction(int $id): ?bool
	{
		$userId = $this->getCurrentUser()->getId();

		$tag = SupersetTagTable::getList([
				'filter' => [
					'=USER_ID' => $userId,
					'=ID' => $id,
				],
			])
			->fetchObject()
		;

		if (!$tag)
		{
			$this->addError(new Error(Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_TAG_SAVE_ERROR_EMPTY_TAG')));

			return null;
		}

		$tagBindings = SupersetDashboardTagTable::getList([
				'filter' => [
					'=TAG_ID' => $id,
				],
			])
			->fetchCollection()
		;

		foreach ($tagBindings as $binding)
		{
			$binding->delete();
		}

		$tag->delete();

		return true;
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\CustomController\DetailPageLayout;

use Bitrix\Crm\Feature\RestV3CrudDeal;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\DetailPageLayout\LayoutDto;
use Bitrix\Crm\V2\Public\OwnerType;

final class RouteBuilder
{
	public const FEATURE = RestV3CrudDeal::class;

	private const ENTITY_TYPES = [
		'lead' => OwnerType::LEAD,
		'deal' => OwnerType::DEAL,
		'contact' => OwnerType::CONTACT,
		'company' => OwnerType::COMPANY,
		'quote' => OwnerType::QUOTE,
		'smartInvoice' => OwnerType::SMART_INVOICE,
		'smartDocument' => OwnerType::SMART_DOCUMENT,
		'smartB2eDocument' => OwnerType::SMART_B2E_DOCUMENT,
	];

	private const ACTIONS = [
		'getCurrent' => 'getCurrentLayout',
		'setCurrent' => 'setCurrent',
		'getPersonalLayoutId' => 'getPersonalLayoutId',
		'getCommonLayoutId' => 'getCommonLayoutId',
		'setCommonForAll' => 'setCommonForAll',
		'add' => 'add',
		'update' => 'update',
		'delete' => 'delete',
		'get' => 'get',
		'list' => 'list',
	];

	/** @return array<string, array> */
	public function buildRoutes(): array
	{
		$routes = [];
		foreach (self::ENTITY_TYPES as $segment => $entityTypeId)
		{
			$routes = array_merge($routes, $this->buildRoutesForEntity($segment, $entityTypeId));
		}

		return $routes;
	}

	/** @return array<string, array> */
	public function buildRoutesForEntity(string $segment, int $entityTypeId): array
	{
		$routes = [];
		foreach (self::ACTIONS as $action => $controllerMethod)
		{
			$routes['crm.' . $segment . '.detailPageLayout.' . $action] = [
				'controller' => DetailPageLayout::class,
				'method' => $controllerMethod,
				'dtoFqcn' => LayoutDto::class,
				'entityTypeId' => $entityTypeId,
				'scopes' => ['crm'],
			];
		}

		return $routes;
	}
}

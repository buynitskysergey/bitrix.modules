<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Product;

use Bitrix\Crm\V2\Infrastructure\Rest\CustomController\AbstractPortalAdminController;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Product\ProductDtoMapper;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Product\ProductListRequestMapper;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Product\ProductDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Product\ListProductRequest;
use Bitrix\Crm\V2\Internal\Entity\Product\ProductCard;
use Bitrix\Crm\V2\Internal\Repository\Product\ProductRepository;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\LocalizableMessage;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\Provider\Params\Pager;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Exception\Validation\RequestFilterValidationException;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;

/**
 * `crm.product.list` - the cards of the CRM product catalog, read by a portal administrator.
 *
 * The subject is the catalogue entry, not its use inside an Item: this method shares no class with the
 * group of product rows and belongs to no owner. What it does share with them is the way the route is
 * registered and the feature flag of REST 3.0 in CRM.
 *
 * Who may call it is decided by {@see AbstractPortalAdminController} before the action runs. The name is
 * the one the legacy method of the same resource carries, and that is not a collision: the two live at
 * different addresses, as `crm.deal.*` already does.
 *
 * The route names no entity type, so it has no service parameters of its own and the source parameters of
 * the action are the client's query string alone. Nothing is read from there - the whole request is the
 * typed body, and the answer is the standard list.
 *
 * The read goes to {@see ProductRepository} of `V2\Internal` directly: the scenario is a single read of a
 * catalog card, and a public contract of the catalog would be a refactoring out of all proportion to it.
 * The dependency stays inside REST and carries no compatibility promise.
 */
#[DtoType(ProductDto::class)]
final class Product extends AbstractPortalAdminController
{
	/** Where a catalog of the CRM is chosen, in the notation of the answer. */
	private const CATALOG_ID_FILTER_FIELD = 'filter.' . ProductCard::FIELD_CATALOG_ID;

	public function listAction(ListProductRequest $request): ListResponse
	{
		$requestMapper = new ProductListRequestMapper();
		$selectedFields = $requestMapper->mapSelect($request);
		$cards = $this->findCards(
			$requestMapper->mapFilter($request->filter),
			$requestMapper->mapOrder($request->order),
			$requestMapper->mapPager($request->pagination),
			$selectedFields,
			$requestMapper->mapCatalogId($request->filter),
		);

		return new ListResponse((new ProductDtoMapper())->getDtoCollectionByProductCards($cards, $selectedFields));
	}

	/**
	 * The page as the repository reads it. A missing `iblock` or `catalog` is not caught: a card cannot be
	 * answered without either of them, and the framework turns such a failure into a system error of REST
	 * v3, logged and without its text.
	 *
	 * Of the refusals the read makes, only one is the client's: a catalog that is not one of the CRM.
	 * Everything else the repository refuses is a field it cannot answer for - the framework rejects a
	 * field without `Filterable` or `Sortable` long before this, so what is left is a DTO and a repository
	 * that disagree. That is a defect of the portal and is left to the framework for the same treatment as
	 * a missing module: a system error with the failure in the log. Answering it as a malformed filter
	 * would tell the client to fix something it did not write.
	 *
	 * @param array<string, string> $order
	 * @param string[] $selectedFields
	 * @param int|null $catalogId the catalog the client chose; `null` reads the default one of the CRM.
	 * @return ProductCard[]
	 * @throws RequestFilterValidationException the catalog the client named is not one of the CRM.
	 * @throws ArgumentException a filter or an ordering over a field the read cannot answer for.
	 */
	private function findCards(
		?ConditionTree $filter,
		array $order,
		Pager $pager,
		array $selectedFields,
		?int $catalogId,
	): array
	{
		try
		{
			return (new ProductRepository())->findAll(
				$filter,
				$order,
				$pager->getLimit(),
				$pager->getOffset(),
				$selectedFields,
				$catalogId,
			);
		}
		catch (ArgumentException $exception)
		{
			if ($exception->getParameter() !== ProductCard::FIELD_CATALOG_ID)
			{
				throw $exception;
			}

			throw new RequestFilterValidationException(self::refusedCatalogErrors($catalogId));
		}
	}

	/**
	 * What the client is told about a catalog the read refused: the field it came in, and what is wrong
	 * with it.
	 *
	 * @return Error[]
	 */
	private static function refusedCatalogErrors(?int $catalogId): array
	{
		return [
			new Error(
				new LocalizableMessage(
					'CRM_V2_REST_PRODUCT_CATALOG_IS_NOT_A_CRM_ONE',
					['#CATALOG_ID#' => (string)$catalogId],
				),
				self::CATALOG_ID_FILTER_FIELD,
			),
		];
	}
}

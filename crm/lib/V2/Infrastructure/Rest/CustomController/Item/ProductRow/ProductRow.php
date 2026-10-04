<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Item\ProductRow;

use Bitrix\Crm\V2\Infrastructure\Rest\CustomController\AbstractController;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\ProductRow\ProductRowDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Item\ProductRow\ProductRowDtoMapper;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Item\ProductRow\ProductRowListRequestMapper;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Item\ProductRow\AddProductRowRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Item\ProductRow\AvailableForPaymentRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Item\ProductRow\DeleteProductRowRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Item\ProductRow\GetProductRowRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Item\ProductRow\ListProductRowRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Item\ProductRow\ReplaceProductRowsRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Item\ProductRow\UpdateProductRowRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Response\Item\ProductRow\ReplaceProductRowsResponse;
use Bitrix\Crm\V2\Infrastructure\Rest\ResponseMapper\Item\ProductRow\ProductRowErrorMapper;
use Bitrix\Crm\V2\Public\Command\Item\ProductRow\AddCommand;
use Bitrix\Crm\V2\Public\Command\Item\ProductRow\DeleteCommand;
use Bitrix\Crm\V2\Public\Command\Item\ProductRow\ReplaceCommand;
use Bitrix\Crm\V2\Public\Command\Item\ProductRow\UpdateCommand;
use Bitrix\Crm\V2\Public\Command\Item\Scope;
use Bitrix\Crm\V2\Public\Entity\Item;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Crm\V2\Public\Provider\Item\Exception\UnknownSortFieldException;
use Bitrix\Crm\V2\Public\Provider\Item\ProductRow\Param\ProductRowSort;
use Bitrix\Crm\V2\Public\Provider\Item\ProductRow\ProductRowProvider;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Attribute\RequiredGroup;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Exception\EntityNotFoundException;
use Bitrix\Rest\V3\Exception\UnknownDtoPropertyException;
use Bitrix\Rest\V3\Exception\Validation\DtoValidationException;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Interaction\Response\AddResponse;
use Bitrix\Rest\V3\Interaction\Response\DeleteResponse;
use Bitrix\Rest\V3\Interaction\Response\GetResponse;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;
use Bitrix\Rest\V3\Structure\FieldsStructure;

/**
 * `crm.{entity}.productRow.*` - the whole group, on one implementation.
 *
 * The type of the owner is an invariant of the route: it is set as an integer in
 * {@see \Bitrix\Crm\V2\Infrastructure\Rest\CustomController\SchemaProvider} and reaches an action by
 * auto-wiring ({@see AbstractController::getEntityType()}). Neither the query string nor the body is ever
 * read for it, so `crm.deal.productRow.add` writes rows of a deal and of nothing else.
 *
 * Nothing of the subject matter is decided here. The rights, the list of writable fields, normalization
 * and the existence of the owner are the scenario's, and this class does three things: it turns the
 * request into the arguments of one scenario, it calls it, and it turns its answer - successful or not -
 * into the standard answer of REST v3.
 *
 * The user whose rights are checked is the one the infrastructure of the controller resolved, and it is
 * passed explicitly to every scenario and to the check of the references a request carries. A user
 * identifier from the body is not a subject of a rights check and is not read anywhere in this group.
 */
#[DtoType(ProductRowDto::class)]
final class ProductRow extends AbstractController
{
	public function addAction(AddProductRowRequest $request, EntityType $entityType): AddResponse
	{
		$mapper = new ProductRowDtoMapper($entityType, $this->userId);
		$dto = $mapper->createDtoByFields($request->fields, RequiredGroup::Add->value);
		$ownerId = $mapper->getOwnerIdByDto($dto);

		$result =
			(new AddCommand($entityType, $ownerId, $mapper->getFieldsByDtoWithoutOwner($dto), $this->userId))
				->setScope(Scope::Rest)
				->run()
		;
		(new ProductRowErrorMapper())->throwOnFailure($result, $ownerId);

		/** @var Item\ProductRow $row */
		$row = $result->getData()[AddCommand::DATA_PRODUCT_ROW];

		return new AddResponse((int)$row->getId());
	}

	/**
	 * The answer is the row after the write: normalization decides what a sent value became, and a
	 * client told only that the write succeeded would have to read the row again to learn it.
	 */
	public function updateAction(UpdateProductRowRequest $request, EntityType $entityType): GetResponse
	{
		$mapper = new ProductRowDtoMapper($entityType, $this->userId);
		$dto = $mapper->createDtoByFields($request->fields, RequiredGroup::Update->value);

		$result =
			(new UpdateCommand($entityType, $request->id, $mapper->getFieldsByDto($dto), $this->userId))
				->setScope(Scope::Rest)
				->run()
		;
		(new ProductRowErrorMapper())->throwOnFailure($result, $request->id);

		/** @var Item\ProductRow $row */
		$row = $result->getData()[UpdateCommand::DATA_PRODUCT_ROW];

		return new GetResponse($mapper->getDtoByProductRow($row, $request));
	}

	public function getAction(GetProductRowRequest $request, EntityType $entityType): GetResponse
	{
		$result = (new ProductRowProvider($this->userId))->getById($request->id);
		(new ProductRowErrorMapper())->throwOnFailure($result, $request->id);

		/** @var Item\ProductRow $row */
		$row = $result->getData()[ProductRowProvider::DATA_PRODUCT_ROW];

		// The route names the parent a row is addressed through, and a row under a parent of another
		// type is not reachable this way - the same rule the write scenarios apply to a row they are
		// given. Answered as an absent row, as every other cause of "not yours to see" here is.
		if ($row->getOwnerEntityType()?->equals($entityType) !== true)
		{
			throw new EntityNotFoundException($request->id);
		}

		$mapper = new ProductRowDtoMapper($entityType, $this->userId);

		return new GetResponse($mapper->getDtoByProductRow($row, $request));
	}

	public function deleteAction(DeleteProductRowRequest $request, EntityType $entityType): DeleteResponse
	{
		$result =
			(new DeleteCommand($entityType, $request->id, $this->userId))
				->setScope(Scope::Rest)
				->run()
		;
		(new ProductRowErrorMapper())->throwOnFailure($result, $request->id);

		return new DeleteResponse();
	}

	public function listAction(ListProductRowRequest $request, EntityType $entityType): ListResponse
	{
		$requestMapper = new ProductRowListRequestMapper();
		$filter = $requestMapper->mapFilter($request->filter);

		$result = (new ProductRowProvider($this->userId))->getList(
			$entityType,
			$filter,
			$this->mapSort($requestMapper, $request),
			$requestMapper->mapPager($request->pagination),
		);
		(new ProductRowErrorMapper())->throwOnFailure($result, $filter->getOwnerId());

		return new ListResponse($this->getDtoCollection($result->getData(), $request, $entityType));
	}

	public function replaceAction(
		ReplaceProductRowsRequest $request,
		EntityType $entityType,
	): ReplaceProductRowsResponse
	{
		$mapper = new ProductRowDtoMapper($entityType, $this->userId);
		// The catalog answers for the whole set once; without this every row would ask it about its own
		// product, and a set is the one request of this group whose size the client chooses.
		$mapper->prefetchCatalogProducts($request->items);

		$rows = [];
		foreach ($request->items as $rowIndex => $fields)
		{
			$rows[$rowIndex] = $this->getRowFields($mapper, $fields, $rowIndex, $request);
		}

		$result =
			(new ReplaceCommand($entityType, $request->ownerId, $rows, $this->userId))
				->setScope(Scope::Rest)
				->run()
		;
		(new ProductRowErrorMapper())->throwOnFailure($result, $request->ownerId);

		return new ReplaceProductRowsResponse($this->getDtoCollection($result->getData(), $request, $entityType));
	}

	public function getAvailableForPaymentAction(
		AvailableForPaymentRequest $request,
		EntityType $entityType,
	): ListResponse
	{
		$result = (new ProductRowProvider($this->userId))->getAvailableForPayment($entityType, $request->ownerId);
		(new ProductRowErrorMapper())->throwOnFailure($result, $request->ownerId);

		return new ListResponse($this->getDtoCollection($result->getData(), $request, $entityType));
	}

	/**
	 * One row of a replacement as the scenario takes it. The validation group is the default one on
	 * purpose: the owner is required when a row is *added* and is not part of a row of a set at all, so
	 * asking for the group of an addition would refuse every row for the absence of a field the contract
	 * does not allow there.
	 *
	 * @param array<string, mixed> $fields
	 * @return array<string, mixed>
	 */
	private function getRowFields(
		ProductRowDtoMapper $mapper,
		array $fields,
		int|string $rowIndex,
		ReplaceProductRowsRequest $request,
	): array
	{
		try
		{
			return $mapper->getFieldsByDto($mapper->createDtoByFields(
				FieldsStructure::create($fields, $request->getDtoClass(), $request),
				RequiredGroup::Default->value,
			));
		}
		catch (DtoValidationException | UnknownDtoPropertyException $exception)
		{
			(new ProductRowErrorMapper())->throwOnRowFailure($exception, $rowIndex);
		}
	}

	private function mapSort(
		ProductRowListRequestMapper $requestMapper,
		ListProductRowRequest $request,
	): ProductRowSort
	{
		try
		{
			return $requestMapper->mapSort($request->order);
		}
		catch (UnknownSortFieldException $exception)
		{
			throw new UnknownDtoPropertyException(
				ProductRowDto::create()->getShortName(),
				$exception->getFieldName(),
			);
		}
	}

	/**
	 * @param array<string, mixed> $resultData
	 */
	private function getDtoCollection(
		array $resultData,
		Request $request,
		EntityType $entityType,
	): DtoCollection
	{
		/** @var Item\ProductRowCollection $rows */
		$rows = $resultData[ProductRowProvider::DATA_PRODUCT_ROWS];

		return (new ProductRowDtoMapper($entityType, $this->userId))->getDtoCollectionByProductRows($rows, $request);
	}
}

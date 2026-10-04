<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Requisite;

use Bitrix\Crm\V2\Infrastructure\Rest\CustomController\AbstractPortalAdminController;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Requisite\RequisiteDtoMapper;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Requisite\RequisiteListRequestMapper;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Requisite\RequisiteDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Requisite\ListRequisiteRequest;
use Bitrix\Crm\V2\Internal\Repository\Requisite\RequisiteRepository;
use Bitrix\Main\ArgumentException;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Exception\InvalidFilterException;
use Bitrix\Rest\V3\Exception\InvalidPaginationException;
use Bitrix\Rest\V3\Exception\Validation\RequestFilterValidationException;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;

/**
 * `crm.requisite.list` - the requisites of the contacts and companies of the whole portal, read by a
 * portal administrator.
 *
 * The subject is the requisite as a record of its own rather than a field of the contact or the company
 * it belongs to: the owner is where the record hangs, not what the method is addressed through. A filter
 * over the owner is therefore ordinary and optional, unlike `crm.{entity}.productRow.list`, where a row
 * only ever exists within its owner.
 *
 * Who may call it is decided by {@see AbstractPortalAdminController} before the action runs, and that
 * gate is the whole reason the method reads without asking about the owner of a single record: the legal
 * details of every counterparty of the portal are answered here, and the right to read one requisite is
 * otherwise derived from the right to read its owner.
 *
 * The name is the one the legacy method of the same resource carries, and that is not a collision: the
 * two live at different addresses, as `crm.deal.*` already does. Neither the fields nor the filter of the
 * legacy method are restated - what this one publishes is a contract of its own.
 *
 * The route names no entity type, so it has no service parameters of its own and the source parameters of
 * the action are the client's query string alone. Nothing is read from there - the whole request is the
 * typed body, and the answer is the standard list.
 *
 * The read goes to {@see RequisiteRepository} of `V2\Internal` directly: the scenario is a single read of
 * the requisites of the portal, and a public contract of the domain would be a refactoring out of all
 * proportion to it. The dependency stays inside REST and carries no compatibility promise.
 */
#[DtoType(RequisiteDto::class)]
final class Requisite extends AbstractPortalAdminController
{
	/**
	 * The page as the repository reads it. Of the refusals the read makes, none is the client's: the
	 * framework rejects a field without `Filterable` or `Sortable` long before this, so an
	 * {@see ArgumentException} from below is a DTO and a repository that disagree - a defect of the
	 * portal, left to the framework for a system error with the failure in the log. Answering it as a
	 * malformed request would tell the client to fix something it did not write.
	 *
	 * @throws InvalidFilterException an operand a requisite cannot be filtered by.
	 * @throws RequestFilterValidationException an owner of a type the method does not serve.
	 * @throws InvalidPaginationException a page below the bounds the framework leaves unchecked.
	 * @throws ArgumentException a filter, an ordering or a field set over a field that is not a field of
	 *         a requisite.
	 */
	public function listAction(ListRequisiteRequest $request): ListResponse
	{
		$requestMapper = new RequisiteListRequestMapper();
		$selectedFields = $requestMapper->mapSelect($request);
		$pager = $requestMapper->mapPager($request->pagination);
		$requisites = (new RequisiteRepository())->findAll(
			$requestMapper->mapFilter($request->filter),
			$requestMapper->mapOrder($request->order),
			$pager->getLimit(),
			$pager->getOffset(),
			$selectedFields,
		);

		return new ListResponse(
			(new RequisiteDtoMapper())->getDtoCollectionByRequisites($requisites, $selectedFields),
		);
	}
}

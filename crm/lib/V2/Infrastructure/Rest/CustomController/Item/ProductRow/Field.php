<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Item\ProductRow;

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Infrastructure\Rest\CustomController\AbstractController;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Field\CrmFieldMetadataDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Field\CrmFieldMetadataMapper;
use Bitrix\Crm\V2\Infrastructure\Rest\FieldMetadata\StaticDtoFieldMetadataSource;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Field\GetRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Field\ListRequest;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Main\Engine\Action;
use Bitrix\Main\SystemException;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Exception\AccessDeniedException;
use Bitrix\Rest\V3\Exception\LogicException;
use Bitrix\Rest\V3\Interaction\Response\GetResponse;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;
use Bitrix\Rest\V3\Realisation\Exception\FieldNotFoundException;

/**
 * `crm.{entity}.productRow.field.*` - the help on the fields of the group.
 *
 * The class the help describes is a service parameter of the route and is read from there and from nowhere
 * else. The distinction matters: service parameters of a route outrank the query string on their way to the
 * arguments of an action, but the object of a request is filled from the body of the client, which outranks
 * them - a controller reading the class off the request answers about any DTO the client names.
 *
 * For the same reason there is no `#[DtoType]` here: the attribute wins over the class of the route when the
 * request is built, which would leave the route unable to say anything about the DTO it serves. The DTO of
 * the route is the metadata one - it is what these methods answer with and what their `select` names - while
 * the DTO under description travels separately.
 *
 * Nothing of the subject matter is decided here: the composition of the help is the DTO of the group, and it
 * is the same composition its reading answers with.
 */
final class Field extends AbstractController
{
	/** The service parameter of the route naming the DTO the help describes. */
	private const DESCRIBED_DTO_PARAMETER = 'dtoFqcn';

	private ?StaticDtoFieldMetadataSource $metadataSource = null;
	private ?CrmFieldMetadataMapper $mapper = null;

	protected function processBeforeAction(Action $action): bool
	{
		if (!parent::processBeforeAction($action))
		{
			return false;
		}

		$entityTypeId = $this->getEntityType()->getId();
		if (!Container::getInstance()->getUserPermissions($this->userId)->entityType()->canReadItems($entityTypeId))
		{
			throw new AccessDeniedException();
		}

		return true;
	}

	public function listAction(ListRequest $request, EntityType $entityType): ListResponse
	{
		$fields = $this->getMetadataSource()->getAll($entityType);

		return new ListResponse($this->getMapper()->mapCollection($fields, $request->select?->getList() ?? []));
	}

	public function getAction(GetRequest $request, EntityType $entityType): GetResponse
	{
		$field = $this->getMetadataSource()->getByName($entityType, $request->name);
		if ($field === null)
		{
			throw new FieldNotFoundException($request->name);
		}

		/** @var CrmFieldMetadataDto $dto */
		$dto = $this->getMapper()->mapCollection([$field], $request->select?->getList() ?? [])->first();

		return new GetResponse($dto);
	}

	private function getMetadataSource(): StaticDtoFieldMetadataSource
	{
		return $this->metadataSource ??= new StaticDtoFieldMetadataSource($this->getDescribedDtoFqcn());
	}

	private function getMapper(): CrmFieldMetadataMapper
	{
		return $this->mapper ??= new CrmFieldMetadataMapper();
	}

	/**
	 * @return class-string<Dto>
	 */
	private function getDescribedDtoFqcn(): string
	{
		// the service parameters of the route are merged over the query string, so a value declared by a
		// route always wins; the body of the request does not reach them at all
		$dtoFqcn = $this->getSourceParametersList()[0][self::DESCRIBED_DTO_PARAMETER] ?? null;
		if (!is_string($dtoFqcn) || !is_subclass_of($dtoFqcn, Dto::class))
		{
			throw new LogicException(new SystemException(
				'A route of ' . self::class . ' must declare a DTO in its "' . self::DESCRIBED_DTO_PARAMETER . '" query param',
			));
		}

		return $dtoFqcn;
	}
}

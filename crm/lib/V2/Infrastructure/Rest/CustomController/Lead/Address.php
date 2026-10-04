<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Lead;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Lead\AddressDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Lead\LeadAddressMapper;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Lead\Address\DeleteRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Lead\Address\GetRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Lead\Address\SetRequest;
use Bitrix\Crm\V2\Public\Command\Address\DeleteLeadAddressCommand;
use Bitrix\Crm\V2\Public\Command\Address\SetLeadAddressCommand;
use Bitrix\Crm\V2\Public\Entity\Address\LeadAddress;
use Bitrix\Crm\V2\Public\Provider\Address\LeadAddressProvider;
use Bitrix\Main\Error;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Controller\RestController;
use Bitrix\Rest\V3\Exception\EntityNotFoundException;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;
use Bitrix\Rest\V3\Interaction\Response\DeleteResponse;
use Bitrix\Rest\V3\Interaction\Response\GetResponse;
use Bitrix\Rest\V3\Interaction\Response\UpdateResponse;

#[DtoType(AddressDto::class)]
final class Address extends RestController
{
	private ?LeadAddressMapper $addressMapper = null;

	public function getAction(GetRequest $request, CurrentUser $currentUser): GetResponse
	{
		try
		{
			$address = (new LeadAddressProvider())->get($request->id, (int)$currentUser->getId());
		}
		catch (ObjectNotFoundException)
		{
			throw new EntityNotFoundException($request->id);
		}

		return new GetResponse($this->getAddressMapper()->mapToDto($address));
	}

	public function setAction(SetRequest $request, CurrentUser $currentUser): UpdateResponse
	{
		if ($request->fields->getItems() === [])
		{
			throw new RequestValidationException([new Error('The fields property must not be empty.', 'fields')]);
		}

		/** @var AddressDto $dto */
		$dto = $request->fields->convertToDto('set');

		try
		{
			$result = (new SetLeadAddressCommand(
				$request->id,
				$this->getAddressMapper()->mapToChanges($dto, $request->fields->getItems()),
				(int)$currentUser->getId(),
			))->run();
		}
		catch (ObjectNotFoundException)
		{
			throw new EntityNotFoundException($request->id);
		}

		if (!$result->isSuccess())
		{
			throw new RequestValidationException($result->getErrors());
		}

		return new UpdateResponse();
	}

	public function deleteAction(DeleteRequest $request, CurrentUser $currentUser): DeleteResponse
	{
		try
		{
			$result = (new DeleteLeadAddressCommand($request->id, (int)$currentUser->getId()))->run();
		}
		catch (ObjectNotFoundException)
		{
			throw new EntityNotFoundException($request->id);
		}

		if (!$result->isSuccess())
		{
			throw new RequestValidationException($result->getErrors());
		}

		return new DeleteResponse();
	}

	private function getAddressMapper(): LeadAddressMapper
	{
		return $this->addressMapper ??= new LeadAddressMapper();
	}
}

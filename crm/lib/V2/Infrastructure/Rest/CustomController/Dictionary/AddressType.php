<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Dictionary;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Dictionary\AddressTypeDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Dictionary\AddressTypeListRequestMapper;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Dictionary\AddressType\GetRequest;
use Bitrix\Crm\V2\Public\Provider\Dictionary\AddressTypeProvider;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Controller\RestController;
use Bitrix\Rest\V3\Exception\EntityNotFoundException;
use Bitrix\Rest\V3\Interaction\Request\ListRequest;
use Bitrix\Rest\V3\Interaction\Response\GetResponse;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;

#[DtoType(AddressTypeDto::class)]
final class AddressType extends RestController
{
	private ?AddressTypeProvider $provider = null;

	public function getAction(GetRequest $request): GetResponse
	{
		$addressType = $this->getProvider()->getById($request->id, $this->getResponseLanguage());
		if ($addressType === null)
		{
			throw new EntityNotFoundException($request->id);
		}

		return new GetResponse(
			$this->getDtoMapper()->mapOne($addressType, $request->select?->getStructuredList() ?? []),
		);
	}

	public function listAction(ListRequest $request): ListResponse
	{
		$addressTypes = (new AddressTypeListRequestMapper())->map(
			$this->getProvider()->getList($this->getResponseLanguage()),
			$request,
		);

		return new ListResponse(
			$this->getDtoMapper()->mapCollection($addressTypes, $request->select?->getStructuredList() ?? []),
		);
	}

	private function getProvider(): AddressTypeProvider
	{
		return $this->provider ??= new AddressTypeProvider();
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Item;

use Bitrix\Crm\V2\Infrastructure\Rest\CustomController\AbstractController;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Item\ItemDtoMapper;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Item\FieldValueElementMapper;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Item\ItemFilterMapper;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Item\ItemListRequestMapper;
use Bitrix\Crm\V2\Infrastructure\Rest\Provider\Item\RestItemProvider;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Item\AbstractItemRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Item\AddFieldValueRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Item\AddItemRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Item\DeleteItemRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Item\DeleteFieldValueRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Item\GetItemRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Item\ListItemRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Item\UpdateItemRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\FileFieldInputBag;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\FileFieldInputExtractor;
use Bitrix\Crm\V2\Internal\Integration\Rest\File\FileUploadGateway;
use Bitrix\Crm\V2\Internal\Service\Item\CustomFieldRegistry;
use Bitrix\Crm\V2\Internal\Service\Item\File\FileFieldWriteService;
use Bitrix\Crm\V2\Internal\Service\Item\File\SystemFileFieldHandlerRegistry;
use Bitrix\Crm\V2\Public\Command\Item\AbstractItemCommand;
use Bitrix\Crm\V2\Public\Command\Item\AddFieldValueCommand;
use Bitrix\Crm\V2\Public\Command\Item\AddItemCommand;
use Bitrix\Crm\V2\Public\Command\Item\DeleteItemCommand;
use Bitrix\Crm\V2\Public\Command\Item\DeleteFieldValueCommand;
use Bitrix\Crm\V2\Public\Command\Item\Scope;
use Bitrix\Crm\V2\Public\Command\Item\UpdateItemCommand;
use Bitrix\Crm\V2\Public\Entity\Item\ItemCollection;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Crm\V2\Public\EntityTypeSettings;
use Bitrix\Crm\V2\Public\ItemId;
use Bitrix\Crm\V2\Public\Provider\Item\Exception\UnknownFilterFieldException;
use Bitrix\Crm\V2\Public\Provider\Item\Exception\UnknownSelectFieldException;
use Bitrix\Crm\V2\Public\Provider\Item\Exception\UnknownSortFieldException;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Main\Result;
use Bitrix\Rest\V3\Attribute\RequiredGroup;
use Bitrix\Rest\V3\Exception\EntityNotFoundException;
use Bitrix\Rest\V3\Exception\UnknownDtoPropertyException;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;
use Bitrix\Rest\V3\Interaction\Response\AddResponse;
use Bitrix\Rest\V3\Interaction\Response\DeleteResponse;
use Bitrix\Rest\V3\Interaction\Response\GetResponse;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;
use Bitrix\Rest\V3\Interaction\Response\UpdateResponse;
use Bitrix\Rest\V3\Structure\Structure;
use ReflectionClass;

class Item extends AbstractController
{
	public function addAction(AddItemRequest $request, EntityType $entityType): AddResponse
	{
		$fileFields = $this->extractFileFields($request, $entityType, FileFieldInputExtractor::OPERATION_ADD);
		$dto = $request->fields->convertToDto((RequiredGroup::Add)->value);

		$item = $this->getMapper($entityType)->getItemByDto($dto, validateForAdd: true);
		$result = $this->runAddItemCommand($item, $fileFields);

		if (!$result->isSuccess())
		{
			throw new RequestValidationException($result->getErrors());
		}

		return new AddResponse($item->getId());
	}

	public function updateAction(UpdateItemRequest $request, EntityType $entityType): UpdateResponse
	{
		$fileFields = $this->extractFileFields($request, $entityType, FileFieldInputExtractor::OPERATION_UPDATE);
		$dto = $request->fields->convertToDto((RequiredGroup::Update)->value);

		$item = $this->getMapper($entityType)->getItemByDto($dto);
		$item->setId($request->id);
		$provider = $this->getRestItemProvider($entityType);
		if ($provider->getById($request->id, ['id']) === null)
		{
			throw new EntityNotFoundException($request->id);
		}

		if ($this->shouldSkipUpdate($item, $fileFields)) // for response consistency on empty fields
		{
			return new UpdateResponse();
		}

		try
		{
			$result = $this->runUpdateItemCommand($item, $fileFields);
		}
		catch (ObjectNotFoundException)
		{
			throw new EntityNotFoundException($item->getId());
		}

		if (!$result->isSuccess())
		{
			throw new RequestValidationException($result->getErrors());
		}

		return new UpdateResponse();
	}

	public function addFieldValueAction(
		AddFieldValueRequest $request,
		EntityType $entityType,
	): UpdateResponse
	{
		if ($this->getRestItemProvider($entityType)->getById($request->id, ['id']) === null)
		{
			throw new EntityNotFoundException($request->id);
		}
		$itemId = new ItemId($entityType, $request->id);
		$this->validateFieldScope($request);
		$fieldValueElement = (new FieldValueElementMapper())->map(
			$entityType,
			$request->fieldName,
			$request->value,
		);

		try
		{
			$result =
				(new AddFieldValueCommand(
					$itemId,
					$fieldValueElement,
					$this->userId,
				))
				->setScope(Scope::Rest)
				->withoutRequiredUserFieldsCheck(!$this->isRequiredCheckEnabled())
				->run()
			;
		}
		catch (ObjectNotFoundException)
		{
			throw new EntityNotFoundException($itemId->getId());
		}

		if (!$result->isSuccess())
		{
			throw new RequestValidationException($result->getErrors());
		}

		return new UpdateResponse();
	}

	public function deleteAction(DeleteItemRequest $request, EntityType $entityType): DeleteResponse
	{
		if ($this->getRestItemProvider($entityType)->getById($request->id, ['id']) === null)
		{
			throw new EntityNotFoundException($request->id);
		}
		$itemId = new ItemId($entityType, $request->id);

		try
		{
			$result =
				(new DeleteItemCommand($itemId, $this->userId))
					->setScope(Scope::Rest)
					->run()
			;
		}
		catch (ObjectNotFoundException)
		{
			throw new EntityNotFoundException($itemId->getId());
		}

		if (!$result->isSuccess())
		{
			throw new RequestValidationException($result->getErrors());
		}

		return new DeleteResponse();
	}

	public function deleteFieldValueAction(
		DeleteFieldValueRequest $request,
		EntityType $entityType,
	): UpdateResponse
	{
		if ($this->getRestItemProvider($entityType)->getById($request->id, ['id']) === null)
		{
			throw new EntityNotFoundException($request->id);
		}
		$itemId = new ItemId($entityType, $request->id);
		$this->validateFieldScope($request);
		$value = $this->normalizeDeleteFieldValue($request, $entityType);
		$fieldValueElement = (new FieldValueElementMapper())->map(
			$entityType,
			$request->fieldName,
			$value,
			validateValueExistence: false,
		);

		try
		{
			$result =
				(new DeleteFieldValueCommand(
					$itemId,
					$fieldValueElement,
					$this->userId,
				))
				->setScope(Scope::Rest)
				->withoutRequiredUserFieldsCheck(!$this->isRequiredCheckEnabled())
				->run()
			;
		}
		catch (ObjectNotFoundException)
		{
			throw new EntityNotFoundException($itemId->getId());
		}

		if (!$result->isSuccess())
		{
			throw new RequestValidationException($result->getErrors());
		}

		return new UpdateResponse();
	}

	public function getAction(GetItemRequest $request, EntityType $entityType): GetResponse
	{
		$provider = $this->getRestItemProvider($entityType);
		$mapper = $this->getReadMapper($entityType);
		$select = $mapper->getItemSelectFromDtoFieldNames($request);

		try
		{
			$item = $provider->getById($request->id, $select);
		}
		catch (UnknownSelectFieldException $exception)
		{
			throw $this->createUnknownDtoPropertyException(
				$request->getDtoClass(),
				$exception->getFieldName(),
			);
		}
		if (!$item)
		{
			throw new EntityNotFoundException($request->id);
		}

		$dto = $mapper->getDtoByItemAndRequest($item, $request);

		return new GetResponse($dto);
	}

	public function listAction(ListItemRequest $request, EntityType $entityType): ListResponse
	{
		$settings = EntityTypeSettings::of($entityType);
		$itemDtoMapper = $this->getReadMapper($entityType);
		$requestMapper = new ItemListRequestMapper(
			$itemDtoMapper,
			new ItemFilterMapper($settings, $itemDtoMapper),
		);
		$select = $requestMapper->mapSelect($request);
		$filter = $requestMapper->mapFilter($request);
		$sort = $requestMapper->mapSort($request);
		$pager = $requestMapper->mapPager($request);
		$items = $this->executeListProviderCall(
			fn(): ItemCollection => $this->getRestItemProvider($entityType)
				->getList($select, $filter, $sort, $pager),
			$request->getDtoClass(),
		);

		return new ListResponse($itemDtoMapper->getDtoCollectionByItemsAndRequest($items, $request));
	}

	/**
	 * @param callable(): ItemCollection $providerCall
	 */
	private function executeListProviderCall(callable $providerCall, string $dtoClass): ItemCollection
	{
		try
		{
			return $providerCall();
		}
		catch (
			UnknownSelectFieldException
			| UnknownFilterFieldException
			| UnknownSortFieldException $exception
		)
		{
			throw $this->createUnknownDtoPropertyException($dtoClass, $exception->getFieldName());
		}
	}

	private function createUnknownDtoPropertyException(
		string $dtoClass,
		string $fieldName,
	): UnknownDtoPropertyException
	{
		$dto = Structure::getDto($dtoClass);
		$dtoShortName = $dto?->getShortName()
			?? (new ReflectionClass($dtoClass))->getShortName();

		return new UnknownDtoPropertyException($dtoShortName, $fieldName);
	}

	private function validateFieldScope(AbstractItemRequest $request): void
	{
		$scope = $request->getOptions()['scope'] ?? null;
		$availableFields = $scope?->fields ?? [];
		if ($availableFields !== [] && !in_array($request->fieldName, $availableFields, true))
		{
			throw $this->createUnknownDtoPropertyException($request->getDtoClass(), $request->fieldName);
		}
	}

	protected function getMapper(EntityType $entityType): ItemDtoMapper
	{
		return new ItemDtoMapper(EntityTypeSettings::of($entityType));
	}

	protected function getReadMapper(EntityType $entityType): ItemDtoMapper
	{
		return new ItemDtoMapper(EntityTypeSettings::of($entityType), restServer: $this->getServer());
	}

	protected function getRestItemProvider(EntityType $entityType): RestItemProvider
	{
		return new RestItemProvider($entityType, $this->userId);
	}

	protected function runAddItemCommand(
		\Bitrix\Crm\V2\Public\Entity\Item\Item $item,
		FileFieldInputBag $fileFields,
	): Result
	{
		$command
			= (new AddItemCommand($item, $this->userId))
				->setScope(Scope::Rest)
				->withoutRequiredUserFieldsCheck(!$this->isRequiredCheckEnabled())
		;

		return $this->runItemWriteCommand($command, $fileFields);
	}

	protected function runUpdateItemCommand(
		\Bitrix\Crm\V2\Public\Entity\Item\Item $item,
		FileFieldInputBag $fileFields,
	): Result
	{
		$command
			= (new UpdateItemCommand($item, $this->userId))
				->setScope(Scope::Rest)
				->withoutRequiredUserFieldsCheck(!$this->isRequiredCheckEnabled())
		;

		return $this->runItemWriteCommand($command, $fileFields);
	}

	protected function runItemWriteCommand(AbstractItemCommand $command, FileFieldInputBag $fileFields): Result
	{
		$requests = $fileFields->getWriteRequests();
		if ($requests === [])
		{
			return $command->run();
		}

		return $this->executeFileWriteCommand($command, $requests);
	}

	protected function executeFileWriteCommand(AbstractItemCommand $command, array $requests): Result
	{
		return (new FileFieldWriteService(
			systemFileFieldHandlerRegistry: new SystemFileFieldHandlerRegistry(),
		))->execute($command, $requests);
	}

	protected function shouldSkipUpdate(
		\Bitrix\Crm\V2\Public\Entity\Item\Item $item,
		FileFieldInputBag $fileFields,
	): bool
	{
		return !$item->hasChangedFields() && $fileFields->getWriteRequests() === [];
	}

	protected function extractFileFields(
		AbstractItemRequest $request,
		EntityType $entityType,
		string $operation,
	): FileFieldInputBag
	{
		$bag = (new FileFieldInputExtractor(
			CustomFieldRegistry::getInstance(),
			new FileUploadGateway(),
			new SystemFileFieldHandlerRegistry(),
		))->extract($request, $entityType, $operation);

		return $bag;
	}

	private function normalizeDeleteFieldValue(
		DeleteFieldValueRequest $request,
		EntityType $entityType,
	): mixed
	{
		$descriptor = CustomFieldRegistry::getInstance()
			->getEntityDescriptorsMap($entityType)[$request->fieldName] ?? null;
		if ($descriptor?->type !== 'file')
		{
			return $request->value;
		}

		return (new FileFieldInputExtractor(
			CustomFieldRegistry::getInstance(),
			new FileUploadGateway(),
			new SystemFileFieldHandlerRegistry(),
		))->extractExistingFileReference($request->value, 'value');
	}

	protected function isRequiredCheckEnabled(): bool
	{
		return \Bitrix\Crm\Settings\RestSettings::getCurrent()->isRequiredUserFieldCheckEnabled();
	}
}

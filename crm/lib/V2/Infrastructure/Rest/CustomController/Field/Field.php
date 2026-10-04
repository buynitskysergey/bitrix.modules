<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Field;

use Bitrix\Crm\V2\Infrastructure\Rest\CustomController\AbstractController;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Field\CrmFieldMetadataDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\ItemDtoGenerator;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Field\CrmFieldMetadataMapper;
use Bitrix\Crm\V2\Infrastructure\Rest\FieldMetadata\GeneratedDtoFieldMetadataSource;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Field\GetRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Field\ListRequest;
use Bitrix\Crm\V2\Internal\Service\FieldMetadata\FieldMetadataService;
use Bitrix\Crm\V2\Internal\Service\Item\CustomFieldRegistry;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Engine\Action;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Dto\Generator;
use Bitrix\Rest\V3\Exception\AccessDeniedException;
use Bitrix\Rest\V3\Interaction\Response\GetResponse;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;
use Bitrix\Rest\V3\Realisation\Exception\FieldNotFoundException;

#[DtoType(CrmFieldMetadataDto::class)]
final class Field extends AbstractController
{
	private static array $initializedEntityTypes = [];
	private ?FieldMetadataService $fieldMetadataService = null;
	private ?CrmFieldMetadataMapper $mapper = null;

	protected function processBeforeAction(Action $action): bool
	{
		if (!parent::processBeforeAction($action))
		{
			return false;
		}
		$entityType = $this->getEntityType();
		if (!Container::getInstance()->getUserPermissions($this->userId)->entityType()->canReadItems($entityType->getId()))
		{
			throw new AccessDeniedException();
		}

		if (isset(self::$initializedEntityTypes[$entityType->getId()]))
		{
			return true;
		}

		$generatedDto = (new ItemDtoGenerator())->generate($entityType->getId());
		$dtoClass = Generator::generateByDto($generatedDto);
		$dtoClass::create();
		self::$initializedEntityTypes[$entityType->getId()] = true;

		return true;
	}

	public function listAction(ListRequest $request, EntityType $entityType): ListResponse
	{
		$fields = $this->getFieldMetadataService()->getAll(
			$entityType,
			$this->userId,
			$this->getResponseLanguage(),
		);
		$selectedFields = $request->select?->getList() ?? [];

		return new ListResponse($this->getMapper()->mapCollection($fields, $selectedFields));
	}

	public function getAction(GetRequest $request, EntityType $entityType): GetResponse
	{
		$field = $this->getFieldMetadataService()->getByName(
			$entityType,
			$request->name,
			$this->userId,
			$this->getResponseLanguage(),
		);
		if ($field === null)
		{
			throw new FieldNotFoundException($request->name);
		}

		$selectedFields = $request->select?->getList() ?? [];
		/** @var CrmFieldMetadataDto $dto */
		$dto = $this->getMapper()->mapCollection([$field], $selectedFields)->first();

		return new GetResponse($dto);
	}

	private function getFieldMetadataService(): FieldMetadataService
	{
		return $this->fieldMetadataService ??= new FieldMetadataService(
			new GeneratedDtoFieldMetadataSource(new ItemDtoGenerator()),
			CustomFieldRegistry::getInstance(),
		);
	}

	private function getMapper(): CrmFieldMetadataMapper
	{
		return $this->mapper ??= new CrmFieldMetadataMapper();
	}
}

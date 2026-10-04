<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\CustomController;

use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Engine\AutoWire\Parameter;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Rest\V3\Controller\RestController;
use Bitrix\Rest\V3\Exception\EntityNotFoundException;
use Bitrix\Rest\V3\Exception\Validation\RequiredFieldInRequestException;

abstract class AbstractController extends RestController
{
	protected int $userId;

	protected function init(): void
	{
		$this->userId = (int)CurrentUser::get()->getId();

		parent::init();
	}

	public function getAutoWiredParameters(): array
	{
		return array_merge(
			parent::getAutoWiredParameters(),
			[
				new Parameter(
					EntityType::class,
					fn (): EntityType => $this->getEntityType(),
				),
			],
		);
	}

	protected function getEntityType(string $paramName = 'entityTypeId'): EntityType
	{
		$sourceEntityTypeId = $this->getSourceParametersList()[0][$paramName] ?? null;
		// if sourceEntityTypeId is int, it is set explicitly in SchemaProvider (trusted value)
		// if sourceEntityTypeId is not set in SchemaProvider, it is filled from Request Query params
		// if sourceEntityTypeId is filled from Request Query params, it has type "string"
		if (is_int($sourceEntityTypeId))
		{
			return EntityType::fromId($sourceEntityTypeId);
		}

		$bodyEntityTypeId = (int)$this->getRequest()->getJsonList()->getRaw($paramName);
		if (empty($bodyEntityTypeId))
		{
			throw new RequiredFieldInRequestException($paramName);
		}

		try
		{
			return EntityType::smartProcess($bodyEntityTypeId);
		}
		catch (ArgumentException)
		{
			throw new EntityNotFoundException($bodyEntityTypeId);
		}
	}

	protected function writeToLogException(\Throwable $e): void
	{
		if ($e instanceof EntityNotFoundException)
		{
			return;
		}

		parent::writeToLogException($e);
	}
}

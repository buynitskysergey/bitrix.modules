<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Validation\Validator;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\ItemIdentifierDto;
use Bitrix\Main\Validation\ValidationResult;
use Bitrix\Main\Validation\Validator\InArrayValidator;
use Bitrix\Rest\Exceptions\ArgumentTypeException;

/**
 * @internal
 */
class ItemIdentifierValidator
{
	public function __construct(
		private readonly array $validEntitiesIdMapByTypeId,
		private readonly bool $strict = false,
		private readonly bool $showTypeValues = false,
		private readonly bool $showIdValues = false,
	)
	{
	}

	public function validate(mixed $value): ValidationResult
	{
		if (!$value instanceof ItemIdentifierDto)
		{
			throw new ArgumentTypeException('value', ItemIdentifierDto::class);
		}

		$result = new ValidationResult();

		$entityTypeId = $value->entityTypeId ?? null;
		$entityTypeValidator = new InArrayValidator(
			array_keys($this->validEntitiesIdMapByTypeId),
			$this->strict,
			$this->showTypeValues,
		);
		$entityTypeResult = $entityTypeValidator->validate($entityTypeId);
		$result->addErrors($entityTypeResult->getErrors());

		$entityIdValidator = new InArrayValidator(
			$this->validEntitiesIdMapByTypeId[$entityTypeId] ?? [],
			$this->strict,
			$this->showIdValues,
		);
		$entityIdResult = $entityIdValidator->validate($value->entityId ?? null);
		$result->addErrors($entityIdResult->getErrors());

		return $result;
	}
}

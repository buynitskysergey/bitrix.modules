<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Validation\Rule;

use Attribute;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Provider\Context;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Provider\ValidValuesProviderInterface;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Validator\ItemIdentifierValidator;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Localization\LocalizableMessageInterface;
use Bitrix\Main\Validation\Rule\AbstractPropertyValidationAttribute;
use Bitrix\Main\Validation\Rule\ValidateByGroupInterface;
use Bitrix\Main\Validation\ValidationResult;
use Bitrix\Rest\Exceptions\ArgumentTypeException;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class ItemIdentifier extends AbstractPropertyValidationAttribute implements ValidateByGroupInterface
{
	protected ?array $validEntitiesIdMapByTypeId = null;
	protected ?Context $context = null;

	public function __construct(
		protected readonly array $entityTypeIds,
		protected readonly string|ValidValuesProviderInterface $provider,
		protected readonly bool $strict = false,
		protected string|LocalizableMessageInterface|null $errorMessage = null,
		protected readonly bool $showTypeValues = false,
		protected readonly bool $showIdValues = false,
		protected array $groups = [],
	)
	{
		if (!is_subclass_of($provider, ValidValuesProviderInterface::class))
		{
			throw new ArgumentTypeException(
				'provider',
				ValidValuesProviderInterface::class,
			);
		}
	}

	public function validateProperty(mixed $propertyValue): ValidationResult
	{
		if (!isset($this->context))
		{
			$this->context = $this->buildContext($propertyValue);
		}

		return parent::validateProperty($propertyValue);
	}

	protected function buildContext(mixed $propertyValue): Context
	{
		return new Context(
			(int)CurrentUser::get()->getId(),
			$propertyValue,
			false,
			['entityTypeIds' => $this->entityTypeIds],
		);
	}

	protected function getValidators(): array
	{
		return [
			new ItemIdentifierValidator(
				$this->getValidValues(),
				$this->strict,
				$this->showTypeValues,
				$this->showIdValues,
			),
		];
	}

	public function getGroups(): array
	{
		return $this->groups;
	}

	public static function __set_state(array $array): static
	{
		return new static(
			entityTypeIds: $array['entityTypeIds'] ?? [],
			provider: $array['provider'] ?? '',
			strict: $array['strict'] ?? null,
			errorMessage: $array['errorMessage'] ?? null,
			showTypeValues: $array['showTypeValues'] ?? false,
			showIdValues: $array['showIdValues'] ?? false,
			groups: $array['groups'] ?? [],
		);
	}

	protected function getValidValues(): array
	{
		if (!isset($this->context))
		{
			return [];
		}

		if (!isset($this->validEntitiesIdMapByTypeId))
		{
			$this->validEntitiesIdMapByTypeId = $this->provider::getValidValues($this->context);
		}

		return $this->validEntitiesIdMapByTypeId;
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Category\Stage;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Category\StageInputDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\CategoryPaginationStructure;
use Bitrix\Main\Error;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Validation\Group\ValidationGroup;
use Bitrix\Main\Validation\ValidationError;
use Bitrix\Main\Validation\Validator\MaxValidator;
use Bitrix\Rest\V3\Attribute\ElementType;
use Bitrix\Rest\V3\Attribute\RequiredGroup;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Dto\DtoValidatorHelper;
use Bitrix\Rest\V3\Exception\Validation\DtoValidationException;
use Bitrix\Rest\V3\Exception\Validation\InvalidRequestFieldTypeException;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\FieldsStructure;

/**
 * `crm.<entity>.category.stage.replace`: the whole set of stages one category is to end up with.
 *
 * ```json
 * { "categoryId": 2, "stages": [ { "stageId": "C2:NEW", "name": "New" }, { "name": "Won back" } ] }
 * ```
 *
 * The only method of the family that takes neither the standard `fields` of one object nor an `id`,
 * because it addresses neither: what it carries is a set, and the order of that set is part of what
 * it says. The category is named once, by `categoryId`, and by nothing else - there is no `filter`
 * here and no other way to say which stages are meant.
 *
 * `stages` is demanded rather than optional, and an empty one is a request in its own right: a
 * category left with none of its own stages. What a missing `stageId` means, and what the elements
 * may carry at all, is the business of {@see StageInputDto}.
 */
final class ReplaceStagesRequest extends Request
{
	/**
	 * The most stages one replacement may carry.
	 *
	 * A replacement is one transaction over the whole set, so an unbounded set is an unbounded
	 * transaction - the ceiling is the request's to set rather than the domain's. Every stage of it is
	 * written through the legacy stage boundary one at a time, and that boundary costs tens of
	 * milliseconds a stage whatever this domain does, so the size of the set is the length of the
	 * transaction: the locks it holds on the stage table are held against the whole portal and not
	 * only against the caller. Fifty keeps the worst case at a couple of seconds, and a pipeline of
	 * fifty stages is already past what a pipeline is for.
	 *
	 * Below the page of the family ({@see CategoryPaginationStructure::MAX_LIMIT}) on purpose: reading
	 * a set out and sending it back is not what bounds a transaction, and the two ceilings move
	 * independently.
	 */
	public const MAX_STAGES = 50;

	/**
	 * `0` is a category like any other here - the default pipeline of Deal.
	 */
	public int $categoryId;

	/**
	 * The stages as they arrived. {@see self::convertToStages()} is what reads them.
	 *
	 * @var array<int, mixed>
	 */
	#[ElementType(StageInputDto::class)]
	public array $stages;

	public static function create(HttpRequest $httpRequest, string $dtoClass, array $options = []): Request
	{
		/** @var self $request */
		$request = parent::create($httpRequest, $dtoClass, $options);
		$request->refuseSetBeyondTheLimit();

		return $request;
	}

	/**
	 * The stages of the request, in the order it put them.
	 *
	 * Told apart from a plain read ({@see self::$stages}) the way {@see FieldsStructure::convertToDto()}
	 * is: reading the elements is what checks them, and a client learns everything wrong with its set
	 * at once rather than one element at a time. Which element an answer is about is part of the field
	 * it names - `stages.1.name`.
	 *
	 * @return DtoCollection<StageInputDto>
	 */
	public function convertToStages(): DtoCollection
	{
		$stages = new DtoCollection(StageInputDto::class);
		$refusals = [];

		foreach (array_values($this->stages) as $index => $element)
		{
			$stage = $this->stageOf($element, $index);
			$stages->add($stage);
			$refusals = array_merge($refusals, self::refusalsOf($stage, $index));
		}

		if ($refusals !== [])
		{
			throw new DtoValidationException($refusals);
		}

		return $stages;
	}

	/**
	 * One element of the set as the contract of a stage reads it. A field the contract does not carry
	 * is refused here rather than dropped, and so is a value of the wrong type.
	 */
	private function stageOf(mixed $element, int $index): StageInputDto
	{
		$stage = StageInputDto::create();

		foreach (FieldsStructure::create($element, StageInputDto::class, $this)->getItems() as $property => $value)
		{
			try
			{
				$stage->{$property} = $value;
			}
			catch (\TypeError)
			{
				throw new InvalidRequestFieldTypeException(
					self::fieldOf($index, $property),
					(string)$stage->getFields()[$property]?->getPropertyType(),
				);
			}
		}

		return $stage;
	}

	/**
	 * What is wrong with one element, each answer naming the element it is about - the way the
	 * framework names the elements of a collection standing inside a DTO
	 * ({@see DtoValidatorHelper::validate()}).
	 *
	 * @return ValidationError[]
	 */
	private static function refusalsOf(Dto $stage, int $index): array
	{
		$result = DtoValidatorHelper::validate($stage, ValidationGroup::create((RequiredGroup::Default)->value));

		return array_map(
			static fn (Error $error): ValidationError => new ValidationError(
				$error->getLocalizableMessage() ?? $error->getMessage(),
				self::fieldOf($index, (string)$error->getCode()),
			),
			$result->getErrors(),
		);
	}

	private static function fieldOf(int $index, string $property): string
	{
		return 'stages.' . $index . '.' . $property;
	}

	/**
	 * Refuses a set larger than one transaction may carry, before anything reads it.
	 */
	private function refuseSetBeyondTheLimit(): void
	{
		$refusal = (new MaxValidator(self::MAX_STAGES))->validate(count($this->stages));
		if ($refusal->isSuccess())
		{
			return;
		}

		throw new RequestValidationException(array_map(
			static fn (Error $error): ValidationError => new ValidationError(
				$error->getLocalizableMessage() ?? $error->getMessage(),
				'stages',
			),
			$refusal->getErrors(),
		));
	}
}

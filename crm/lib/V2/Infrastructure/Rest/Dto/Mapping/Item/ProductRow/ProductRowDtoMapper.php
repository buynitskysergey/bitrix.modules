<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Item\ProductRow;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\ProductRow\ProductRowDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\RequestSelectedFields;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Provider\CatalogProductIdProvider;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Provider\Context;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Provider\ProductRowOwnerProvider;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Rule\InArrayFrom;
use Bitrix\Crm\V2\Public\Entity\Item\ProductRow;
use Bitrix\Crm\V2\Public\Entity\Item\ProductRowCollection;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Main\ArgumentTypeException;
use Bitrix\Main\Validation\ValidationError;
use Bitrix\Main\Validation\ValidationResult;
use Bitrix\Main\Validation\Validator\InArrayValidator;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Exception\Validation\DtoValidationException;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\FieldsStructure;

/**
 * Converts between {@see ProductRowDto} and the public {@see ProductRow}, in both directions.
 *
 * Three differences the two shapes have and neither of them can resolve on its own:
 *
 * - the owner type is a numeric id outside and an {@see EntityType} inside (the short code the storage
 *   keeps is a third representation and lives in the repository, not here);
 * - two names disagree - `productName` / `name` and `discountSum` / `discount`;
 * - the existence of the owner and of the catalog product is checked here, per request, because the
 *   owner type comes from the route and a rule that caches its valid values may not be shared between
 *   the DTO instances of one request.
 *
 * The last of the three is why a mapper is built once per request rather than once per row: the products
 * of a whole set are resolved in a single lookup ({@see prefetchCatalogProducts()}) and every row is then
 * checked against that one answer. The instance is what bounds the reuse - a rule shared by the DTO
 * instances of the process would outlive the request and judge one request by the products of another.
 *
 * Reading honours `select`: only the properties the client asked for are filled, and a property that
 * was never filled stays out of the response entirely. Writing honours the absence of a field: only
 * the fields the client actually sent reach the scenario, which is what makes a partial write
 * expressible at all.
 */
final class ProductRowDtoMapper
{
	/** The only names on which the external contract and the public type disagree. */
	private const ROW_FIELD_BY_DTO_FIELD = [
		'productName' => 'name',
		'discountSum' => 'discount',
	];

	private const DTO_FIELD_ID = 'id';
	private const DTO_FIELD_OWNER_ID = 'ownerId';
	private const DTO_FIELD_PRODUCT_ID = 'productId';

	/** The answer of the catalog for the whole request; `null` while no set has been resolved. */
	private ?array $catalogProductIds = null;

	/**
	 * @param int $userId the user the references of a request are checked as. The subject of every right
	 *        of this group is the user the infrastructure of the controller resolved, and it is named here
	 *        for the same reason it is named to every scenario: which products a row may point at is an
	 *        answer about a user, and reading that user from the process instead would tie the answer to a
	 *        context this class does not control.
	 */
	public function __construct(
		private readonly EntityType $ownerType,
		private readonly int $userId,
	)
	{
	}

	/**
	 * Resolves the catalog products of a whole set of rows in one lookup, so that a replacement costs the
	 * catalog one query instead of one per row. The rows are the ones as they were sent: a row is read for
	 * its product identifier alone, and everything else about it is the business of the conversion that
	 * follows.
	 *
	 * The set is resolved as a whole on purpose. Resolving row by row and keeping the answers would let
	 * the first row decide what the second one is checked against, which is the reason these checks are
	 * not validation attributes in the first place.
	 *
	 * @param array<int|string, mixed> $rowsFields the fields of every row of the request.
	 */
	public function prefetchCatalogProducts(array $rowsFields): void
	{
		$productIds = [];
		foreach ($rowsFields as $fields)
		{
			$productId = is_array($fields) ? ($fields[self::DTO_FIELD_PRODUCT_ID] ?? null) : null;
			if (is_scalar($productId))
			{
				$productIds[] = (int)$productId;
			}
		}

		$this->catalogProductIds = $this->resolveCatalogProducts($productIds);
	}

	/**
	 * The DTO of a write request: the framework checks the form of the fields and this adds the two
	 * checks that need a request of their own - the owner within the type of the route, the product
	 * within the catalog.
	 *
	 * @param string $validationGroup {@see \Bitrix\Rest\V3\Attribute\RequiredGroup}
	 * @throws DtoValidationException
	 */
	public function createDtoByFields(FieldsStructure $fields, string $validationGroup): ProductRowDto
	{
		$dto = $fields->convertToDto($validationGroup);
		if (!$dto instanceof ProductRowDto)
		{
			throw new ArgumentTypeException('fields', ProductRowDto::class);
		}

		$this->validateReferences($dto);

		return $dto;
	}

	/**
	 * The row as the write scenarios take it: public field names of {@see ProductRow} mapped to their
	 * values, and only the fields the client sent.
	 *
	 * The owner id is part of it on purpose. For an update it is a read-only field the scenario has to
	 * refuse, and dropping it here would turn a refusal into silence.
	 *
	 * @return array<string, mixed>
	 */
	public function getFieldsByDto(ProductRowDto $dto): array
	{
		$fields = [];
		foreach ($dto->toArray(true) as $dtoFieldName => $value)
		{
			$fields[self::ROW_FIELD_BY_DTO_FIELD[$dtoFieldName] ?? $dtoFieldName] = $value;
		}

		return $fields;
	}

	/**
	 * The same field set without the owner: when a row is added the owner is the address of the row and
	 * the command carries it itself, so the scenario refuses it among the fields.
	 *
	 * @return array<string, mixed>
	 */
	public function getFieldsByDtoWithoutOwner(ProductRowDto $dto): array
	{
		$fields = $this->getFieldsByDto($dto);
		unset($fields[self::DTO_FIELD_OWNER_ID]);

		return $fields;
	}

	/**
	 * The owner a row is added to; `0` when the client sent none, which the scenario answers as an
	 * owner that does not exist.
	 */
	public function getOwnerIdByDto(ProductRowDto $dto): int
	{
		return (int)($dto->toArray(true)[self::DTO_FIELD_OWNER_ID] ?? 0);
	}

	public function getDtoByProductRow(ProductRow $row, Request $request): ProductRowDto
	{
		return $this->createDtoByRow($row, $this->getSelectedFieldNames($request));
	}

	public function getDtoCollectionByProductRows(ProductRowCollection $rows, Request $request): DtoCollection
	{
		$collection = new DtoCollection(ProductRowDto::class);
		$selectedFieldNames = $this->getSelectedFieldNames($request);
		foreach ($rows->getAll() as $row)
		{
			$collection->add($this->createDtoByRow($row, $selectedFieldNames));
		}

		return $collection;
	}

	/**
	 * @param string[] $selectedFieldNames
	 */
	private function createDtoByRow(ProductRow $row, array $selectedFieldNames): ProductRowDto
	{
		/** @var ProductRowDto $dto */
		$dto = ProductRowDto::create();
		foreach ($selectedFieldNames as $dtoFieldName)
		{
			if (!isset($dto->getFields()[$dtoFieldName]))
			{
				continue;
			}

			// Writes into the field rather than into the property, so an unselected property stays
			// uninitialized and drops out of the response.
			$dto->__set($dtoFieldName, $this->getRowFieldValue($row, $dtoFieldName));
		}

		return $dto;
	}

	private function getRowFieldValue(ProductRow $row, string $dtoFieldName): mixed
	{
		return match ($dtoFieldName)
		{
			self::DTO_FIELD_ID => $row->getId(),
			self::DTO_FIELD_OWNER_ID => (int)$row->getOwnerId(),
			'ownerTypeId' => ($row->getOwnerEntityType() ?? $this->ownerType)->getId(),
			self::DTO_FIELD_PRODUCT_ID => $row->getProductId(),
			'productName' => $row->getName(),
			'price' => $row->getPrice(),
			'quantity' => $row->getQuantity(),
			'discountTypeId' => $row->getDiscountTypeId(),
			'discountRate' => $row->getDiscountRate(),
			'discountSum' => $row->getDiscount(),
			'taxRate' => $row->getTaxRate(),
			'taxIncluded' => $row->getTaxIncluded(),
			'measureCode' => $row->getMeasureCode(),
			'measureName' => $row->getMeasureName(),
			'sort' => $row->getSort(),
			'productTypeId' => $row->getProductTypeId(),
			default => null,
		};
	}

	/**
	 * The fields to read, by the rule the whole REST of the CRM reads by. The identifier is what a row
	 * falls back to when nothing was asked for.
	 *
	 * @return string[]
	 */
	private function getSelectedFieldNames(Request $request): array
	{
		return RequestSelectedFields::resolve($request, self::DTO_FIELD_ID);
	}

	/**
	 * @throws DtoValidationException
	 */
	private function validateReferences(ProductRowDto $dto): void
	{
		$values = $dto->toArray(true);
		$errors = [];

		if (array_key_exists(self::DTO_FIELD_OWNER_ID, $values))
		{
			$ownerRule = new InArrayFrom(
				ProductRowOwnerProvider::class,
				['entityTypeId' => $this->ownerType->getId()],
			);
			$errors = array_merge($errors, self::toFieldErrors(
				$ownerRule->validateProperty($values[self::DTO_FIELD_OWNER_ID]),
				self::DTO_FIELD_OWNER_ID,
			));
		}

		$productId = $values[self::DTO_FIELD_PRODUCT_ID] ?? null;
		if ($productId !== null)
		{
			$errors = array_merge($errors, self::toFieldErrors(
				(new InArrayValidator($this->getCatalogProductIds($productId)))->validate($productId),
				self::DTO_FIELD_PRODUCT_ID,
			));
		}

		if ($errors !== [])
		{
			throw new DtoValidationException($errors);
		}
	}

	/**
	 * What a product identifier is checked against: the answer the catalog gave for the whole set when
	 * there was one, and the answer for this identifier alone otherwise - a single row is a set of one and
	 * costs the same one query it always did.
	 *
	 * @return int[]
	 */
	private function getCatalogProductIds(mixed $productId): array
	{
		return $this->catalogProductIds ?? $this->resolveCatalogProducts([(int)$productId]);
	}

	/**
	 * @param int[] $productIds
	 * @return int[] the identifiers of the given ones that may be referenced: the products the catalog
	 *         knows, plus the non-positive values, which name no product and are a row's way of carrying
	 *         none.
	 */
	private function resolveCatalogProducts(array $productIds): array
	{
		return CatalogProductIdProvider::getValidValues(new Context($this->userId, $productIds));
	}

	/**
	 * @return ValidationError[]
	 */
	private static function toFieldErrors(ValidationResult $result, string $dtoFieldName): array
	{
		if ($result->isSuccess())
		{
			return [];
		}

		$errors = [];
		foreach ($result->getErrors() as $error)
		{
			$errors[] = new ValidationError($error->getLocalizableMessage(), $dtoFieldName);
		}

		return $errors;
	}
}

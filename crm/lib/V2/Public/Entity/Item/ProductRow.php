<?php
declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Item;

use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Main\Entity\EntityInterface;

/**
 * A single product row of an Item.
 *
 * Intentionally mutable, unlike the immutable value objects in this namespace (ContactBinding,
 * CompanyBinding, AbstractMultifieldValue). A ProductRow is an editable "input row": callers build
 * it up field by field via fluent setters before adding it to a {@see ProductRowCollection}, so a
 * constructor-only readonly shape would be impractical here. This contrast is deliberate.
 */
final class ProductRow implements EntityInterface
{
	private ?int $id = null;
	private ?int $productId = null;
	private ?string $name = null;
	private float $quantity = 1.0;
	private float $price = 0.0;
	private float $discount = 0.0;
	private ?float $taxRate = null;
	private int $sort = 0;
	private ?int $ownerId = null;
	private ?EntityType $ownerEntityType = null;
	/** @see \Bitrix\Crm\Discount */
	private ?int $discountTypeId = null;
	private ?float $discountRate = null;
	private ?bool $taxIncluded = null;
	/** Name of the tax as it was stored on the row; the rate itself is {@see $taxRate}. */
	private ?string $taxName = null;
	/** Measure classifier code, not a reference to a measure record. */
	private ?int $measureCode = null;
	private ?string $measureName = null;
	/** @see \Bitrix\Crm\ProductType */
	private ?int $productTypeId = null;

	public function getId(): ?int
	{
		return $this->id;
	}

	public function setId(int $id): static
	{
		$this->id = $id;

		return $this;
	}

	public function getProductId(): ?int
	{
		return $this->productId;
	}

	public function setProductId(int $productId): static
	{
		$this->productId = $productId;

		return $this;
	}

	public function getName(): ?string
	{
		return $this->name;
	}

	public function setName(string $name): static
	{
		$this->name = $name;

		return $this;
	}

	public function getQuantity(): float
	{
		return $this->quantity;
	}

	public function setQuantity(float $quantity): static
	{
		$this->quantity = $quantity;

		return $this;
	}

	public function getPrice(): float
	{
		return $this->price;
	}

	public function setPrice(float $price): static
	{
		$this->price = $price;

		return $this;
	}

	public function getDiscount(): float
	{
		return $this->discount;
	}

	public function setDiscount(float $discount): static
	{
		$this->discount = $discount;

		return $this;
	}

	public function getTaxRate(): ?float
	{
		return $this->taxRate;
	}

	public function setTaxRate(float $taxRate): static
	{
		$this->taxRate = $taxRate;

		return $this;
	}

	public function getSort(): int
	{
		return $this->sort;
	}

	public function setSort(int $sort): static
	{
		$this->sort = $sort;

		return $this;
	}

	public function getOwnerId(): ?int
	{
		return $this->ownerId;
	}

	public function setOwnerId(int $ownerId): static
	{
		$this->ownerId = $ownerId;

		return $this;
	}

	public function getOwnerEntityType(): ?EntityType
	{
		return $this->ownerEntityType;
	}

	public function setOwnerEntityType(EntityType $ownerEntityType): static
	{
		$this->ownerEntityType = $ownerEntityType;

		return $this;
	}

	public function getDiscountTypeId(): ?int
	{
		return $this->discountTypeId;
	}

	public function setDiscountTypeId(int $discountTypeId): static
	{
		$this->discountTypeId = $discountTypeId;

		return $this;
	}

	public function getDiscountRate(): ?float
	{
		return $this->discountRate;
	}

	public function setDiscountRate(float $discountRate): static
	{
		$this->discountRate = $discountRate;

		return $this;
	}

	public function getTaxIncluded(): ?bool
	{
		return $this->taxIncluded;
	}

	public function setTaxIncluded(bool $taxIncluded): static
	{
		$this->taxIncluded = $taxIncluded;

		return $this;
	}

	public function getTaxName(): ?string
	{
		return $this->taxName;
	}

	public function setTaxName(string $taxName): static
	{
		$this->taxName = $taxName;

		return $this;
	}

	public function getMeasureCode(): ?int
	{
		return $this->measureCode;
	}

	public function setMeasureCode(int $measureCode): static
	{
		$this->measureCode = $measureCode;

		return $this;
	}

	public function getMeasureName(): ?string
	{
		return $this->measureName;
	}

	public function setMeasureName(string $measureName): static
	{
		$this->measureName = $measureName;

		return $this;
	}

	public function getProductTypeId(): ?int
	{
		return $this->productTypeId;
	}

	public function setProductTypeId(int $productTypeId): static
	{
		$this->productTypeId = $productTypeId;

		return $this;
	}

}

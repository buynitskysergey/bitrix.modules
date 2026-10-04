<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Item\CustomField;

final readonly class Address
{
	public function __construct(
		private ?int $locationId = null,
		private ?float $latitude = null,
		private ?float $longitude = null,
		private ?string $country = null,
		private ?string $city = null,
		private ?string $postalCode = null,
		private ?string $address1 = null,
		private ?string $address2 = null,
	)
	{
	}

	public function getLocationId(): ?int
	{
		return $this->locationId;
	}

	public function getLatitude(): ?float
	{
		return $this->latitude;
	}

	public function getLongitude(): ?float
	{
		return $this->longitude;
	}

	public function getCountry(): ?string
	{
		return $this->country;
	}

	public function getCity(): ?string
	{
		return $this->city;
	}

	public function getPostalCode(): ?string
	{
		return $this->postalCode;
	}

	public function getAddress1(): ?string
	{
		return $this->address1;
	}

	public function getAddress2(): ?string
	{
		return $this->address2;
	}
}

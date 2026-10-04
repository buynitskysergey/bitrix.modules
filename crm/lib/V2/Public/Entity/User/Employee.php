<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\User;

use Bitrix\Main\Web\Uri;

final class Employee
{
	public function __construct(
		private readonly ?int $id = null,
		private readonly ?string $firstName = null,
		private readonly ?string $lastName = null,
		private readonly ?string $formattedName = null,
		private readonly ?string $personalGender = null,
		private readonly ?Uri $showUrl = null,
		private readonly ?Uri $photoUrl = null,
	)
	{
	}

	public function getId(): ?int
	{
		return $this->id;
	}

	public function getFirstName(): ?string
	{
		return $this->firstName;
	}

	public function getLastName(): ?string
	{
		return $this->lastName;
	}

	public function getFormattedName(): ?string
	{
		return $this->formattedName;
	}

	public function getPersonalGender(): ?string
	{
		return $this->personalGender;
	}

	public function getShowUrl(): ?Uri
	{
		return $this->showUrl;
	}

	public function getPhotoUrl(): ?Uri
	{
		return $this->photoUrl;
	}
}

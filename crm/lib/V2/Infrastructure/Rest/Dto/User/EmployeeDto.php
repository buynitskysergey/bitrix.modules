<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\User;

use Bitrix\Crm\V2\Public\Entity\User\Employee;
use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Interaction\Request\Request;

class EmployeeDto extends Dto
{
	#[Filterable]
	public int $id;
	public ?string $firstName;
	public ?string $lastName;
	public ?string $personalGender;
	public ?string $url;
	public ?string $personalPhoto;

	public static function fromEntity(?Employee $employee, array $select = []): ?self
	{
		if (!$employee)
		{
			return null;
		}

		$dto = new self();
		if (empty($select) || in_array('id', $select, true))
		{
			$dto->id = $employee->getId();
		}
		if (empty($select) || in_array('firstName', $select, true))
		{
			$dto->firstName = $employee->getFirstName();
		}
		if (empty($select) || in_array('lastName', $select, true))
		{
			$dto->lastName = $employee->getLastName();
		}
		if (empty($select) || in_array('personalGender', $select, true))
		{
			$dto->personalGender = $employee->getPersonalGender();
		}
		if (empty($select) || in_array('url', $select, true))
		{
			$dto->url = $employee->getShowUrl()?->getUri();
		}
		if (empty($select) || in_array('personalPhoto', $select, true))
		{
			$dto->personalPhoto = $employee->getPhotoUrl()?->getUri();
		}

		return $dto;
	}
}

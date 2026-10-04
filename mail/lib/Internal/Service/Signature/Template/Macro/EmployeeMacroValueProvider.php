<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\Signature\Template\Macro;

use Bitrix\Mail\Internal\Service\Signature\Template\SignatureTemplateContext;
use Bitrix\Main\UserTable;

final class EmployeeMacroValueProvider implements SignatureMacroValueProvider
{
	private const SELECT = [
		'NAME',
		'LAST_NAME',
		'SECOND_NAME',
		'WORK_POSITION',
		'WORK_PHONE',
		'PERSONAL_MOBILE',
		'EMAIL',
		'PERSONAL_WWW',
		'UF_DEPARTMENT',
	];

	private DepartmentNameProvider $departmentNameProvider;
	private \Closure $userLoader;
	private array $snapshots = [];

	public function __construct(
		?DepartmentNameProvider $departmentNameProvider = null,
		?\Closure $userLoader = null,
	)
	{
		$this->departmentNameProvider = $departmentNameProvider ?? new DepartmentNameProvider();
		$this->userLoader = $userLoader ?? static fn(int $userId): ?array => UserTable::query()
			->setSelect(self::SELECT)
			->where('ID', $userId)
			->setLimit(1)
			->fetch() ?: null;
	}

	public function getValues(SignatureTemplateContext $context, array $ids = []): array
	{
		if ($ids !== [] && !$this->containsEmployeeId($ids))
		{
			return [];
		}

		$snapshot = $this->getSnapshot($context->userId);
		$name = $this->getString($snapshot, 'NAME');
		$lastName = $this->getString($snapshot, 'LAST_NAME');
		$secondName = $this->getString($snapshot, 'SECOND_NAME');

		$values = [
			'employee.name' => $name,
			'employee.lastName' => $lastName,
			'employee.secondName' => $secondName,
			'employee.fullName' => $this->joinNameParts([$lastName, $name, $secondName]),
			'employee.firstLastName' => $this->joinNameParts([$name, $lastName]),
			'employee.position' => $this->getString($snapshot, 'WORK_POSITION'),
			'employee.department' => '',
			'employee.workPhone' => $this->getString($snapshot, 'WORK_PHONE', false),
			'employee.mobilePhone' => $this->getString($snapshot, 'PERSONAL_MOBILE', false),
			'employee.email' => $context->senderEmail,
			'employee.website' => $this->getString($snapshot, 'PERSONAL_WWW', false),
		];
		if ($ids === [] || in_array('employee.department', $ids, true))
		{
			$values['employee.department'] = $this->departmentNameProvider->getName(
				$this->getDepartmentIds($snapshot['UF_DEPARTMENT'] ?? null),
			);
		}

		return $ids === [] ? $values : array_intersect_key($values, array_flip($ids));
	}

	private function containsEmployeeId(array $ids): bool
	{
		foreach ($ids as $id)
		{
			if (str_starts_with($id, 'employee.'))
			{
				return true;
			}
		}

		return false;
	}

	private function getSnapshot(int $userId): array
	{
		if (!array_key_exists($userId, $this->snapshots))
		{
			try
			{
				$this->snapshots[$userId] = ($this->userLoader)($userId) ?? [];
			}
			catch (\Throwable)
			{
				$this->snapshots[$userId] = [];
			}
		}

		return $this->snapshots[$userId];
	}

	private function getString(array $snapshot, string $field, bool $trim = true): string
	{
		$value = (string)($snapshot[$field] ?? '');

		return $trim ? trim($value) : $value;
	}

	private function joinNameParts(array $parts): string
	{
		return implode(' ', array_filter($parts, static fn(string $part): bool => $part !== ''));
	}

	private function getDepartmentIds(mixed $value): array
	{
		if (is_array($value))
		{
			return $value;
		}

		return $value === null || $value === '' ? [] : [$value];
	}
}

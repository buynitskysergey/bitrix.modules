<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\Signature\Template\Macro;

use Bitrix\HumanResources\Public\Service\Container;
use Bitrix\Main\Loader;

final class DepartmentNameProvider
{
	private \Closure $moduleLoader;
	private \Closure $nodeNameLoader;
	private array $names = [];

	public function __construct(?\Closure $moduleLoader = null, ?\Closure $nodeNameLoader = null)
	{
		$this->moduleLoader = $moduleLoader ?? static fn(): bool => Loader::includeModule('humanresources');
		$this->nodeNameLoader = $nodeNameLoader ?? static fn(int $departmentId): ?string =>
			Container::getNodeService()->getById($departmentId)?->name
		;
	}

	public function getName(array $departmentIds): string
	{
		$departmentIds = array_values(
			array_filter(
				array_map('intval', $departmentIds),
				static fn(int $departmentId): bool => $departmentId > 0,
			),
		);
		if (count($departmentIds) !== 1)
		{
			return '';
		}

		$departmentId = $departmentIds[0];
		if (array_key_exists($departmentId, $this->names))
		{
			return $this->names[$departmentId];
		}

		try
		{
			if (!($this->moduleLoader)())
			{
				return $this->names[$departmentId] = '';
			}

			$name = ($this->nodeNameLoader)($departmentId);
			return $this->names[$departmentId] = trim((string)$name);
		}
		catch (\Throwable)
		{
			return $this->names[$departmentId] = '';
		}
	}
}

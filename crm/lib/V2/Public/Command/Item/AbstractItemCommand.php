<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Command\Item;

use Bitrix\Main\Command\CommandInterface;
use Bitrix\Main\Result;

abstract class AbstractItemCommand implements CommandInterface
{
	private Scope $scope = Scope::Manual;
	private bool $checkPermissions = true;
	private bool $checkRequiredUserFields = true;
	private bool $runAutomation = true;

	public function __construct(
		private readonly int $userId,
	)
	{
	}

	abstract protected function execute(): Result;

	public function run(): Result
	{
		return $this->execute();
	}

	public function getUserId(): int
	{
		return $this->userId;
	}

	public function getScope(): Scope
	{
		return $this->scope;
	}

	public function setScope(Scope $scope): static
	{
		$this->scope = $scope;

		$this->checkPermissions = match ($scope)
		{
			Scope::Automation, Scope::System => false,
			default => true,
		};
		$this->checkRequiredUserFields = true;
		$this->runAutomation = match ($scope)
		{
			Scope::Automation, Scope::Import => false,
			default => true,
		};

		return $this;
	}

	public function withoutPermissionCheck(bool $without = true): static
	{
		$this->checkPermissions = !$without;

		return $this;
	}

	public function withoutRequiredUserFieldsCheck(bool $without = true): static
	{
		$this->checkRequiredUserFields = !$without;

		return $this;
	}

	public function withoutAutomation(bool $without = true): static
	{
		$this->runAutomation = !$without;

		return $this;
	}

	public function shouldCheckPermissions(): bool
	{
		return $this->checkPermissions;
	}

	public function shouldCheckRequiredUserFields(): bool
	{
		return $this->checkRequiredUserFields;
	}

	public function shouldRunAutomation(): bool
	{
		return $this->runAutomation;
	}
}

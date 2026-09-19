<?php

namespace Bitrix\Sign\Access;

use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Main\Loader;
use Bitrix\Sign\Access\Model\UserModel;
use Bitrix\Sign\Access\Permission\SignPermissionDictionary;
use Bitrix\Sign\Access\Service\RolePermissionService;

/**
 * Resolves the reversible document annulment permission (SIGN_DOCUMENT_ANNUL)
 * once per user and answers owner-scoped checks for many documents without
 * re-running the access rule per row.
 *
 * The permission is owner-scoped (ALL/SELF/DEPARTMENT/SUBDEPARTMENT); the scope
 * is resolved lazily on the first check and memoized for subsequent calls, so a
 * single instance can gate a whole grid page or a batch action cheaply.
 */
final class DocumentAnnulPermission
{
	private bool $resolved = false;
	private bool $isAdmin = false;
	private ?string $permissionValue = null;
	/** @var array<int, true>|null Allowed owner ids; null means every owner is allowed (ALL). */
	private ?array $allowedOwnerIds = [];

	public function __construct(
		private readonly UserModel $user,
		private readonly RolePermissionService $rolePermissionService = new RolePermissionService(),
	)
	{
	}

	public static function forUser(int $userId): self
	{
		return new self(UserModel::createFromId($userId));
	}

	/**
	 * Reuses an already-built UserModel (e.g. the one the access controller
	 * memoizes for the current request) so the owner-scope department resolve is
	 * not repeated on top of an equivalent model built from scratch.
	 */
	public static function fromUserModel(UserModel $user): self
	{
		return new self($user);
	}

	public function canAnnulDocumentOwnedBy(?int $ownerId): bool
	{
		$this->resolve();

		if ($this->isAdmin)
		{
			return true;
		}

		if ($this->permissionValue === null || $this->permissionValue === UserPermissions::PERMISSION_NONE)
		{
			return false;
		}

		// null means the ALL scope: any owner is allowed.
		if ($this->allowedOwnerIds === null)
		{
			return true;
		}

		return $ownerId !== null && isset($this->allowedOwnerIds[$ownerId]);
	}

	private function resolve(): void
	{
		if ($this->resolved)
		{
			return;
		}
		$this->resolved = true;

		if (!Loader::includeModule('crm'))
		{
			$this->permissionValue = null;

			return;
		}

		$this->isAdmin = $this->user->isAdmin();
		if ($this->isAdmin)
		{
			return;
		}

		$this->permissionValue = $this->rolePermissionService->getValueForPermission(
			$this->user->getRoles(),
			(string)SignPermissionDictionary::SIGN_DOCUMENT_ANNUL,
		);

		$this->allowedOwnerIds = match ($this->permissionValue)
		{
			UserPermissions::PERMISSION_ALL => null,
			UserPermissions::PERMISSION_SUBDEPARTMENT => array_fill_keys($this->user->getUserDepartmentMembers(true), true),
			UserPermissions::PERMISSION_DEPARTMENT => array_fill_keys($this->user->getUserDepartmentMembers(), true),
			UserPermissions::PERMISSION_SELF => [$this->user->getUserId() => true],
			default => [],
		};
	}
}

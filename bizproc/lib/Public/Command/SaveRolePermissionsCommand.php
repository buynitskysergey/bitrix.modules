<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Command;

use Bitrix\Bizproc\Internal\Service\RolePermissionService;
use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Result;

/**
 * Public write entry point for the role/permission matrix. Every channel (permissions page, REST, config
 * import) builds this command and runs it; the delegate scope validator and the transactional delta/replace
 * write live in {@see RolePermissionService}, so the invariant cannot be bypassed by a non-UI path.
 *
 * `$userGroups` is the FULL desired state of the delegate's area — a permission absent from a role's
 * `accessRights` is a removal inside that area, not "left untouched" (see the delta write). `$delegateId`
 * is the acting user whose committed area constrains the write.
 */
final class SaveRolePermissionsCommand extends AbstractCommand
{
	/**
	 * @param array<int, array{
	 *     id?: int|string,
	 *     title?: string,
	 *     accessRights?: array<int, array{id: int|string, value: int|string}>,
	 *     members?: array<string, mixed>,
	 *     accessCodes?: array<string, mixed>,
	 * }> $userGroups
	 * @param array<int, int|string> $deletedUserGroups
	 */
	public function __construct(
		public readonly int $delegateId,
		public readonly array $userGroups,
		public readonly array $deletedUserGroups = [],
	)
	{
	}

	public function toArray(): array
	{
		return [
			'delegateId' => $this->delegateId,
			'userGroups' => $this->userGroups,
			'deletedUserGroups' => $this->deletedUserGroups,
		];
	}

	public static function mapFromArray(array $props): self
	{
		return new self(
			delegateId: (int)($props['delegateId'] ?? 0),
			userGroups: (array)($props['userGroups'] ?? []),
			deletedUserGroups: (array)($props['deletedUserGroups'] ?? []),
		);
	}

	protected function execute(): Result
	{
		return (new RolePermissionService())->saveRolePermissions(
			$this->delegateId,
			$this->userGroups,
			$this->deletedUserGroups,
		);
	}
}

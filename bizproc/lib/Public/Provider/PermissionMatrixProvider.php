<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Provider;

use Bitrix\Bizproc\Internal\Access\AccessController;
use Bitrix\Bizproc\Internal\Access\Permission\PermissionDictionary;
use Bitrix\Bizproc\Internal\Integration\UI\AccessRights\V2\AccessRightsProvider;
use Bitrix\Bizproc\Internal\Integration\UI\AccessRights\V2\Models\RightModel;
use Bitrix\Bizproc\Internal\Service\Feature\BpDesignerFeature;
use Bitrix\Main\Config\Option;
use Bitrix\UI\AccessRights\V2\AccessRightsBuilder;
use Bitrix\UI\AccessRights\V2\Dto\Controller\UserGroupDto;
use Bitrix\UI\AccessRights\V2\Options;
use Bitrix\UI\AccessRights\V2\Options\RightSection;
use Bitrix\UI\AccessRights\V2\Options\UserGroup;

/**
 * Provider for the permissions page: builds the ui.accessrights.v2 page options and the load payload, and
 * decodes the UI right ids back to permission ids for the save command.
 *
 * Reads (getData/getOptions) go through the ui.accessrights.v2 builder over the AccessRightsProvider, so the
 * matrix, the role models and the scope pick list share one source of truth. decodeUserGroups maps the UI
 * right ids (entity~~~action) plus access codes into the shape SaveRolePermissionsCommand expects; the caller
 * runs the command so the configure-rights gate always applies.
 *
 * canConfigure answers whether the user may configure rights at all: the CONFIGURE_RIGHTS toggler or a
 * portal administrator, which grants full access to the matrix (any right, every role, every template).
 */
final class PermissionMatrixProvider
{
	private const MODULE_ID = 'bizproc';
	private ?AccessRightsBuilder $displayBuilder = null;

	public function __construct(
		private readonly ?int $userId = null,
	)
	{
	}

	public function canConfigure(): bool
	{
		return $this->controller()->check((string)PermissionDictionary::BIZPROC_TEMPLATE_CONFIGURE_RIGHTS);
	}

	/**
	 * Portal-level product/tariff gate for the permissions configuration surface: the new designer must be on
	 * and the tariff feature available. Shared by every entry point (the page ajax controller and the scope
	 * EntitySelector popup) so no path reaches the matrix past a weaker check. The per-user CONFIGURE_RIGHTS
	 * check stays with each caller.
	 */
	public static function isConfigurationFeatureEnabled(): bool
	{
		return Option::get(self::MODULE_ID, 'designer_v2', 'N') === 'Y'
			&& (new BpDesignerFeature())->isAvailable();
	}

	public function getOptions(string $component, string $containerId): Options
	{
		$builder = $this->displayBuilder();
		$options = new Options($component, $containerId);
		$options
			->setModuleId(self::MODULE_ID)
			->setActionSave('savePermissions')
			->setIsSaveOnlyChangedRights(false)
			->setSearchContainerSelector('#uiToolbarContainer')
			->setAccessRights($builder->buildAccessRights())
			->setUserGroups($builder->buildUserGroups())
		;

		return $options;
	}

	/**
	 * @return array{USER_GROUPS: array, ACCESS_RIGHTS: array} payload for ajax load / save reload.
	 */
	public function getData(): array
	{
		$builder = $this->displayBuilder();

		return [
			'USER_GROUPS' => array_map(static fn (UserGroup $group): array => $group->toArray(), $builder->buildUserGroups()),
			'ACCESS_RIGHTS' => array_map(static fn (RightSection $section): array => $section->toArray(), $builder->buildAccessRights()),
		];
	}

	/**
	 * Decodes the UI payload into the SaveRolePermissionsCommand input. The UI sends right ids as
	 * `entity~~~action`; they are decoded back to numeric permission ids, access codes become members.
	 *
	 * @param array<int, array> $userGroups
	 * @return array<int, array{id: int, title: string, accessRights: array, members: array}>
	 */
	public function decodeUserGroups(array $userGroups): array
	{
		$decodeBuilder = $this->builder(false);

		$mappedUserGroups = [];
		foreach (UserGroupDto::fromArrayList($userGroups) as $index => $userGroup)
		{
			$accessRights = [];
			foreach ($userGroup->accessRights as $accessRight)
			{
				$model = $decodeBuilder->decodeAccessCode($accessRight);
				if (!$model instanceof RightModel)
				{
					continue;
				}

				$accessRights[] = ['id' => (int)$model->actionId, 'value' => (int)$model->getValue()];
			}

			$mappedUserGroup = [
				'id' => (int)$userGroup->id,
				'title' => (string)$userGroup->title,
				'accessRights' => $accessRights,
			];
			if (array_key_exists('accessCodes', $userGroups[$index] ?? []))
			{
				$members = [];
				foreach ($userGroup->accessCodes as $accessCode)
				{
					$members[(string)$accessCode->accessCode] = (string)$accessCode->type;
				}
				$mappedUserGroup['members'] = $members;
			}

			$mappedUserGroups[] = $mappedUserGroup;
		}

		return $mappedUserGroups;
	}

	private function builder(bool $withVariables): AccessRightsBuilder
	{
		return new AccessRightsBuilder(new AccessRightsProvider($this->userId, $withVariables));
	}

	private function displayBuilder(): AccessRightsBuilder
	{
		return $this->displayBuilder ??= $this->builder(true);
	}

	private function controller(): AccessController
	{
		return $this->userId === null
			? AccessController::getCurrent()
			: AccessController::getInstance($this->userId);
	}
}

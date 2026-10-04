<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Integration\UI\AccessRights\V2;

use Bitrix\Bizproc\Integration\UI\EntitySelector\AccessTemplateProvider;
use Bitrix\Bizproc\Internal\Access\AccessController;
use Bitrix\Bizproc\Internal\Access\Permission\PermissionDictionary;
use Bitrix\Bizproc\Internal\Container;
use Bitrix\Bizproc\Internal\Entity\Access\RoleCollection;
use Bitrix\Bizproc\Internal\Service\DelegationScopeValidator;
use Bitrix\Bizproc\Internal\Integration\UI\AccessRights\V2\Models\RightId;
use Bitrix\Bizproc\Internal\Integration\UI\AccessRights\V2\Models\RightIdConverter;
use Bitrix\Bizproc\Internal\Integration\UI\AccessRights\V2\Models\RightModel;
use Bitrix\Bizproc\Internal\Integration\UI\AccessRights\V2\Structure\Entity\Template;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Type\Collection;
use Bitrix\UI\AccessRights\V2\Contract\AccessRightsBuilder\Provider;
use Bitrix\UI\AccessRights\V2\Control\Value\VariablesSource;
use Bitrix\UI\AccessRights\V2\Dto\AccessRightsBuilder\UserGroupModelDto;
use Bitrix\UI\AccessRights\V2\Options\RightSection\RightItem;

/**
 * ui.accessrights.v2 provider for the bizproc template ACL matrix. Pure UI layer: it never
 * persists (the write path is {@see \Bitrix\Bizproc\Public\Command\SaveRolePermissionsCommand}); it only
 * exposes the single template entity with its five rights and the roles with their matrix and access codes.
 *
 * Template variables are loaded only for the display path; the save/decode path does not query templates.
 *
 * Roles, their ids and their permission cells are one memoized snapshot shared by the variables and the role
 * models: the repository memoizes nothing, so a second getAllRoles() would be a second query, and the two
 * consumers run in either order (getOptions() builds the rights first, getData() the roles first).
 */
final class AccessRightsProvider implements Provider
{
	public const ENTITY_ID = 'bizproc-template';

	private ?array $entities = null;
	private ?array $variablesMap = null;
	private ?array $rolePermissions = null;
	private ?bool $lazyTemplatesUsable = null;
	private ?bool $templateAdmin = null;

	public function __construct(
		private readonly ?int $userId = null,
		private readonly bool $withVariables = true,
		private readonly DelegationScopeValidator $scopeValidator = new DelegationScopeValidator(),
		private readonly string $uiValueSourceClass = VariablesSource::class,
		private readonly string $uiRightItemClass = RightItem::class,
	)
	{
	}

	public function loadEntities(): array
	{
		$this->entities ??= [
			new Template(
				$this->getVariablesMap(),
				$this->getAllSelectableByPermission(),
				// The save path decodes values only, so it needs neither the value source nor the "all" caption.
				$this->withVariables && $this->isLazyTemplatesUsable(),
			),
		];

		return $this->entities;
	}

	/**
	 * @return UserGroupModelDto[]
	 */
	public function loadUserGroupModels(): array
	{
		$snapshot = $this->loadRolePermissions();

		$roleIds = $snapshot['roleIds'];
		if (!$roleIds)
		{
			return [];
		}

		$touchable = array_fill_keys($roleIds, true);
		$permissionsByRole = $snapshot['permissionsByRole'];
		$accessCodesByRole = Container::getAccessRepository()->getAccessCodesByRoleIds($roleIds);

		$models = [];
		foreach ($snapshot['roles'] as $role)
		{
			$roleId = (int)$role->getId();
			if (!isset($touchable[$roleId]))
			{
				continue;
			}

			$model = new UserGroupModelDto($roleId, (string)$role->getName());

			foreach ($accessCodesByRole[$roleId] ?? [] as $accessCode)
			{
				$model->addAccessCode($accessCode);
			}

			foreach ($permissionsByRole[$roleId] ?? [] as $permission)
			{
				$model->addAccessRightModel(
					new RightModel(self::ENTITY_ID, (string)$permission['id'], $permission['value']),
				);
			}

			$models[] = $model;
		}

		return $models;
	}

	private function resolveUserId(): int
	{
		return $this->userId ?? AccessController::getCurrent()->getUser()->getUserId();
	}

	public function getRightIdConverter(): RightIdConverter
	{
		return new RightIdConverter();
	}

	public function createRightModelByRightId(Provider\Models\RightId|RightId $id, mixed $value): ?RightModel
	{
		return new RightModel($id->entityId, $id->actionId, $value);
	}

	/**
	 * The single source of roles and role permissions for the display path: the role collection (the models
	 * need the names), the ids the user may touch and every permission cell of those roles.
	 *
	 * @return array{roles: RoleCollection, roleIds: int[], permissionsByRole: array<int, array<int, array{id: int, value: string}>>}
	 */
	private function loadRolePermissions(): array
	{
		if ($this->rolePermissions !== null)
		{
			return $this->rolePermissions;
		}

		$roles = Container::getAccessRepository()->getAllRoles();

		// A configuring user sees every role; all other users see none.
		$roleIds = $this->scopeValidator->filterTouchableRoleIds($this->resolveUserId(), $roles->getEntityIds());
		Collection::normalizeArrayValuesByInt($roleIds, false);

		$this->rolePermissions = [
			'roles' => $roles,
			'roleIds' => $roleIds,
			'permissionsByRole' => $this->loadPermissionsByRole($roleIds),
		];

		return $this->rolePermissions;
	}

	/**
	 * @param int[] $roleIds
	 * @return array<int, array<int, array{id: int, value: string}>> role id => permission cells
	 */
	private function loadPermissionsByRole(array $roleIds): array
	{
		$result = [];
		foreach (Container::getAccessRepository()->getPermissionsByRoleIds($roleIds) as $permission)
		{
			// The control layer speaks strings (template id, the "all" sentinel, toggler value);
			// the save path converts them back to int for the ORM.
			$result[(int)$permission->getRoleId()][] = [
				'id' => (int)$permission->getPermissionId(),
				'value' => (string)$permission->getValue(),
			];
		}

		return $result;
	}

	/**
	 * Per-multivariables-right flag whether the configuring user may pick "all".
	 *
	 * @return array<int, bool> permission id => user may pick "all"
	 */
	private function getAllSelectableByPermission(): array
	{
		if (!$this->withVariables || !$this->scopeValidator->canConfigure($this->resolveUserId()))
		{
			return [];
		}

		return array_fill_keys($this->getMultivariablesPermissionIds(), true);
	}

	/**
	 * @return array<int, array<int, array{id: int, title: string}>>
	 */
	private function getVariablesMap(): array
	{
		if ($this->variablesMap !== null)
		{
			return $this->variablesMap;
		}

		$this->variablesMap = [];
		if (!$this->withVariables || !$this->scopeValidator->canConfigure($this->resolveUserId()))
		{
			return $this->variablesMap;
		}

		$this->variablesMap = $this->isLazyTemplatesUsable()
			? $this->collectVariablesFromMatrix()
			: $this->allNodesTemplatesByPermission()
		;

		return $this->variablesMap;
	}

	/**
	 * ALG-01: the ids come from the values of the multivariables rights, the threshold cuts every right down to
	 * its first ITEMS_LIMIT ids, and one indexed query loads the titles of what is left. One query for every
	 * right and every role, never one per cell.
	 *
	 * The threshold runs before the query on purpose. Its cost then depends on the shown ids alone
	 * (ITEMS_LIMIT per right), not on how many templates the portal granted by name. And a single query keeps
	 * resolvability and title in one snapshot: a row deleted right now moves an id to the placeholder branch
	 * instead of dropping it out of both branches.
	 *
	 * @return array<int, array<int, array{id: int, title: string}>>
	 */
	private function collectVariablesFromMatrix(): array
	{
		// A right with no granted value keeps its key with an empty list: the client tells that state apart
		// from "no source given".
		$variables = array_fill_keys($this->getMultivariablesPermissionIds(), []);

		$idsByPermission = [];
		foreach ($this->loadRolePermissions()['permissionsByRole'] as $cells)
		{
			foreach ($cells as $cell)
			{
				$permissionId = $cell['id'];
				if (!array_key_exists($permissionId, $variables))
				{
					continue;
				}

				$templateId = (int)$cell['value'];
				// the "all" sentinel (-1), 0 and any other noise are not template ids
				if ($templateId <= 0)
				{
					continue;
				}

				$idsByPermission[$permissionId][$templateId] = $templateId;
			}
		}

		$shownIdsByPermission = [];
		$shownIds = [];
		foreach ($idsByPermission as $permissionId => $idSet)
		{
			$ids = array_keys($idSet);
			rsort($ids);

			// The threshold counts every element of the right, titles and placeholders together: a role with
			// hundreds of unresolvable ids would otherwise bring the payload back to what it was.
			$shown = array_slice($ids, 0, AccessTemplateProvider::ITEMS_LIMIT);
			$shownIdsByPermission[$permissionId] = $shown;
			$shownIds += array_fill_keys($shown, true);
		}

		if (!$shownIds)
		{
			return $variables;
		}

		$titles = $this->selectTemplateTitles(array_keys($shownIds));
		$withPostfix = $this->isTemplateAdmin();

		foreach ($shownIdsByPermission as $permissionId => $ids)
		{
			foreach ($ids as $id)
			{
				if (isset($titles[$id]))
				{
					$postfix = $withPostfix ? AccessTemplateProvider::makeTitlePostfix($id) : '';
					$variables[$permissionId][] = ['id' => $id, 'title' => $titles[$id] . $postfix];
				}
				else
				{
					$variables[$permissionId][] = ['id' => $id, 'title' => self::makePlaceholderTitle($id)];
				}
			}
		}

		return $variables;
	}

	/**
	 * Every Nodes template of the portal in every multivariables right: the behaviour before the lazy scope,
	 * kept as the branch of a disabled OPT-01.
	 *
	 * @return array<int, array<int, array{id: int, title: string}>>
	 */
	private function allNodesTemplatesByPermission(): array
	{
		$rows =
			AccessTemplateProvider::makeScopeQuery()
				->setSelect(['ID', 'NAME'])
				->setOrder(['ID' => 'ASC'])
				->exec()
				->fetchAll()
		;

		$templates = array_map(
			static fn (array $row): array => ['id' => (int)$row['ID'], 'title' => (string)$row['NAME']],
			$rows,
		);

		return array_fill_keys($this->getMultivariablesPermissionIds(), $templates);
	}

	/**
	 * Resolvability and title in one answer: a missing key means the id resolves to no template of the page
	 * scope, so the caller renders a placeholder for it.
	 *
	 * @param int[] $ids
	 * @return array<int, string> template id => name
	 */
	private function selectTemplateTitles(array $ids): array
	{
		if (!$ids)
		{
			return [];
		}

		$rows =
			AccessTemplateProvider::makeScopeQuery()
				->setSelect(['ID', 'NAME'])
				->whereIn('ID', $ids)
				->exec()
				->fetchAll()
		;

		$titles = [];
		foreach ($rows as $row)
		{
			$titles[(int)$row['ID']] = (string)$row['NAME'];
		}

		return $titles;
	}

	/**
	 * Caption of an id the page scope resolves to no template. The threshold leaves the ids past the first
	 * ITEMS_LIMIT without an element of their own, so the client captions those from the same phrase
	 * {@see self::makePlaceholderTitlePattern()}: one template never gets two different captions.
	 */
	private static function makePlaceholderTitle(int|string $id): string
	{
		return (string)Loc::getMessage('BIZPROC_ACCESS_RIGHTS_TEMPLATE_PLACEHOLDER', ['#ID#' => (string)$id]);
	}

	/**
	 * The same caption with the id left for the client to put in.
	 */
	public static function makePlaceholderTitlePattern(): string
	{
		return self::makePlaceholderTitle(RightItem::UNLOADED_VARIABLE_VALUE_PLACEHOLDER);
	}

	/**
	 * Same predicate as the items of the scope entity (API-01): a template administrator sees the id in the
	 * caption, so one template never gets two different captions before and after a save.
	 */
	private function isTemplateAdmin(): bool
	{
		$this->templateAdmin ??= (new \CBPWorkflowTemplateUser($this->resolveUserId()))->isAdmin();

		return $this->templateAdmin;
	}

	/**
	 * The option and the ui contract are two independent reasons to stay on the branch before OPT-01: either one
	 * keeps the static list, and neither is a condition of the other. bizproc may run against a ui that predates
	 * the value source, and then the page stays as it was instead of calling an API that is not there. Silently,
	 * because for the user that is simply the page it had before.
	 */
	private function isLazyTemplatesUsable(): bool
	{
		$this->lazyTemplatesUsable ??= LazyTemplatesOption::isEnabled() && $this->isUiContractAvailable();

		return $this->lazyTemplatesUsable;
	}

	/**
	 * Two probes and not one, because the elements of the contract reached ui in separate commits and an update
	 * is assembled commit by commit: a ui carrying the value source without the placeholder constant, or the
	 * other way round, is a state this module can be installed next to. One element per commit is enough - what
	 * came along with the probed element cannot be missing while the element itself is there.
	 *
	 * Three of the five calls this module makes into the new ui API are never probed directly -
	 * MultiVariables::variablesSource(), allSelectedTitle() and unloadedVariableTitlePattern(). They rest on the
	 * invariant that an update takes a ui commit whole: each of them came to ui with the same addition as one of
	 * the two probed elements, so it is there whenever its probe passes.
	 *
	 * The probed names are constructor parameters holding the ui defaults: a class of ui that is loaded cannot be
	 * taken off the autoloader, so a test points the probes at names of its own instead.
	 */
	private function isUiContractAvailable(): bool
	{
		return class_exists($this->uiValueSourceClass)
			&& defined($this->uiRightItemClass . '::UNLOADED_VARIABLE_VALUE_PLACEHOLDER');
	}

	/**
	 * @return int[]
	 */
	private function getMultivariablesPermissionIds(): array
	{
		$ids = [];
		foreach (array_keys(PermissionDictionary::getList()) as $permissionId)
		{
			$descriptor = PermissionDictionary::getPermission((string)$permissionId);
			if (($descriptor['type'] ?? null) === PermissionDictionary::TYPE_MULTIVARIABLES)
			{
				$ids[] = (int)$permissionId;
			}
		}

		return $ids;
	}
}

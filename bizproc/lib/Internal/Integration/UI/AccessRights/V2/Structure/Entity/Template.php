<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Integration\UI\AccessRights\V2\Structure\Entity;

use Bitrix\Bizproc\Integration\UI\EntitySelector\AccessTemplateProvider;
use Bitrix\Bizproc\Internal\Access\Permission\PermissionDictionary;
use Bitrix\Bizproc\Internal\Integration\UI\AccessRights\V2\AccessRightsProvider;
use Bitrix\Bizproc\Internal\Integration\UI\AccessRights\V2\Control\MultiVariables;
use Bitrix\Bizproc\Internal\Integration\UI\AccessRights\V2\Structure\Action\TemplateAction;
use Bitrix\Main\Localization\Loc;
use Bitrix\UI\AccessRights\V2\Contract\AccessRightsBuilder\Provider\Structure\Entity;
use Bitrix\UI\AccessRights\V2\Control\Toggler;
use Bitrix\UI\AccessRights\V2\Control\Value\MultiVariable;
use Bitrix\UI\AccessRights\V2\Control\Value\VariablesSource;
use Bitrix\UI\AccessRights\V2\Dto\AccessRightsBuilder\PermissionDto;

/**
 * The single «workflow template» entity of the rights matrix: five permissions where CREATE and
 * CONFIGURE_RIGHTS are togglers and EDIT / PUBLISH / DELETE are multivariables over the template scope.
 */
final class Template implements Entity
{
	private const SEARCH_MATCH_MODE_ALL = 'all';

	/** @var PermissionDto[]|null */
	private ?array $permissions = null;

	/**
	 * @param array<int, array<int, array{id: int, title: string}>> $variablesByPermission permission id => templates
	 * @param array<int, bool> $allSelectableByPermission permission id => configuring user may pick "all"
	 * @param bool $withVariablesSource OPT-01, resolved by the matrix provider: this entity never reads the option
	 */
	public function __construct(
		private readonly array $variablesByPermission = [],
		private readonly array $allSelectableByPermission = [],
		private readonly bool $withVariablesSource = false,
	)
	{
	}

	public function getId(): string
	{
		return AccessRightsProvider::ENTITY_ID;
	}

	public function getTitle(): string
	{
		return (string)Loc::getMessage('BIZPROC_ACCESS_ENTITY_TEMPLATE_TITLE');
	}

	/**
	 * The builder asks the entity for its permissions once per rendered right and once more per matrix cell of
	 * every role, and it only reads the controls it gets back. The entity is immutable, so one built set serves
	 * every call instead of rebuilding five actions, three controls and their captions each time.
	 *
	 * @return PermissionDto[]
	 */
	public function getPermissions(): array
	{
		$this->permissions ??= $this->buildPermissions();

		return $this->permissions;
	}

	/**
	 * @return PermissionDto[]
	 */
	private function buildPermissions(): array
	{
		$permissions = [];
		foreach (array_keys(PermissionDictionary::getList()) as $permissionId)
		{
			$permissionId = (int)$permissionId;
			$descriptor = PermissionDictionary::getPermission((string)$permissionId);

			$action = new TemplateAction(
				(string)$permissionId,
				(string)($descriptor['title'] ?? ''),
				(string)($descriptor['hint'] ?? ''),
			);

			$permissions[] = new PermissionDto($action, $this->createControl($permissionId, $descriptor));
		}

		return $permissions;
	}

	private function createControl(int $permissionId, array $descriptor)
	{
		if (($descriptor['type'] ?? null) === PermissionDictionary::TYPE_TOGGLER)
		{
			return (new Toggler((string)PermissionDictionary::VALUE_YES, (string)PermissionDictionary::VALUE_NO))
				->default((string)PermissionDictionary::VALUE_NO)
				->nothingSelected((string)PermissionDictionary::VALUE_NO)
				->empty((string)PermissionDictionary::VALUE_NO)
			;
		}

		$variables = [];
		foreach ($this->variablesByPermission[$permissionId] ?? [] as $template)
		{
			$variables[] = new MultiVariable((string)$template['title'], (string)$template['id']);
		}

		$allSelectable = $this->allSelectableByPermission[$permissionId] ?? false;
		$control = (new MultiVariables())
			->variables($variables)
			->enableSearch(true)
		;

		if ($this->withVariablesSource)
		{
			$control
				->variablesSource(new VariablesSource(
					entityId: AccessTemplateProvider::ENTITY_ID,
					dynamicSearchMatchMode: self::SEARCH_MATCH_MODE_ALL,
					itemEntityId: AccessTemplateProvider::ENTITY_ID,
				))
				->allSelectedTitle((string)Loc::getMessage('BIZPROC_ACCESS_ENTITY_TEMPLATE_ALL_SELECTED_TITLE'))
				// the variables of the right name only the first ITEMS_LIMIT ids, so the dialog builds the very
				// same placeholder for the rest
				->unloadedVariableTitlePattern(AccessRightsProvider::makePlaceholderTitlePattern())
			;
		}

		if ($allSelectable)
		{
			$control->allSelectedCode((string)PermissionDictionary::VALUE_VARIATION_ALL);
		}

		return $control;
	}
}

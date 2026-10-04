<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Integration\AiAssistant\Agents;

use Bitrix\AiAssistant\Definition\Agent\BaseAgent;
use Bitrix\AiAssistant\Definition\Dto\DefinitionMetadataDto;
use Bitrix\AiAssistant\Definition\Dto\SystemPromptDto;
use Bitrix\AiAssistant\Definition\Dto\UsesToolsDto;
use Bitrix\HumanResources\Access\Model\UserModel;
use Bitrix\HumanResources\Access\Permission\PermissionDictionary;
use Bitrix\HumanResources\Access\Permission\PermissionHelper;
use Bitrix\HumanResources\Access\Permission\PermissionVariablesDictionary;
use Bitrix\HumanResources\Config\Storage;
use Bitrix\HumanResources\Enum\Access\RoleCategory;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\Access\CreateRoleTool;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\Access\DeleteRoleTool;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\Access\RoleListTool;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\Access\RolePermissionsTool;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\Access\UpdateRoleTool;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\Access\UserPermissionsTool;
use Bitrix\HumanResources\Internals\Service\Container as InternalContainer;

class AccessAgent extends BaseAgent
{
	public function getCode(): string
	{
		return 'access';
	}

	public function getSystemPrompt(): SystemPromptDto
	{
		return new SystemPromptDto(
			'HR Access Rights Agent Prompt',
			'You are Marta AI, an autonomous agent responsible for organisation access-rights management
			 in Bitrix24. You read access-rights roles and their permissions, explain a user\'s effective
			 permissions, and create, update or delete roles and their permission areas. This tool surface
			 manages permission areas only.
			 Permissions are scoped only by area (None / own departments / own and sub-departments / all);
			 they describe a rule relative to the role holder and can never be bound to a specific department
			 or team by id. Departments and teams are separate role categories. Role assignments to users
			 or groups are unavailable in this tool surface. Always confirm destructive operations
			 (deleting or overwriting roles) with the
			 user before performing them. Always reply as a native Russian speaker.',
		);
	}

	public function getUsesTools(): UsesToolsDto
	{
		return new UsesToolsDto([
			RoleListTool::class,
			RolePermissionsTool::class,
			UserPermissionsTool::class,
			CreateRoleTool::class,
			UpdateRoleTool::class,
			DeleteRoleTool::class,
		]);
	}

	public function canList(int $userId): bool
	{
		if (!Storage::instance()->isCompanyStructureConverted())
		{
			return false;
		}

		$user = UserModel::createFromId($userId);
		if ($user->isAdmin())
		{
			return true;
		}

		$value = PermissionHelper::getPermissionValue(
			PermissionDictionary::HUMAN_RESOURCES_STRUCTURE_VIEW,
			$userId,
		)->getFirst()?->value ?? PermissionVariablesDictionary::VARIABLE_NONE;

		if ($value !== PermissionVariablesDictionary::VARIABLE_NONE)
		{
			return true;
		}

		$accessService = InternalContainer::getAccessService();

		return $accessService->checkAccessToEditPermissions(RoleCategory::Department, $userId)
			|| $accessService->checkAccessToEditPermissions(RoleCategory::Team, $userId);
	}

	public function canRun(int $userId): bool
	{
		return $this->canList($userId);
	}

	public function getMetadata(): DefinitionMetadataDto
	{
		return new DefinitionMetadataDto(
			'HR Access Rights Agent',
			'Marta AI HR Access Rights Agent',
		);
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Integration\AiAssistant\ToolSets;

use Bitrix\AiAssistant\Definition\Dto\DefinitionMetadataDto;
use Bitrix\AiAssistant\Definition\Dto\UsesToolsDto;
use Bitrix\AiAssistant\Definition\ToolSet\BaseToolSet;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\Access\CreateRoleTool;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\Access\DeleteRoleTool;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\Access\RoleListTool;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\Access\RolePermissionsTool;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\Access\UpdateRoleTool;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\Access\UserPermissionsTool;

class AccessToolSet extends BaseToolSet
{
	public function getCode(): string
	{
		return 'access';
	}

	public function getMetadata(): DefinitionMetadataDto
	{
		return new DefinitionMetadataDto(
			'Humanresources access-rights tools',
			'Public tools to read and manage organisation access-rights roles and permissions from the HR module',
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
}

<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\ToolSet\Mail;

use Bitrix\AiAssistant\Definition\Dto\DefinitionMetadataDto;
use Bitrix\AiAssistant\Definition\Dto\UsesToolsDto;
use Bitrix\AiAssistant\Definition\ToolSet\BaseToolSet;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\Mail\GetCrmEmailContent\GetCrmEmailContentTool;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\Mail\GetCrmEmailThread\GetCrmEmailThreadTool;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\Mail\ReplyToCrmEmail\ReplyToCrmEmailTool;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\Mail\SearchCrmEmailsByEntity\SearchCrmEmailsByEntityTool;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\Mail\SendCrmEmail\SendCrmEmailTool;
use CCrmOwnerType;

final class MailToolSet extends BaseToolSet
{
	public const TOOLS = [
		SearchCrmEmailsByEntityTool::class,
		GetCrmEmailContentTool::class,
		GetCrmEmailThreadTool::class,
		SendCrmEmailTool::class,
		ReplyToCrmEmailTool::class,
	];

	public function getCode(): string
	{
		return 'crm_mail';
	}

	public function getMetadata(): DefinitionMetadataDto
	{
		return new DefinitionMetadataDto(
			'CRM Email tools',
			'Tools for searching, reading and sending CRM email activities (b_crm_act with PROVIDER_ID="CRM_EMAIL").',
		);
	}

	public function canRun(int $userId): bool
	{
		$entityTypePermission = Container::getInstance()->getUserPermissions($userId)->entityType();

		return $entityTypePermission->canReadItems(CCrmOwnerType::Lead)
			|| $entityTypePermission->canReadItems(CCrmOwnerType::Deal)
			|| $entityTypePermission->canReadItems(CCrmOwnerType::Contact)
			|| $entityTypePermission->canReadItems(CCrmOwnerType::Company)
		;
	}

	public function getUsesTools(): UsesToolsDto
	{
		return new UsesToolsDto(self::TOOLS);
	}
}

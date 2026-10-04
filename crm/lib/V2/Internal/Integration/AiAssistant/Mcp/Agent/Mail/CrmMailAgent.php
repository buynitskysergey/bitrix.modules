<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Agent\Mail;

use Bitrix\AiAssistant\Definition\Agent\BaseAgent;
use Bitrix\AiAssistant\Definition\Dto\DefinitionMetadataDto;
use Bitrix\AiAssistant\Definition\Dto\SystemPromptDto;
use Bitrix\AiAssistant\Definition\Dto\UsesToolsDto;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\ToolSet\Mail\MailToolSet;
use CCrmOwnerType;

final class CrmMailAgent extends BaseAgent
{
	public function getCode(): string
	{
		return 'crm_mail';
	}

	public function getSystemPrompt(): SystemPromptDto
	{
		return new SystemPromptDto(
			'CRM Mail Agent Prompt',
			'You are Marta AI, an autonomous CRM email assistant in Bitrix24.
			 Your role is to help users search, read, and send emails that are
			 attached to CRM entities (Deal, Lead, Contact, Company) and stored
			 as CRM email activities.
			 When the user references a CRM entity, prefer CRM email tools over
			 generic mailbox tools — only CRM email tools keep the timeline,
			 bindings and CRM triggers consistent.
			 Always reply as a native Russian speaker.'
		);
	}

	public function getUsesTools(): UsesToolsDto
	{
		return new UsesToolsDto(MailToolSet::TOOLS);
	}

	public function canList(int $userId): bool
	{
		return true;
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

	public function getMetadata(): DefinitionMetadataDto
	{
		return new DefinitionMetadataDto(
			'CRM Mail Agent',
			'Marta AI CRM Email Agent — searches and sends emails bound to CRM entities.',
		);
	}
}

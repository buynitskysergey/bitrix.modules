<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\Mail\GetCrmEmailContent;

use Bitrix\Crm\Activity\Email\Read\EmailActivity;
use Bitrix\Crm\Activity\Email\Read\ContentProvider;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\IntegerProperty;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\ToolDefinition;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Result\ToolResult;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractTool;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractToolDto;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\Mail\Formatter;

final class GetCrmEmailContentTool extends AbstractTool
{
	protected function getDefinition(): ToolDefinition
	{
		return (new ToolDefinition(
			name: 'get_crm_email_content',
			description:
				'Returns the content of a CRM email activity by its ID — '
				. 'subject, date, direction, participants, sanitized plain-text body and CRM '
				. 'bindings. Body is capped at ' . Formatter::MAX_BODY_LENGTH . ' chars; '
				. 'when truncated, "truncated" flag is set. Use after '
				. 'search_crm_emails_by_entity to read a specific email.',
		))
			->setProperties([
				(new IntegerProperty(
					id: 'activityId',
					description: 'CRM email activity identifier (b_crm_act.ID with PROVIDER_ID="CRM_EMAIL").',
				))
					->setIsRequired(true)
					->setIsNullable(false)
				,
			])
		;
	}

	/**
	 * @param GetCrmEmailContentToolDto $args
	 */
	protected function internalExecute(AbstractToolDto $args): ToolResult
	{
		$userId = $args->getUserId();
		$activityId = (int)$args->activityId;

		$result = (new ContentProvider())->get($activityId, $userId);
		if (!$result->isSuccess())
		{
			return ToolResult::fail(implode("\n", $result->getErrorMessages()));
		}

		$data = $result->getData();
		$activity = $data['activity'] ?? null;
		if (!$activity instanceof EmailActivity)
		{
			return ToolResult::fail('CRM email activity not found.');
		}

		return ToolResult::success(...(new Formatter())->formatContent($activity, $data['bindings'] ?? []));
	}

	protected function getArgsDtoClass(): string
	{
		return GetCrmEmailContentToolDto::class;
	}

	public function canList(int $userId): bool
	{
		return true;
	}

	public function canRun(int $userId): bool
	{
		return true;
	}
}

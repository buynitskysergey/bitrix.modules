<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\Mail\GetCrmEmailThread;

use Bitrix\Crm\Activity\Email\Read\ThreadProvider;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\IntegerProperty;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\ToolDefinition;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Result\ToolResult;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractTool;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractToolDto;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\Mail\Formatter;

final class GetCrmEmailThreadTool extends AbstractTool
{
	protected function getDefinition(): ToolDefinition
	{
		return (new ToolDefinition(
			name: 'get_crm_email_thread',
			description:
				'Returns the conversation thread (chronological list) for a CRM '
				. 'email activity. Each item contains id, subject, date, direction, '
				. 'from, to, cc and bcc. Items the user has no access to are returned as '
				. '{ hidden: true }. No bodies — use get_crm_email_content '
				. 'for the full text of a specific message. Hard cap is '
				. ThreadProvider::MAX_THREAD . ' messages; "truncated" is true when more messages exist.',
		))
			->setProperties([
				(new IntegerProperty(
					id: 'activityId',
					description: 'CRM email activity identifier — any message in the thread.',
				))
					->setIsRequired(true)
					->setIsNullable(false)
				,
			])
		;
	}

	/**
	 * @param GetCrmEmailThreadToolDto $args
	 */
	protected function internalExecute(AbstractToolDto $args): ToolResult
	{
		$userId = $args->getUserId();
		$activityId = (int)$args->activityId;

		$result = (new ThreadProvider())->get($activityId, $userId);
		if (!$result->isSuccess())
		{
			return ToolResult::fail(implode("\n", $result->getErrorMessages()));
		}

		$data = $result->getData();
		$formatter = new Formatter();
		$data['messages'] = array_map(
			static fn($entry): array => $formatter->formatThreadEntry($entry),
			$data['messages'] ?? [],
		);

		return ToolResult::success(...$data);
	}

	protected function getArgsDtoClass(): string
	{
		return GetCrmEmailThreadToolDto::class;
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

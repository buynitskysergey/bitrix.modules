<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\Mail\SearchCrmEmailsByEntity;

use Bitrix\Crm\Activity\Email\Read\SearchProvider;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\IntegerProperty;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\ToolDefinition;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Result\ToolResult;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractTool;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractToolDto;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\Mail\Formatter;

final class SearchCrmEmailsByEntityTool extends AbstractTool
{
	protected function getDefinition(): ToolDefinition
	{
		return (new ToolDefinition(
			name: 'search_crm_emails_by_entity',
			description:
				'Searches email activities (CRM Email) bound to a CRM entity '
				. '(Deal, Lead, Contact or Company). Returns activities with id, '
				. 'subject, from, to, date, sorted by date desc. '
				. 'Also returns pagination metadata: totalCount, returnedCount, '
				. 'remainingCount, hasMore, nextOffset, limit, and offset. '
				. 'If hasMore is true, explicitly tell the user that only the current '
				. 'page is shown and remainingCount more CRM emails match; use nextOffset '
				. 'to load the next page. '
				. 'Use this when the user asks for emails on a specific deal/lead/contact/company.',
		))
			->setProperties([
				(new IntegerProperty(
					id: 'entityTypeId',
					description:
						'CRM entity type ID. Use CCrmOwnerType constants: '
						. '1 — Lead, 2 — Deal, 3 — Contact, 4 — Company.',
				))
					->setIsRequired(true)
					->setIsNullable(false)
				,
				(new IntegerProperty(
					id: 'entityId',
					description: 'CRM entity identifier.',
				))
					->setIsRequired(true)
					->setIsNullable(false)
				,
				(new IntegerProperty(
					id: 'direction',
					description: 'Filter by direction: 1 — incoming, 2 — outgoing. Omit for both.',
				)),
				(new IntegerProperty(
					id: 'limit',
					description: 'Max number of activities to return. Default '
						. SearchProvider::DEFAULT_LIMIT . ', max ' . SearchProvider::MAX_LIMIT . '.',
				)),
				(new IntegerProperty(
					id: 'offset',
					description: 'Offset for pagination. Default 0.',
				)),
			])
		;
	}

	/**
	 * @param SearchCrmEmailsByEntityToolDto $args
	 */
	protected function internalExecute(AbstractToolDto $args): ToolResult
	{
		$userId = $args->getUserId();
		$entityTypeId = (int)$args->entityTypeId;
		$entityId = (int)$args->entityId;
		$direction = $args->direction;

		$result = (new SearchProvider())->search(
			userId: $userId,
			entityTypeId: $entityTypeId,
			entityId: $entityId,
			direction: $direction,
			limit: $args->limit,
			offset: $args->offset,
		);
		if (!$result->isSuccess())
		{
			return ToolResult::fail(implode("\n", $result->getErrorMessages()));
		}

		$data = $result->getData();
		$formatter = new Formatter();
		$data['activities'] = array_map(
			static fn($activity): array => $formatter->formatSummary($activity),
			$data['activities'] ?? [],
		);

		return ToolResult::success(...$data);
	}

	protected function getArgsDtoClass(): string
	{
		return SearchCrmEmailsByEntityToolDto::class;
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

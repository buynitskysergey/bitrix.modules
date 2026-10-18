<?php

declare(strict_types=1);

namespace Bitrix\Mail\Public\Service\Message\Conversation;

use Bitrix\Mail\Public\Service\Message\Conversation\Dto\ConversationSummary;

final class ConversationFolderActionPolicy
{
	/**
	 * @param array{isDraft: bool, isOutcome: bool} $folder
	 * @return string[]
	 */
	public function availableActions(array $folder): array
	{
		if ($folder['isDraft'])
		{
			return ConversationSummary::DRAFT_ACTIONS;
		}
		if ($folder['isOutcome'])
		{
			return ConversationSummary::OUTCOME_FOLDER_ACTIONS;
		}

		return ConversationSummary::FOLDER_ACTIONS;
	}

	/** @return string[] */
	public function allActions(): array
	{
		return array_values(array_unique(array_merge(
			ConversationSummary::FOLDER_ACTIONS,
			ConversationSummary::OUTCOME_FOLDER_ACTIONS,
			ConversationSummary::DRAFT_ACTIONS,
		)));
	}
}

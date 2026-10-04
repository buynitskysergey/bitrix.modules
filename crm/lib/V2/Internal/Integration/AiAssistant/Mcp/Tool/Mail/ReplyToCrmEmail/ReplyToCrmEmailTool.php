<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\Mail\ReplyToCrmEmail;

use Bitrix\Crm\Activity\Email\Send\ReplyRequest;
use Bitrix\Crm\Activity\Email\Send\ReplyService;
use Bitrix\Crm\Integration\Mail\RecipientLimitProvider;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\ArrayProperty;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\IntegerProperty;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\StringProperty;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\ToolDefinition;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Result\ToolResult;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractToolDto;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\Mail\Base\AbstractCrmEmailSenderTool;

final class ReplyToCrmEmailTool extends AbstractCrmEmailSenderTool
{
	protected function getDefinition(): ToolDefinition
	{
		$recipientsLimit = RecipientLimitProvider::getTotal();

		return (new ToolDefinition(
			name: 'reply_to_crm_email',
			description:
				'Replies to a CRM email activity. Creates a child activity with PARENT_ID set to '
				. 'the original, inherits bindings and primary recipients from the parent (sender '
				. 'for incoming, recipients for outgoing) and dispatches the email synchronously '
				. 'through Bitrix Mail with proper Cc/Bcc headers, Reply-To and a tracking URN. '
				. 'Optional cc/bcc from the agent are appended to inherited recipients. '
				. "The total number of unique recipients across inherited to plus cc/bcc is limited to {$recipientsLimit}. "
				. 'Use this when the user asks to reply to a specific CRM email already on the timeline.',
		))
			->setProperties([
				(new IntegerProperty(
					id: 'activityId',
					description: 'ID of the parent CRM email activity (b_crm_act, PROVIDER_ID="CRM_EMAIL").',
				))
					->setIsRequired(true)
					->setIsNullable(false)
				,
				(new StringProperty(
					id: 'body',
					description: 'Reply body in HTML. CRM signature is appended automatically.',
				))
					->setIsRequired(true)
					->setIsNullable(false)
				,
				(new ArrayProperty(
					id: 'cc',
					description:
						'Optional additional Cc recipients on top of the inherited "to". '
						. "The total number of unique recipients across inherited to plus cc/bcc is limited to {$recipientsLimit}.",
				))
					->setArrayItemProperty(new StringProperty(id: 'email', description: 'Email address.'))
				,
				(new ArrayProperty(
					id: 'bcc',
					description:
						'Optional additional Bcc recipients. The total number of unique recipients '
						. "across inherited to plus cc/bcc is limited to {$recipientsLimit}.",
				))
					->setArrayItemProperty(new StringProperty(id: 'email', description: 'Email address.'))
				,
				(new StringProperty(
					id: 'from',
					description:
						'Optional sender address. Must be one of the current user available senders. '
						. 'If omitted, the default CRM email is used when available; otherwise the first '
						. 'available sender is used.',
				)),
			])
		;
	}

	/**
	 * @param ReplyToCrmEmailToolDto $args
	 */
	protected function internalExecute(AbstractToolDto $args): ToolResult
	{
		$userId = $args->getUserId();
		$parentId = (int)$args->activityId;
		$body = (string)$args->body;
		$rawFrom = $args->from !== null ? trim($args->from) : null;

		$cc = $this->asRecipientList($args->cc ?? []);
		$bcc = $this->asRecipientList($args->bcc ?? []);

		$result = (new ReplyService())->reply(new ReplyRequest(
			userId: $userId,
			parentActivityId: $parentId,
			body: $body,
			cc: $cc,
			bcc: $bcc,
			rawFrom: $rawFrom,
		));
		if (!$result->isSuccess())
		{
			return ToolResult::fail((new ReplyToCrmEmailErrorMapper())->map($result));
		}

		return ToolResult::success(...$result->getData());
	}

	protected function getArgsDtoClass(): string
	{
		return ReplyToCrmEmailToolDto::class;
	}
}

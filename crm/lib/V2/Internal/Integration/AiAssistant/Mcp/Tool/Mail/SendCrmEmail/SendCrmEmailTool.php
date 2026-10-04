<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\Mail\SendCrmEmail;

use Bitrix\Crm\Activity\Email\Send\Request;
use Bitrix\Crm\Activity\Email\Send\Service;
use Bitrix\Crm\Integration\Mail\RecipientLimitProvider;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\ArrayProperty;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\IntegerProperty;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\StringProperty;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\ToolDefinition;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Result\ToolResult;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractToolDto;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\Mail\Base\AbstractCrmEmailSenderTool;

final class SendCrmEmailTool extends AbstractCrmEmailSenderTool
{
	protected function getDefinition(): ToolDefinition
	{
		$recipientsLimit = RecipientLimitProvider::getTotal();

		return (new ToolDefinition(
			name: 'send_crm_email',
			description:
				'Sends an outgoing email bound to a CRM entity (Deal, Lead, Contact or Company). '
				. 'Creates a CRM email activity (b_crm_act, PROVIDER_ID="CRM_EMAIL") visible on the '
				. 'timeline and dispatches the message synchronously through Bitrix Mail with proper '
				. 'Cc/Bcc headers, Reply-To and a tracking URN so that incoming replies link back to '
				. 'this activity. The total number of unique recipients across to/cc/bcc is limited '
				. "to {$recipientsLimit}. Use this when the user asks to send an email related to a specific "
				. 'CRM record — do not use generic mailbox tools, otherwise the timeline will be inconsistent.',
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
				(new ArrayProperty(
					id: 'to',
					description:
						'Primary recipient email addresses. At least one is required. '
						. "The total number of unique recipients across to/cc/bcc is limited to {$recipientsLimit}.",
				))
					->setIsRequired(true)
					->setIsNullable(false)
					->setArrayItemProperty(new StringProperty(id: 'email', description: 'Email address.'))
				,
				(new ArrayProperty(
					id: 'cc',
					description:
						'Optional Cc recipients (visible to all addressees). '
						. "The total number of unique recipients across to/cc/bcc is limited to {$recipientsLimit}.",
				))
					->setArrayItemProperty(new StringProperty(id: 'email', description: 'Email address.'))
				,
				(new ArrayProperty(
					id: 'bcc',
					description:
						'Optional Bcc recipients (hidden from other addressees). '
						. "The total number of unique recipients across to/cc/bcc is limited to {$recipientsLimit}.",
				))
					->setArrayItemProperty(new StringProperty(id: 'email', description: 'Email address.'))
				,
				(new StringProperty(
					id: 'subject',
					description:
						'Email subject. Plain text. Optional — if empty, a subject is auto-generated from the body; '
						. 'if the body is too short, a default placeholder is used.',
				)),
				(new StringProperty(
					id: 'body',
					description: 'Email body in HTML. CRM signature is appended automatically.',
				))
					->setIsRequired(true)
					->setIsNullable(false)
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
	 * @param SendCrmEmailToolDto $args
	 */
	protected function internalExecute(AbstractToolDto $args): ToolResult
	{
		$userId = $args->getUserId();
		$entityTypeId = (int)$args->entityTypeId;
		$entityId = (int)$args->entityId;
		$body = (string)$args->body;
		$rawFrom = $args->from !== null ? trim($args->from) : null;

		$to = $this->asRecipientList($args->to ?? []);
		$cc = $this->asRecipientList($args->cc ?? []);
		$bcc = $this->asRecipientList($args->bcc ?? []);

		$result = (new Service())->send(new Request(
			userId: $userId,
			entityTypeId: $entityTypeId,
			entityId: $entityId,
			to: $to,
			cc: $cc,
			bcc: $bcc,
			subject: (string)($args->subject ?? ''),
			body: $body,
			rawFrom: $rawFrom,
		));
		if (!$result->isSuccess())
		{
			return ToolResult::fail(implode("\n", $result->getErrorMessages()));
		}

		return ToolResult::success(...$result->getData());
	}

	protected function getArgsDtoClass(): string
	{
		return SendCrmEmailToolDto::class;
	}
}

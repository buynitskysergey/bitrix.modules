<?php

declare(strict_types=1);

namespace Bitrix\Crm\Activity\Email\Send;

use Bitrix\Crm\Activity\Email\Access;
use Bitrix\Crm\Activity\Email\MetaParser;
use Bitrix\Crm\Activity\Email\Outgoing\MessageSender;
use Bitrix\Crm\Activity\Email\Outgoing\SendRequest;
use Bitrix\Crm\Activity\Provider\Email;
use Bitrix\Crm\Result;
use Bitrix\Main\Loader;
use Bitrix\Main\Result as MainResult;
use CCrmActivity;
use CCrmActivityDirection;

final class ReplyService
{
	public function __construct(
		private readonly MessageSender $sender = new MessageSender(),
		private readonly Access $access = new Access(),
	)
	{
	}

	public function reply(ReplyRequest $request): MainResult
	{
		if ($request->parentActivityId <= 0)
		{
			return Result::fail('activityId must be a positive integer.');
		}

		if (!Loader::includeModule('mail'))
		{
			return Result::fail('The "mail" module is not available.');
		}

		$parent = CCrmActivity::GetByID($request->parentActivityId, false);
		if (!is_array($parent))
		{
			return Result::fail('Parent CRM email activity not found.');
		}

		if (($parent['PROVIDER_ID'] ?? '') !== Email::getId())
		{
			return Result::fail('Parent activity is not a CRM email (PROVIDER_ID must be "CRM_EMAIL").');
		}

		$ownerTypeId = (int)($parent['OWNER_TYPE_ID'] ?? 0);
		$ownerId = (int)($parent['OWNER_ID'] ?? 0);
		if ($ownerTypeId <= 0 || $ownerId <= 0)
		{
			return Result::fail('Parent activity has no main owner.');
		}

		$bindings = CCrmActivity::GetBindings($request->parentActivityId);
		$bindings = is_array($bindings) ? $bindings : [];
		if (!$this->access->canRead($parent, $request->userId, $bindings, checkOwnerFirst: false))
		{
			return Result::fail('Access denied to the parent CRM email activity.');
		}

		$to = $this->extractReplyRecipients($parent);
		if (empty($to))
		{
			$to = $this->extractFirstCommunicationRecipient($request->parentActivityId);
		}

		if (empty($to))
		{
			return Result::fail('Cannot determine reply recipients from the parent activity.');
		}

		if (empty($bindings))
		{
			$bindings = [['OWNER_TYPE_ID' => $ownerTypeId, 'OWNER_ID' => $ownerId]];
		}

		$result = $this->sender->send(new SendRequest(
			userId: $request->userId,
			mainOwnerTypeId: $ownerTypeId,
			mainOwnerId: $ownerId,
			bindings: $bindings,
			parentActivityId: $request->parentActivityId,
			subject: $this->buildReplySubject((string)($parent['SUBJECT'] ?? '')),
			body: $request->body,
			to: $to,
			cc: $request->cc,
			bcc: $request->bcc,
			rawFrom: $request->rawFrom,
			senderId: $request->senderId,
			mailboxId: $request->mailboxId,
		));

		if ($result->isSuccess())
		{
			$result->setData(['parentActivityId' => $request->parentActivityId] + $result->getData());
		}

		return $result;
	}

	/**
	 * @return list<string>
	 */
	public function extractReplyRecipients(array $parent): array
	{
		$meta = MetaParser::fromActivityFields($parent);
		$direction = (int)($parent['DIRECTION'] ?? 0);
		$own = array_fill_keys($meta->getOwnerEmails(), true);

		if ($direction === CCrmActivityDirection::Outgoing)
		{
			return $this->stripSelf($meta->getTo(), $own);
		}

		$primary = $this->stripSelf($meta->getReplyTo() ?: $meta->getFromList(), $own);
		if (!empty($primary))
		{
			return $primary;
		}

		return $this->stripSelf($meta->getTo(), $own);
	}

	/**
	 * @param list<string> $emails
	 * @param array<string, true> $own
	 * @return list<string>
	 */
	private function stripSelf(array $emails, array $own): array
	{
		return array_values(array_filter($emails, static fn(string $email): bool => !isset($own[$email])));
	}

	public function buildReplySubject(string $original): string
	{
		$trimmed = trim($original);
		if ($trimmed === '')
		{
			return 'Re:';
		}

		if (preg_match('/^re\s*:/iu', $trimmed))
		{
			return $trimmed;
		}

		return 'Re: ' . $trimmed;
	}

	/**
	 * @return list<string>
	 */
	private function extractFirstCommunicationRecipient(int $parentId): array
	{
		$rows = CCrmActivity::GetCommunications($parentId);
		if (!is_array($rows))
		{
			return [];
		}

		foreach ($rows as $row)
		{
			if (($row['TYPE'] ?? '') !== 'EMAIL')
			{
				continue;
			}

			$value = trim((string)($row['VALUE'] ?? ''));
			if ($value !== '' && check_email($value))
			{
				return [mb_strtolower($value)];
			}
		}

		return [];
	}
}

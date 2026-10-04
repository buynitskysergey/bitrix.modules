<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\Mailbox;

use Bitrix\Mail\Helper\Enum\MailboxStatus;
use Bitrix\Mail\Helper\Mailbox\MailboxSyncManager;
use Bitrix\Mail\MailboxTable;

final class MailboxDeletionRequestService
{
	private const AGENT_INTERVAL = 60;
	private readonly \Closure $agentRegistrar;

	public function __construct(?\Closure $agentRegistrar = null)
	{
		$this->agentRegistrar = $agentRegistrar ?? static fn (string $agentName) => \CAgent::addAgent(
			$agentName,
			'mail',
			'N',
			self::AGENT_INTERVAL,
			'',
			'Y',
			'',
			100,
			false,
			false,
		);
	}

	public function request(int $mailboxId): bool
	{
		$mailbox = MailboxTable::getRow([
			'select' => ['ID', 'USER_ID', 'LID'],
			'filter' => ['=ID' => $mailboxId],
		]);
		if ($mailbox === null)
		{
			return false;
		}

		$deactivationResult = MailboxTable::update(
			$mailboxId,
			['ACTIVE' => MailboxStatus::Inactive->value],
		);
		if (!$deactivationResult->isSuccess())
		{
			return false;
		}

		$agentName = sprintf('Bitrix\Mail\Helper::deleteMailboxAgent(%u);', $mailboxId);
		$agentId = ($this->agentRegistrar)($agentName);
		if ($agentId === false)
		{
			return false;
		}

		$userId = (int)$mailbox['USER_ID'];
		if ($userId > 0)
		{
			\CUserCounter::clear($userId, 'mail_unseen', $mailbox['LID']);
			(new MailboxSyncManager($userId))->deleteSyncData($mailboxId);
		}

		return true;
	}
}

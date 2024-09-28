<?php

namespace Bitrix\Sign\Operation\Member\Reminder;

use Bitrix\Main;
use Bitrix\Sign\Agent\Member\SigningReminderAgent;
use Bitrix\Sign\Contract;
use Bitrix\Sign\Item\Document;
use Bitrix\Sign\Repository\MemberRepository;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Type\DateTime;
use Bitrix\Sign\Type\Member\Notification\ReminderType;

final class Start implements Contract\Operation
{
	private const INTERVAL_BETWEEN_EXECUTION = 60 * 10;
	private const NEXT_EXEC_TIME = 60 * 10;
	private readonly MemberRepository $memberRepository;

	public function __construct(
		private readonly Document $document,
		?MemberRepository $memberRepository = null,
	)
	{
		$this->memberRepository = $memberRepository ?? Container::instance()->getMemberRepository();
	}

	public function launch(): Main\Result
	{
		$hasMembersWithNoneEmptyReminderTypes = $this->memberRepository->existsByDocumentIdWithReminderTypeNotEqual(
			$this->document->id,
			ReminderType::NONE
		);
		if (!$hasMembersWithNoneEmptyReminderTypes)
		{
			return new Main\Result();
		}

		$nextExecTime = (new DateTime())->withAddSeconds(self::NEXT_EXEC_TIME);
		$agentId = \CAgent::AddAgent(
			name: SigningReminderAgent::getPlanNextRemindDateAgentName($this->document->id),
			module: 'sign',
			interval: self::INTERVAL_BETWEEN_EXECUTION,
			next_exec: $nextExecTime,
			existError: false,
		);
		if ($agentId === false)
		{
			return (new Main\Result())->addError(new Main\Error("Failed to add reminder planning agent for document: {$this->document->id}"));
		}

		$agentId = \CAgent::AddAgent(
			name: SigningReminderAgent::getNotifyAgentName($this->document->id),
			module: 'sign',
			interval: self::INTERVAL_BETWEEN_EXECUTION,
			next_exec: $nextExecTime,
			existError: false,
		);
		if ($agentId === false)
		{
			return (new Main\Result())->addError(new Main\Error("Failed to add agent for document: {$this->document->id}"));
		}

		return new Main\Result();
	}
}
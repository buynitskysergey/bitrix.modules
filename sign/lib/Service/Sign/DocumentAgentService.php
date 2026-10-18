<?php

namespace Bitrix\Sign\Service\Sign;

use Bitrix\Main\ArgumentException;
use Bitrix\Sign\Integration\CRM\Model\EventData;
use Bitrix\Sign\Item\Member;
use Bitrix\Sign\Operation\Result\ConfigureResult;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Type;

use Bitrix\Main;
use Bitrix\Sign\Type\DocumentStatus;

class DocumentAgentService
{
	private const ERROR_RETRY_DELAY = 60;
	private const UPLOAD_RETRY_LIMIT = 4;
	private const PERMANENT_UPLOAD_ERROR_CODES = [
		'FILE_EMPTY',
		'FILE_IS_PROTECTED',
		'FILE_TOO_BIG',
		'FILE_TOO_MANY_PAGES',
		'INCORRECT_FILE_UPLOAD_FORMAT',
		'MULTIPLE_FILES_ONLY_FOR_IMAGES',
		'NOT_ALLOWED_EXTENSIONS',
		'NO_FILES',
		'NO_FILE_TYPE',
		'TO_MANY_FILES',
		'UNSUPPORTED_FILE_TYPE',
		'UPLOAD_FILE_NOT_DECODED',
	];

	/**
	 * A blank has already been sent in this hit.
	 *
	 * The chain is one per group, but the number of groups is not bounded: the core runs every due
	 * agent of a hit one after another in the same worker, so several chains would add up to several
	 * sendings in one request. The flag keeps the cost of a hit at one sending for the whole portal
	 * no matter how many batches are waiting - the rest of the chains stay alive and take the next
	 * hits.
	 */
	private static bool $blankSentInThisHit = false;

	/**
	 * Add agent for start signing
	 * @param string $uid
	 *
	 * @return void
	 */
	public function addConfigureAndStartAgent(string $uid): void
	{
		$agentName = $this->getConfigureAndStartAgentName($uid);
		if (!$this->agentExists($agentName))
		{
			$this->addAgent($agentName);
		}
	}

	/**
	 * Add agent for start signing
	 * @param string $uid
	 *
	 * @return void
	 */
	public function removeConfigureAndStartAgent(string $uid, int $retryCount = 0): void
	{
		$agentName = $this->getConfigureAndStartAgentName($uid, $retryCount);
		if ($this->agentExists($agentName))
		{
			$this->removeAgent($agentName);
		}
	}

	/**
	 * Configure and start signing
	 * @param string $uid
	 *
	 * @return string
	 */
	public static function configureAndStart(string $uid, $retryCount = 0): string
	{
		$agentService = Container::instance()->getDocumentAgentService();
		$result = Container::instance()->getDocumentService()->configureAndStart($uid);
		if (!$result->isSuccess())
		{
			if ($retryCount >= 4)
			{
				self::handleSigningConfigureErrors($uid, $result->getErrorCollection());
			}
			else
			{
				$agentService->addAgent(
					agentName: $agentService->getConfigureAndStartAgentName($uid, ++$retryCount),
					nextDateExec: \ConvertTimeStamp(time()+\CTimeZone::GetOffset()+self::ERROR_RETRY_DELAY, "FULL")
				);
			}

			return '';
		}

		if ($result instanceof ConfigureResult && !$result->completed)
		{
			return $agentService->getConfigureAndStartAgentName($uid, $retryCount);
		}

		return '';
	}

	/**
	 * Starts the chain that sends the blanks of the group to the signing service in the background.
	 *
	 * The call encodes the whole file and waits for the service, so inside a request it holds a
	 * php-fpm worker for seconds - and a batch of documents holds one for minutes. One agent for the
	 * whole group, not one per document, is what keeps a single hit cheap: the chain sends one blank
	 * and hands the rest to the next hit, so neither a large batch nor a slow service turns an
	 * ordinary page request into a minutes-long one.
	 */
	public function addUploadGroupBlanksAgent(int $groupId): void
	{
		if ($groupId < 1)
		{
			return;
		}

		// A retry of the chain carries a counter in its name, so an exact-name check would put a
		// second chain next to it and double the cost of a hit.
		if (!$this->hasUploadGroupBlanksChain($groupId))
		{
			$this->addAgent($this->getUploadGroupBlanksAgentName($groupId));
		}
	}

	/**
	 * Tells whether the blanks of the group are still on their way to the signing service.
	 *
	 * A chain that gave up removes itself, so the absence of one means nobody will send what is
	 * left: the sending has to report that instead of waiting for it.
	 */
	public function hasUploadGroupBlanksChain(int $groupId): bool
	{
		// `NAME` of the agent filter is a LIKE - `=NAME` is the exact one - so a single pattern
		// covers the chain together with its retries: they carry the counter in the name. The
		// pattern starts with the whole name of the call, not with a wildcard, so the index of
		// `b_agent` still works: only the arguments after the group are unknown here.
		return (bool)(new \CAgent())->getList(
			['ID' => 'DESC'],
			[
				'MODULE_ID' => 'sign',
				'ACTIVE' => 'Y',
				'NAME' => "\\" . __CLASS__ . "::uploadGroupBlanks($groupId,%",
			],
		)->fetch();
	}

	/**
	 * @param int $afterId Document the chain has given up on: the queue continues behind it.
	 */
	public function getUploadGroupBlanksAgentName(int $groupId, int $retryCount = 0, int $afterId = 0): string
	{
		return "\\" . __CLASS__ . "::uploadGroupBlanks($groupId, $retryCount, $afterId);";
	}

	/**
	 * Sends the blank of one document of the group and keeps the chain going while any is left.
	 */
	public static function uploadGroupBlanks(int $groupId, int $retryCount = 0, int $afterId = 0): string
	{
		$container = Container::instance();
		$agentService = $container->getDocumentAgentService();
		if (self::$blankSentInThisHit)
		{
			return $agentService->getUploadGroupBlanksAgentName($groupId, $retryCount, $afterId);
		}

		$document = $container->getDocumentRepository()->getFirstByGroupIdAndStatus(
			$groupId,
			DocumentStatus::NEW,
			$afterId,
		);
		if ($document === null)
		{
			return '';
		}

		if ($document->uid === null)
		{
			// The queue is taken in a fixed order, so a document that cannot be sent at all would
			// hold the chain back on every hit: it stops with a reason instead.
			$container->getLogger('Agent')->error(
				'SIGN: blank upload of group {groupId} skipped document {id} without uid',
				[
					'groupId' => $groupId,
					'id' => $document->id,
				],
			);

			return $agentService->getUploadGroupBlanksAgentName($groupId, 0, (int)$document->id);
		}

		self::$blankSentInThisHit = true;
		try
		{
			$result = $container->getDocumentService()->upload($document->uid);
		}
		catch (\Throwable $exception)
		{
			// Agents of a hit run in one loop, so an exception thrown here would take the chain and
			// the agents behind it down with it. The document is retried instead.
			$result = (new Main\Result())->addError(new Main\Error($exception->getMessage()));
		}

		if ($result->isSuccess())
		{
			// The chain moves on only because a sent blank leaves the `new` status behind. Should
			// that ever stop being true, the same document would be sent on every hit for good.
			$next = $container->getDocumentRepository()->getFirstByGroupIdAndStatus(
				$groupId,
				DocumentStatus::NEW,
				$afterId,
			);
			if ($next?->id === $document->id)
			{
				$container->getLogger('Agent')->error(
					'SIGN: blank upload of group {groupId} left document {uid} waiting, chain stopped',
					[
						'groupId' => $groupId,
						'uid' => $document->uid,
					],
				);

				return '';
			}

			// A success clears the failure streak, and the next document waits for the next hit.
			return $agentService->getUploadGroupBlanksAgentName($groupId, 0, $afterId);
		}

		$isPermanentFailure = false;
		foreach ($result->getErrors() as $error)
		{
			if (in_array($error->getCode(), self::PERMANENT_UPLOAD_ERROR_CODES, true))
			{
				$isPermanentFailure = true;
				break;
			}
		}

		if (!$isPermanentFailure && $retryCount < self::UPLOAD_RETRY_LIMIT)
		{
			$agentService->addAgent(
				agentName: $agentService->getUploadGroupBlanksAgentName($groupId, ++$retryCount, $afterId),
				nextDateExec: \ConvertTimeStamp(time() + \CTimeZone::GetOffset() + self::ERROR_RETRY_DELAY, 'FULL'),
			);

			return '';
		}

		// Retries on this document are over. The queue is taken in a fixed order, so waiting for it
		// any longer would hold back every document behind it - a single file the service refuses
		// for good would leave the whole batch unsent.
		$container->getLogger('Agent')->error(
			'SIGN: blank upload of group {groupId} rejected document {uid} after {retryCount} retries: {errors}',
			[
				'groupId' => $groupId,
				'uid' => $document->uid,
				'retryCount' => $retryCount,
				'errors' => implode('; ', $result->getErrorMessages()),
			],
		);

		$documentRollbackResult = $container->getDocumentService()->rollbackDocument((int)$document->id);
		$blankRollbackResult = new Main\Result();
		if ($document->blankId !== null)
		{
			$blankRollbackResult = $container->getSignBlankService()->rollbackById($document->blankId);
		}

		if (!$documentRollbackResult->isSuccess())
		{
			$container->getLogger('Agent')->error(
				'SIGN: failed to remove rejected document {uid} from group {groupId}: {errors}',
				[
					'groupId' => $groupId,
					'uid' => $document->uid,
					'errors' => implode('; ', $documentRollbackResult->getErrorMessages()),
				],
			);
		}
		if (!$blankRollbackResult->isSuccess())
		{
			$container->getLogger('Agent')->error(
				'SIGN: failed to remove blank {blankId} of rejected document {uid}: {errors}',
				[
					'blankId' => $document->blankId,
					'uid' => $document->uid,
					'errors' => implode('; ', $blankRollbackResult->getErrorMessages()),
				],
			);
		}

		return $agentService->getUploadGroupBlanksAgentName($groupId, 0, (int)$document->id);
	}

	private static function handleSigningConfigureErrors(string $documentUid, Main\ErrorCollection $errors): void
	{
		$documentRepository = Container::instance()->getDocumentRepository();
		$memberRepository = Container::instance()->getMemberRepository();
		$documentItem = $documentRepository->getByUid($documentUid);

		if (!$documentItem)
		{
			return;
		}
		/** @var Main\Error $error */
		foreach ($errors as $error)
		{

			if ($error->getCode() === 'SIGN_DOCUMENT_INCORRECT_STATUS')
			{
				//This is normal case for automated b2b signing, should not go to timeline
				continue;
			}


			$memberItem = null;
			$phone = null;
			if (in_array($error->getCode(), ['MEMBER_PHONE_UNSUPPORTED_COUNTRY_CODE', 'MEMBER_INVALID_PHONE'], true))
			{
				$customData = $error->getCustomData();
				$phone = $customData['phone'] ?? null;

				if ($phone)
				{
					$members = $memberRepository->listByDocumentId($documentItem->id);
					$memberItem = $members->findFirst(static function (Member $member) use ($phone) {
						return
							$member->channelType === Type\Member\ChannelType::PHONE
							&&
							(
								$phone === $member->channelValue
								|| $phone === Main\PhoneNumber\Parser::getInstance()
									->parse($member->channelValue)
									->format(Main\PhoneNumber\Format::E164)
							)
							;
					});
				}
			}

			if (!$memberItem)
			{
				$memberItem = $memberRepository->getAssigneeByDocumentId($documentItem->id);
			}

			$eventData = (new EventData())
				->setEventType(EventData::TYPE_ON_CONFIGURATION_ERROR)
				->setDocumentItem($documentItem)
				->setMemberItem($memberItem)
				->setError($error)
			;

			try
			{
				Container::instance()->getEventHandlerService()->createTimelineEvent($eventData);
			}
			catch (ArgumentException|Main\ArgumentOutOfRangeException $e)
			{
			}

			if (Type\DocumentScenario::isB2EScenario($documentItem->scenario))
			{
				$documentItem->status = DocumentStatus::UPLOADED;
				$documentRepository->update($documentItem);
			}
		}
	}

	private function addAgent(string $agentName, int $interval = 1, string $nextDateExec = '')
	{
		$agent = new \CAgent();
			$agent->AddAgent(
				name: $agentName,
				module: "sign",
				period: "Y",
				interval: $interval,
				next_exec: $nextDateExec ?: \ConvertTimeStamp(time()+\CTimeZone::GetOffset()+$interval, "FULL"),
				sort: 300,
			);
	}

	private function removeAgent(string $agentName)
	{
		$agent = new \CAgent();
		$list = $agent->getList(
			["ID" => "DESC"],
			["MODULE_ID" => "sign", "NAME" => $agentName]
		);
		while ($row = $list->fetch())
		{
			$agent->delete($row["ID"]);
		}
	}

	private function agentExists(string $agentName)
	{
		$agent = new \CAgent();
		return (bool)$agent->getList(
			["ID" => "DESC"],
			["MODULE_ID" => "sign", "NAME" => $agentName]
		)->fetch();
	}

	public function getConfigureAndStartAgentName(string $uid, $retryCount = 0)
	{
		return "\\" . __CLASS__ . "::configureAndStart('$uid', $retryCount);";
	}
}

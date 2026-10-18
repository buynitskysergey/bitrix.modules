<?php

declare(strict_types=1);

namespace Bitrix\Mail\Public\Service\Access;

use Bitrix\Mail\Helper;
use Bitrix\Mail\Helper\AnalyticsHelper;
use Bitrix\Mail\Internals\MessageAccessTable;
use Bitrix\Mail\Internals\MessageClosureTable;
use Bitrix\Mail\MailboxTable;
use Bitrix\Main\ArgumentTypeException;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Security\Sign\BadSignatureException;
use Bitrix\Main\Security\Sign\HmacAlgorithm;
use Bitrix\Main\Security\Sign\Signer;
use Bitrix\Main\Web\Uri;

class MessageAccessService
{
	public const ACCESS_LEVEL_FULL = 'full';
	public const ACCESS_LEVEL_READ = 'read';
	public const RESULT_ACCESS_LEVEL = 'accessLevel';
	public const ERROR_ACCESS_DENIED = 'MAIL_MESSAGE_ACCESS_DENIED';

	/**
	 * Resolves the access level of a user for a message.
	 *
	 * @param int $mailboxId Mailbox the message belongs to.
	 * @param int $messageId
	 * @param int $userId User the access is resolved for, as in {@see self::canRead()}.
	 * @param string $token Personal access token of an entity link, "<token>:<signature>".
	 * @return Result
	 */
	public function resolve(int $mailboxId, int $messageId, int $userId, string $token = ''): Result
	{
		$result = new Result();

		if ($mailboxId <= 0 || $messageId <= 0 || $userId <= 0)
		{
			return $this->createDeniedResult();
		}

		if ($this->hasFullAccessToMailbox($mailboxId, $userId))
		{
			$result->setData([
				self::RESULT_ACCESS_LEVEL => self::ACCESS_LEVEL_FULL,
			]);

			return $result;
		}

		$separatorPosition = mb_strpos($token, ':');
		if ($separatorPosition === false || $separatorPosition <= 0)
		{
			return $this->createDeniedResult();
		}

		[$tokenId, $signature] = explode(':', $token, 2);
		if ($tokenId === '' || $signature === '')
		{
			return $this->createDeniedResult();
		}

		$access = $this->findTokenAccess($tokenId, $mailboxId);
		if (empty($access['SECRET']))
		{
			return $this->createDeniedResult();
		}

		$entityType = isset($access['ENTITY_TYPE']) ? (string)$access['ENTITY_TYPE'] : null;
		$entityId = (int)($access['ENTITY_ID'] ?? 0);
		if (!$this->canUseEntityToken($entityType, $entityId, $userId))
		{
			return $this->createDeniedResult();
		}

		$signer = new Signer(new HmacAlgorithm('md5'));
		try
		{
			$isValid = $signer->validate(
				(string)$access['SECRET'],
				$signature,
				Helper\Message::getSaltByEntityType($entityType ?? '', $entityId, $userId),
			);
		}
		catch (BadSignatureException)
		{
			$isValid = false;
		}

		if (!$isValid)
		{
			return $this->createDeniedResult();
		}

		$tokenMessageId = (int)($access['MESSAGE_ID'] ?? 0);
		if ($messageId !== $tokenMessageId && !$this->isMessageInTokenChain($messageId, $tokenMessageId))
		{
			return $this->createDeniedResult();
		}

		$result->setData([
			self::RESULT_ACCESS_LEVEL => self::ACCESS_LEVEL_READ,
		]);

		return $result;
	}

	/**
	 * Full access means the user reaches the mailbox of the message: getUserMailboxes() unites owned
	 * and shared mailboxes, the same rule \Bitrix\Mail\MessageAccess::isOwner() stands on.
	 *
	 * The answer is a yes or no about one id, so the id-only set is asked for: it is keyed exactly like
	 * the full one and is the only form the between-request cache holds, while the full rows would be
	 * re-selected from the database on every request that opens a letter.
	 */
	protected function hasFullAccessToMailbox(int $mailboxId, int $userId): bool
	{
		return array_key_exists($mailboxId, MailboxTable::getUserMailboxes($userId, true));
	}

	protected function findTokenAccess(string $token, int $mailboxId): ?array
	{
		$access = MessageAccessTable::getList([
			'select' => ['SECRET', 'MESSAGE_ID', 'ENTITY_TYPE', 'ENTITY_ID'],
			'filter' => [
				'=TOKEN' => $token,
				'=MAILBOX_ID' => $mailboxId,
			],
			'limit' => 1,
		])->fetch();

		return $access ?: null;
	}

	protected function canUseEntityToken(?string $entityType, int $entityId, int $userId): bool
	{
		switch ($entityType)
		{
			case MessageAccessTable::ENTITY_TYPE_IM_CHAT:
			case MessageAccessTable::ENTITY_TYPE_CHAT_MESSAGE:
				return Helper\MessageAccess::checkAccessForChat($entityId, $userId);
			case MessageAccessTable::ENTITY_TYPE_CALENDAR_EVENT:
				return Helper\MessageAccess::checkAccessForCalendarEvent($entityId, $userId);
			default:
				return true;
		}
	}

	protected function isMessageInTokenChain(int $messageId, int $tokenMessageId): bool
	{
		return (bool)MessageClosureTable::getList([
			'select' => ['PARENT_ID'],
			'filter' => [
				'=MESSAGE_ID' => $messageId,
				'=PARENT_ID' => $tokenMessageId,
			],
			'limit' => 1,
		])->fetch();
	}

	private function createDeniedResult(): Result
	{
		return (new Result())->addError(
			new Error('Access to the mail message was denied.', self::ERROR_ACCESS_DENIED),
		);
	}

	/**
	 * @param int $mailboxId
	 * @param int $messageId
	 * @param int $mailUserFieldId
	 * @param int $entityId
	 * @param int $userId
	 * @param string|null $entityType Restricts the lookup to bindings of this type. Null accepts any type,
	 *                                so a binding of another entity whose id equals $entityId also matches.
	 *
	 * @return array [bool hasAccess, array|null accessData]
	 */
	public function canRead(
		int $mailboxId,
		int $messageId,
		int $mailUserFieldId,
		int $entityId,
		int $userId,
		?string $entityType = null,
	): array
	{
		$filter = [
			'=MAILBOX_ID' => $mailboxId,
			'=MESSAGE_ID' => $messageId,
			'=ENTITY_UF_ID' => $mailUserFieldId,
			'=ENTITY_ID' => $entityId,
		];

		if ($entityType !== null)
		{
			$filter['=ENTITY_TYPE'] = $entityType;
		}

		$access = MessageAccessTable::getList(['filter' => $filter, 'limit' => 1])->fetch();

		if (!empty($access))
		{
			return [true, $access];
		}

		if (MailboxTable::getUserMailbox($mailboxId, $userId))
		{
			return [true, null];
		}

		return [false, null];
	}

	/**
	 * @throws ArgumentTypeException
	 */
	public function getLinkWithToken(string $link, array $access, int $userId): string
	{
		$signer = new Signer(new HmacAlgorithm('md5'));

		$token = sprintf(
			'%s:%s',
			$access['TOKEN'],
			$signer->getSignature($access['SECRET'], sprintf('user%u', $userId)),
		);

		$link = AnalyticsHelper::addSourceAnalyticsToMessage($link, $access['ENTITY_TYPE'] ?? '');
		$uri = new Uri($link);
		$uri->addParams(['mail_uf_message_token' => $token]);

		return $uri->getUri();
	}
}

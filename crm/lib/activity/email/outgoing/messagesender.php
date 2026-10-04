<?php

declare(strict_types=1);

namespace Bitrix\Crm\Activity\Email\Outgoing;

use Bitrix\Crm\Integration\Mail\MessageSender as CrmMailMessageSender;
use Bitrix\Crm\Integration\Mail\RecipientLimitProvider;
use Bitrix\Crm\Integration\StorageType;
use Bitrix\Crm\Result;
use Bitrix\Crm\Service\Container;
use Bitrix\Mail\Helper;
use Bitrix\Main\Loader;
use Bitrix\Main\Mail\Address;
use Bitrix\Main\Mail\Sender\UserSenderDataProvider;
use Bitrix\Main\Type\DateTime;
use CCrmActivity;
use CCrmActivityDirection;
use CCrmActivityNotifyType;
use CCrmActivityPriority;
use CCrmActivityType;
use CCrmContentType;
use CCrmEvent;
use CCrmMailHelper;
use CCrmOwnerType;

final class MessageSender
{
	public const ERROR_SENDER_NOT_AVAILABLE = 'CRM_EMAIL_SENDER_NOT_AVAILABLE';
	public const ERROR_FROM_INVALID = 'CRM_EMAIL_FROM_INVALID';
	public const ERROR_RECIPIENT_BLACKLISTED = 'CRM_EMAIL_RECIPIENT_BLACKLISTED';

	private readonly \Closure $availableSendersProvider;

	public function __construct(?\Closure $availableSendersProvider = null)
	{
		$this->availableSendersProvider = $availableSendersProvider
			?? static fn(int $userId): array => UserSenderDataProvider::getUserAvailableSenderIdentities($userId)
		;
	}

	public function send(SendRequest $request): Result
	{
		if (!Loader::includeModule('mail'))
		{
			return $this->fail('The "mail" module is not available.');
		}

		$permissionCheck = $this->preparePermittedOwner($request);
		if (!$permissionCheck->isSuccess())
		{
			return $permissionCheck;
		}

		$recipientCheck = $this->prepareRecipients($request);
		if (!$recipientCheck->isSuccess())
		{
			return $recipientCheck;
		}
		$recipients = $recipientCheck->getData();
		$to = $recipients['to'];
		$cc = $recipients['cc'];
		$bcc = $recipients['bcc'];

		$owner = $permissionCheck->getData();
		$senderCheck = $this->resolveSender(
			$request->rawFrom,
			$request->senderId,
			$request->mailboxId,
			$request->userId,
		);
		if (!$senderCheck->isSuccess())
		{
			return $senderCheck;
		}
		$sender = $senderCheck->getData();

		$messageHtml = $this->prepareBody($request->body);
		$now = new DateTime();
		$arFields = $this->buildActivityFields($request, $owner, $sender, $recipients, $messageHtml, $now);

		$activityOptions = [
			'REGISTER_SONET_EVENT' => false,
			'CURRENT_USER' => $request->userId,
		];

		$activityId = (int)CCrmActivity::Add($arFields, false, false, $activityOptions);
		if ($activityId <= 0)
		{
			return $this->fail(CCrmActivity::GetLastErrorMessage() ?: 'Failed to create CRM email activity.');
		}

		$mailboxHelper = $sender['mailboxHelper'] ?? null;
		$injectUrn = empty($mailboxHelper);
		$urn = CCrmActivity::PrepareUrn($arFields);
		$messageId = $this->buildMessageId($urn);

		CCrmActivity::Update(
			$activityId,
			[
				'URN' => $urn,
				'SETTINGS' => [
					'IS_BATCH_EMAIL' => false,
					'MESSAGE_HEADERS' => [
						'Message-Id' => $messageId,
						'Reply-To' => $sender['replyTo'],
					],
					'EMAIL_META' => $this->buildEmailMeta($sender, $recipients),
				],
			],
			false,
			false,
			$activityOptions,
		);

		try
		{
			$transportResult = null;
			$sendResult = CrmMailMessageSender::send(
				[
					'subject' => $request->subject,
					'body' => $messageHtml,
					'to' => $to,
					'cc' => $cc,
					'bcc' => $bcc,
					'fromEmail' => $sender['email'],
					'fromEncoded' => $sender['encoded'],
					'reply' => $sender['replyTo'],
					'rawFiles' => [],
					'attachToFileIds' => [],
					'urn' => $urn,
					'injectUrn' => $injectUrn,
					'hostname' => $this->resolveHostname(),
					'messageId' => $messageId,
					'priorityCount' => count($to) + count($cc) + count($bcc),
				],
				$request->subject,
				$mailboxHelper,
				$transportResult,
				$sender['senderId'],
				$request->userId,
			);
		}
		catch (\Throwable)
		{
			$this->rollbackActivity($activityId, $request->userId);

			return $this->fail($this->resolveSendFailureReason());
		}

		if (!$sendResult)
		{
			$reason = CrmMailMessageSender::getControlledTransportError($transportResult)?->getMessage()
				?? $this->resolveSendFailureReason()
			;
			$this->rollbackActivity($activityId, $request->userId);

			return $this->fail($reason);
		}

		$this->registerActivityLiveFeed($activityId, $request->userId);

		$this->registerLegacyEmailEvent(
			$owner['bindings'],
			$request->subject,
			$sender['display'],
			$to,
			$cc,
			$bcc,
			$request->userId,
		);

		$syncedToImap = !empty($mailboxHelper);
		$warnings = [];
		if (!$syncedToImap)
		{
			$warnings[] =
				'No mailbox is configured for the chosen "from" address; the message is not '
				. 'mirrored to a Sent IMAP folder. Replies are tracked via Reply-To and the URN.'
			;
		}

		return (new Result())->setData([
			'activityId' => $activityId,
			'from' => $sender['email'],
			'to' => $to,
			'cc' => $cc,
			'bcc' => $bcc,
			'syncedToImap' => $syncedToImap,
			'warnings' => $warnings,
		]);
	}

	/**
	 * @param array{mainOwnerTypeId:int, mainOwnerId:int, bindings:list<array>} $owner
	 * @param array{email:string, display:string, replyTo:string, mailboxOwnerId:?int} $sender
	 * @param array{to:list<string>, cc:list<string>, bcc:list<string>} $recipients
	 * @return array<string, mixed>
	 */
	private function buildActivityFields(
		SendRequest $request,
		array $owner,
		array $sender,
		array $recipients,
		string $messageHtml,
		DateTime $now,
	): array
	{
		$fields = [
			'AUTHOR_ID' => (int)($sender['mailboxOwnerId'] ?? $request->userId),
			'OWNER_TYPE_ID' => (int)$owner['mainOwnerTypeId'],
			'OWNER_ID' => (int)$owner['mainOwnerId'],
			'TYPE_ID' => CCrmActivityType::Email,
			'SUBJECT' => $request->subject,
			'START_TIME' => $now,
			'END_TIME' => $now,
			'COMPLETED' => 'Y',
			'RESPONSIBLE_ID' => $request->userId,
			'EDITOR_ID' => $request->userId,
			'PRIORITY' => CCrmActivityPriority::Medium,
			'DESCRIPTION' => $messageHtml,
			'DESCRIPTION_TYPE' => CCrmContentType::Html,
			'DIRECTION' => CCrmActivityDirection::Outgoing,
			'NOTIFY_TYPE' => CCrmActivityNotifyType::None,
			'BINDINGS' => $owner['bindings'],
			'COMMUNICATIONS' => $this->buildCommunications(
				(int)$owner['mainOwnerTypeId'],
				(int)$owner['mainOwnerId'],
				$owner['bindings'],
				$recipients['to'],
				$recipients['cc'],
				$recipients['bcc'],
			),
			'STORAGE_TYPE_ID' => StorageType::getDefaultTypeID(),
			'STORAGE_ELEMENT_IDS' => [],
			// the EmailSent trigger fires inside the add, so the addresses must already be in these fields
			'SETTINGS' => [
				'EMAIL_META' => $this->buildEmailMeta($sender, $recipients),
			],
		];
		if ($request->parentActivityId !== null)
		{
			$fields['PARENT_ID'] = $request->parentActivityId;
		}

		return $fields;
	}

	/**
	 * @param array{email:string, display:string, replyTo:string} $sender
	 * @param array{to:list<string>, cc:list<string>, bcc:list<string>} $recipients
	 * @return array<string, string>
	 */
	private function buildEmailMeta(array $sender, array $recipients): array
	{
		return [
			'__email' => $sender['email'],
			'from' => $sender['display'],
			'replyTo' => $sender['replyTo'],
			'to' => implode(', ', $recipients['to']),
			'cc' => implode(', ', $recipients['cc']),
			'bcc' => implode(', ', $recipients['bcc']),
		];
	}

	/**
	 * @return Result data array{to:list<string>, cc:list<string>, bcc:list<string>}
	 */
	private function prepareRecipients(SendRequest $request): Result
	{
		$invalid = [];
		[$to, $toInvalid] = $this->normalizeRecipientList($request->to);
		[$cc, $ccInvalid] = $this->normalizeRecipientList($request->cc);
		[$bcc, $bccInvalid] = $this->normalizeRecipientList($request->bcc);

		foreach ($toInvalid as $value)
		{
			$invalid[] = 'to: ' . $value;
		}
		foreach ($ccInvalid as $value)
		{
			$invalid[] = 'cc: ' . $value;
		}
		foreach ($bccInvalid as $value)
		{
			$invalid[] = 'bcc: ' . $value;
		}

		if (!empty($invalid))
		{
			return $this->fail('Invalid recipient email(s): ' . implode(', ', $invalid));
		}

		if (empty($to))
		{
			return $this->fail('"to" must contain at least one valid email address.');
		}

		$cc = array_values(array_diff($cc, $to));
		$bcc = array_values(array_diff($bcc, $to, $cc));

		$totalRecipients = count($to) + count($cc) + count($bcc);
		$recipientsLimit = RecipientLimitProvider::getTotal();
		if ($totalRecipients > $recipientsLimit)
		{
			return $this->fail(sprintf(
				'Too many recipients: %d, the limit is %d total across to/cc/bcc.',
				$totalRecipients,
				$recipientsLimit,
			));
		}

		$licenseLimit = $this->getLicenseRecipientsLimit();
		if ($licenseLimit !== -1
			&& (count($to) > $licenseLimit || count($cc) > $licenseLimit || count($bcc) > $licenseLimit)
		)
		{
			return $this->fail(sprintf(
				'Tariff restriction: cannot send to more than %d recipient(s) per to/cc/bcc field.',
				$licenseLimit,
			));
		}

		$blacklisted = $this->findBlacklisted([...$to, ...$cc, ...$bcc]);
		if (!empty($blacklisted))
		{
			return $this->fail(
				'Some recipients are in the global mail blacklist: ' . implode(', ', $blacklisted),
				self::ERROR_RECIPIENT_BLACKLISTED,
			);
		}

		return (new Result())->setData([
			'to' => $to,
			'cc' => $cc,
			'bcc' => $bcc,
		]);
	}

	/**
	 * @return array{0:list<string>, 1:list<string>}
	 */
	private function normalizeRecipientList(array $raw): array
	{
		$result = [];
		$invalid = [];
		foreach ($raw as $value)
		{
			if (!is_string($value) || trim($value) === '')
			{
				$invalid[] = $this->formatInvalidRecipient($value);
				continue;
			}

			$address = new Address(trim($value));
			if (!$address->validate())
			{
				$invalid[] = $this->formatInvalidRecipient($value);
				continue;
			}

			$email = mb_strtolower((string)$address->getEmail());
			if ($email === '' || !check_email($email))
			{
				$invalid[] = $this->formatInvalidRecipient($value);
				continue;
			}

			$result[$email] = $email;
		}

		return [array_values($result), $invalid];
	}

	private function formatInvalidRecipient(mixed $value): string
	{
		if (is_scalar($value) || $value === null)
		{
			$formatted = trim((string)$value);

			return $formatted !== '' ? $formatted : '<empty>';
		}

		return '<' . get_debug_type($value) . '>';
	}

	private function preparePermittedOwner(SendRequest $request): Result
	{
		$mainOwner = [
			'OWNER_TYPE_ID' => $request->mainOwnerTypeId,
			'OWNER_ID' => $request->mainOwnerId,
		];
		if (!CCrmOwnerType::IsDefined($mainOwner['OWNER_TYPE_ID']) || $mainOwner['OWNER_ID'] <= 0)
		{
			return $this->fail('Invalid CRM email activity owner.');
		}

		$permissions = Container::getInstance()->getUserPermissions($request->userId)->getCrmPermissions();
		if (!CCrmActivity::CheckUpdatePermission($mainOwner['OWNER_TYPE_ID'], $mainOwner['OWNER_ID'], $permissions))
		{
			return $this->fail('Access denied to create CRM email activity on the main owner.');
		}

		$bindingsByKey = [$this->bindingKey($mainOwner) => $mainOwner];
		foreach ($this->normalizeBindings($request->bindings) as $binding)
		{
			$bindingsByKey[$this->bindingKey($binding)] = $binding;
		}

		$permittedBindings = [];
		foreach ($bindingsByKey as $key => $binding)
		{
			if (CCrmActivity::CheckUpdatePermission($binding['OWNER_TYPE_ID'], $binding['OWNER_ID'], $permissions))
			{
				$permittedBindings[$key] = $binding;
			}
		}

		return (new Result())->setData([
			'mainOwnerTypeId' => $mainOwner['OWNER_TYPE_ID'],
			'mainOwnerId' => $mainOwner['OWNER_ID'],
			'bindings' => array_values($permittedBindings),
		]);
	}

	/**
	 * @param list<array{OWNER_TYPE_ID:int|string, OWNER_ID:int|string}> $bindings
	 * @return list<array{OWNER_TYPE_ID:int, OWNER_ID:int}>
	 */
	private function normalizeBindings(array $bindings): array
	{
		$result = [];
		foreach ($bindings as $binding)
		{
			$ownerTypeId = (int)($binding['OWNER_TYPE_ID'] ?? 0);
			$ownerId = (int)($binding['OWNER_ID'] ?? 0);
			if (!CCrmOwnerType::IsDefined($ownerTypeId) || $ownerId <= 0)
			{
				continue;
			}

			$result[] = [
				'OWNER_TYPE_ID' => $ownerTypeId,
				'OWNER_ID' => $ownerId,
			];
		}

		return $result;
	}

	/**
	 * @param array{OWNER_TYPE_ID:int, OWNER_ID:int} $binding
	 */
	private function bindingKey(array $binding): string
	{
		return $binding['OWNER_TYPE_ID'] . '_' . $binding['OWNER_ID'];
	}

	/**
	 * @param string|null $rawFrom
	 * @param int|null $senderId
	 * @param int $userId
	 * @return Result data array{
	 *     email:string,
	 *     display:string,
	 *     encoded:string,
	 *     replyTo:string,
	 *     mailboxHelper:?\Bitrix\Mail\Helper\Mailbox,
	 *     mailboxOwnerId:?int,
	 *     senderId:?int
	 * }
	 * @throws \Exception
	 */
	private function resolveSender(?string $rawFrom, ?int $senderId, ?int $mailboxId, int $userId): Result
	{
		$senders = ($this->availableSendersProvider)($userId);
		if (empty($senders))
		{
			return $this->fail(
				'No available email sender is configured for this user.',
				self::ERROR_SENDER_NOT_AVAILABLE,
			);
		}

		$requestedEmail = $this->extractEmail($rawFrom);
		if ($requestedEmail === '')
		{
			return $this->fail(
				'Sender email is not available for this user.',
				self::ERROR_FROM_INVALID,
			);
		}

		$defaultCrmEmail = mb_strtolower((string)CCrmMailHelper::extractEmail(\COption::getOptionString('crm', 'mail', '')));

		$selected = null;
		$legacySenderIdMatch = null;
		foreach ($senders as $sender)
		{
			$email = mb_strtolower((string)($sender['email'] ?? ''));
			if ($email === '')
			{
				continue;
			}

			if ($mailboxId !== null && $mailboxId > 0)
			{
				if ((int)($sender['mailboxId'] ?? 0) === $mailboxId && $email === $requestedEmail)
				{
					$selected = $sender;
					break;
				}

				continue;
			}

			if ($senderId !== null && $senderId > 0)
			{
				if ((int)($sender['id'] ?? 0) === $senderId && $email === $requestedEmail)
				{
					if (empty($sender['mailboxId']))
					{
						$selected = $sender;
						break;
					}

					$legacySenderIdMatch ??= $sender;
				}

				continue;
			}

			if ($requestedEmail !== null && $email === $requestedEmail)
			{
				$selected = $sender;
				break;
			}

			if ($requestedEmail === null && $defaultCrmEmail !== '' && $email === $defaultCrmEmail)
			{
				$selected = $sender;
			}
		}
		$selected ??= $legacySenderIdMatch;

		if (($senderId !== null || $mailboxId !== null || $requestedEmail !== null) && $selected === null)
		{
			return $this->fail(
				'Sender email is not available for this user.',
				self::ERROR_SENDER_NOT_AVAILABLE,
			);
		}

		$selected ??= reset($senders);
		$email = mb_strtolower((string)($selected['email'] ?? ''));
		if ($email === '' || !check_email($email))
		{
			return $this->fail('Cannot resolve a valid "from" address.', self::ERROR_FROM_INVALID);
		}

		$display = UserSenderDataProvider::getAddressInEmailAngleFormat(
			email: $email,
			senderName: (string)($selected['name'] ?? ''),
			userId: $userId,
		) ?: $email;

		$address = new Address($display);
		if (!$address->validate())
		{
			return $this->fail('Cannot resolve a valid "from" address.', self::ERROR_FROM_INVALID);
		}

		$mailboxHelper = null;
		if (!empty($selected['mailboxId']))
		{
			$mailboxHelper = \Bitrix\Mail\Helper\Mailbox::createInstance((int)$selected['mailboxId'], false);
			if (!$mailboxHelper instanceof \Bitrix\Mail\Helper\Mailbox)
			{
				$mailboxHelper = null;
			}
		}

		$replyTo = $email;
		if (empty($mailboxHelper))
		{
			$crmEmail = (string)CCrmMailHelper::extractEmail(\COption::getOptionString('crm', 'mail', ''));
			if ($crmEmail !== '' && mb_strtolower($crmEmail) !== $email)
			{
				$replyTo = $email . ', ' . $crmEmail;
			}
		}

		return (new Result())->setData([
			'email' => $email,
			'display' => $address->get() ?: $email,
			'encoded' => $address->getEncoded() ?: $email,
			'replyTo' => $replyTo,
			'mailboxHelper' => $mailboxHelper,
			'mailboxOwnerId' => $mailboxHelper?->getMailboxOwnerId(),
			'senderId' => empty($selected['mailboxId']) && isset($selected['id']) ? (int)$selected['id'] : null,
			'mailboxId' => !empty($selected['mailboxId']) ? (int)$selected['mailboxId'] : null,
		]);
	}

	private function extractEmail(?string $raw): ?string
	{
		if ($raw === null || trim($raw) === '')
		{
			return null;
		}

		$address = new Address($raw);
		if (!$address->validate())
		{
			return '';
		}

		return mb_strtolower((string)$address->getEmail());
	}

	private function prepareBody(string $body): string
	{
		$html = Helper\Message::sanitizeHtml($body);
		CCrmActivity::AddEmailSignature($html, CCrmContentType::Html);

		return $html;
	}

	/**
	 * @param list<array{OWNER_TYPE_ID:int, OWNER_ID:int}> $bindings
	 * @param list<string> $to
	 * @param list<string> $cc
	 * @param list<string> $bcc
	 * @return list<array{TYPE:string, VALUE:string, ENTITY_TYPE_ID:int, ENTITY_ID:int}>
	 */
	private function buildCommunications(int $entityTypeId, int $entityId, array $bindings, array $to, array $cc, array $bcc): array
	{
		return (new RecipientResolver())->build($entityTypeId, $entityId, $bindings, $to, $cc, $bcc);
	}

	private function buildMessageId(string $urn): string
	{
		return sprintf('<crm.activity.%s@%s>', $urn, $this->resolveHostname());
	}

	/**
	 * @param list<array{OWNER_TYPE_ID:int, OWNER_ID:int}> $bindings
	 * @param list<string> $to
	 * @param list<string> $cc
	 * @param list<string> $bcc
	 */
	private function registerLegacyEmailEvent(
		array $bindings,
		string $subject,
		string $from,
		array $to,
		array $cc,
		array $bcc,
		int $userId,
	): void
	{
		$eventBindings = [];
		foreach ($bindings as $binding)
		{
			$entityTypeName = CCrmOwnerType::ResolveName((int)$binding['OWNER_TYPE_ID']);
			$entityId = (int)$binding['OWNER_ID'];
			if ($entityTypeName === '' || $entityId <= 0)
			{
				continue;
			}

			$eventBindings["{$entityTypeName}_{$entityId}"] = [
				'ENTITY_TYPE' => $entityTypeName,
				'ENTITY_ID' => $entityId,
			];
		}

		if (empty($eventBindings))
		{
			return;
		}

		$eventText = $this->getMessageLabel('CRM_ACTIVITY_EMAIL_SUBJECT', 'Subject') . ': ' . $subject . "\n\r";
		$eventText .= $this->getMessageLabel('CRM_ACTIVITY_EMAIL_FROM', 'From') . ': ' . $from . "\n\r";
		$eventText .= $this->getMessageLabel('CRM_ACTIVITY_EMAIL_TO', 'To') . ': ' . implode(', ', $to) . "\n\r";
		if (!empty($cc))
		{
			$eventText .= 'Cc: ' . implode(', ', $cc) . "\n\r";
		}
		if (!empty($bcc))
		{
			$eventText .= 'Bcc: ' . implode(', ', $bcc) . "\n\r";
		}

		(new CCrmEvent())->Add([
			'EVENT_TYPE' => CCrmEvent::TYPE_EMAIL,
			'ENTITY' => $eventBindings,
			'EVENT_ID' => 'MESSAGE',
			'EVENT_TEXT_1' => $eventText,
			'USER_ID' => $userId,
		]);
	}

	private function getMessageLabel(string $code, string $fallback): string
	{
		$message = \GetMessage($code);

		return is_string($message) && $message !== '' ? $message : $fallback;
	}

	private function resolveHostname(): string
	{
		if (defined('BX24_HOST_NAME') && BX24_HOST_NAME !== '')
		{
			return BX24_HOST_NAME;
		}

		if (defined('SITE_SERVER_NAME') && SITE_SERVER_NAME !== '')
		{
			return SITE_SERVER_NAME;
		}

		return \COption::getOptionString('main', 'server_name', '') ?: 'localhost';
	}

	/**
	 * @param list<string> $emails
	 * @return list<string>
	 */
	private function findBlacklisted(array $emails): array
	{
		if (empty($emails))
		{
			return [];
		}

		$result =
			\Bitrix\Main\Mail\Internal\BlacklistTable::query()
				->setSelect(['CODE'])
				->whereIn('CODE', $emails)
				->exec()
		;

		$blacklisted = [];
		while ($row = $result->fetch())
		{
			$code = $row['CODE'];
			$blacklisted[$code] = $code;
		}

		return array_values($blacklisted);
	}

	private function getLicenseRecipientsLimit(): int
	{
		if (!Loader::includeModule('mail'))
		{
			return -1;
		}

		return \Bitrix\Mail\Helper\LicenseManager::getEmailsLimitToSendMessage();
	}

	private function resolveSendFailureReason(): string
	{
		if (Loader::includeModule('bitrix24'))
		{
			if (
				method_exists(\Bitrix\Bitrix24\MailCounter::class, 'isLimited')
				&& \Bitrix\Bitrix24\MailCounter::isLimited()
			)
			{
				return 'Bitrix24 mail-sending limit reached for this portal.';
			}

			if (
				method_exists(\Bitrix\Bitrix24\MailCounter::class, 'isCustomLimited')
				&& \Bitrix\Bitrix24\MailCounter::isCustomLimited()
			)
			{
				return 'Bitrix24 custom mail-sending limit reached for this portal.';
			}
		}

		return 'Failed to send the email through the Bitrix mail system.';
	}

	private function rollbackActivity(int $activityId, int $userId): void
	{
		CCrmActivity::Delete(
			$activityId,
			false,
			false,
			[
				'CURRENT_USER' => $userId,
				'RECYCLE_BIN_FORCE_USER_ID' => $userId,
			],
		);
	}

	private function registerActivityLiveFeed(int $activityId, int $userId): void
	{
		CCrmActivity::Update(
			$activityId,
			['EDITOR_ID' => $userId],
			false,
			false,
			[
				'REGISTER_SONET_EVENT' => true,
				'CURRENT_USER' => $userId,
			],
		);
	}

	private function fail(string $message, string|int $code = 0): Result
	{
		return Result::fail($message, $code);
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\SourceGeneration;

use Bitrix\Mail\Helper\Message;
use Bitrix\Mail\Internal\Entity\SourceGeneration\Context;
use Bitrix\Mail\Internal\Service\SourceGeneration\Matching\CanonicalMessageData;
use Bitrix\Mail\Internal\Service\SourceGeneration\Matching\MatchDecision;
use Bitrix\Mail\Internal\Service\SourceGeneration\Matching\MessageMatcher;
use Bitrix\Mail\Internals\MailEntityDataTable;
use Bitrix\Mail\Internals\MailEntityOptionsTable;
use Bitrix\Mail\Internals\SourceGenerationMatchTable;
use Bitrix\Mail\MailMessageTable;
use Bitrix\Mail\MailMessageUidTable;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Type\DateTime;

/**
 * The persistence side of a migration import (DATA-01).
 *
 * One imported uid is closed by a single repeatable transaction: the physical row
 * receives its local identity, the matching result becomes terminal and the
 * representation of this generation joins the fingerprints of that logical message.
 * The canonical b_mail_message row is never rewritten, and no binding of an
 * existing message is copied, merged or removed - a merge keeps its bindings by the
 * MAILBOX_ID + MESSAGE_ID pair staying the same.
 *
 * Repeating the operation on the same uid links the same message again and adds
 * nothing else: the fingerprints of an identical representation have identical
 * hashes and the terminal result is reused instead of being decided again.
 */
final class MigrationMessageImporter
{
	private const UNSYNC_BODY_PROPERTY = 'UNSYNC_BODY';
	private const CANDIDATES_PROPERTY = 'MATCH_CANDIDATES';

	/** Diagnostics, not a merge instruction: a long list adds nothing to it */
	private const MAX_STORED_CANDIDATES = 50;

	/** A placement the user has read, whether the server already knows it or not */
	private const READ_STATES = ['Y', 'S'];

	private const READ_STATE = 'Y';
	private const UNREAD_STATE = 'N';

	public function __construct(
		private readonly MessageMatcher $matcher = new MessageMatcher(),
	)
	{
	}

	/**
	 * Fixes the outcome of one imported uid.
	 *
	 * @param string $uidId The provisional uid row of this generation, registered with MESSAGE_ID = 0.
	 * @param int $messageId The local identity of the letter: the existing one for MATCHED, the created one otherwise.
	 * @param CanonicalMessageData|null $canonical The canonical view of the imported MIME, null when it could not be built.
	 */
	public function commit(
		Context $context,
		string $uidId,
		int $messageId,
		MatchDecision $decision,
		?CanonicalMessageData $canonical = null,
		string $migratorReference = '',
	): bool
	{
		if ($messageId <= 0 || $uidId === '')
		{
			return false;
		}

		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			$this->lockPlacementAndResult($context, $uidId);

			$this->linkPlacement($context, $uidId, $messageId);
			if ($decision->isMatched())
			{
				$this->carryReadStateOfTheArchive($context, $uidId, $messageId);
			}

			$this->matcher->finalize($context, $uidId, $messageId);
			$this->storeCandidateDiagnostics($context, $uidId, $decision);

			if ($canonical !== null)
			{
				$this->matcher->registerRepresentation($context, $messageId, $canonical, $migratorReference);
			}

			if (!$this->messageStillExists($context->mailboxId, $messageId))
			{
				/*
					The letter this decision names is gone - deleted for good while this commit was
					deciding and writing. Nothing may start pointing at it: the placement would serve a
					logical record that does not exist, and after the switch the letter of the new source
					would be invisible. Everything written above goes back with the transaction.
				*/
				$connection->rollbackTransaction();

				/*
					And the stored decision goes too, so the next pass decides anew instead of reusing a
					verdict about a letter that is no longer there. Outside the rolled back transaction,
					which is why it is not one statement with the rest.
				*/
				$this->matcher->forget($context, $uidId);

				return false;
			}

			$connection->commitTransaction();
		}
		catch (\Throwable $exception)
		{
			$connection->rollbackTransaction();

			AddMessage2Log(
				sprintf(
					'Source generation import failed (mailbox %u, generation %u, uid %s): %s',
					$context->mailboxId,
					$context->generationId,
					$uidId,
					$exception->getMessage(),
				),
				'mail',
				2,
				false,
			);

			return false;
		}

		return true;
	}

	/**
	 * Moves an already imported placement to the letter a late delivery has proved it to be.
	 *
	 * The import decided this uid was a new letter because the letter it really is had not
	 * reached the mailbox yet: the main load holds no sync lock, so the active source went on
	 * delivering under it. The placement therefore starts serving the delivered letter - the one
	 * that carries the filters, the event and the bindings of a real delivery - and the letter
	 * the import created is left with one placement less for the caller to answer for.
	 *
	 * The same single transaction as {@see commit()}, and the same locks: two workers never move
	 * one placement at once, and the read state of the archive is carried over here as well,
	 * because the placement becomes a merged one exactly as a MATCHED decision would have made it.
	 */
	public function relink(Context $context, string $uidId, int $messageId, string $reason): bool
	{
		if ($messageId <= 0 || $uidId === '')
		{
			return false;
		}

		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			$this->lockPlacementAndResult($context, $uidId);

			$this->linkPlacement($context, $uidId, $messageId);
			$this->carryReadStateOfTheArchive($context, $uidId, $messageId);

			if (!$this->matcher->rebind($context, $uidId, $messageId, $reason))
			{
				$connection->rollbackTransaction();

				return false;
			}

			// The message may disappear while the transaction waits for the placement lock.
			if (!$this->messageStillExists($context->mailboxId, $messageId))
			{
				$connection->rollbackTransaction();

				return false;
			}

			$connection->commitTransaction();
		}
		catch (\Throwable $exception)
		{
			$connection->rollbackTransaction();

			AddMessage2Log(
				sprintf(
					'Source generation relink failed (mailbox %u, generation %u, uid %s): %s',
					$context->mailboxId,
					$context->generationId,
					$uidId,
					$exception->getMessage(),
				),
				'mail',
				2,
				false,
			);

			return false;
		}

		return true;
	}

	/**
	 * Fills what the matched message never received: a body still waiting for its
	 * download and the attachments a lazy save has not created yet. Values that are
	 * already there, existing attachment rows, their ids and their files are left
	 * untouched.
	 *
	 * @param array $attachments Parts of the imported MIME, as the save path receives them.
	 */
	public function completeMatchedMessage(
		Context $context,
		int $messageId,
		string $bodyHtml,
		string $bodyText,
		array $attachments = [],
	): void
	{
		if ($messageId <= 0 || !$this->isBodyPending($context->mailboxId, $messageId))
		{
			return;
		}

		$message = MailMessageTable::getRow([
			'select' => ['ID', 'BODY', 'BODY_HTML', 'ATTACHMENTS', MailMessageTable::FIELD_SANITIZE_ON_VIEW],
			'filter' => [
				'=ID' => $messageId,
				'=MAILBOX_ID' => $context->mailboxId,
			],
		]);

		if ($message === null)
		{
			return;
		}

		$storedHtml = (string)$message['BODY_HTML'];
		$html = $storedHtml === '' && $bodyHtml !== ''
			? $this->prepareHtmlForView($message, $bodyHtml)
			: $storedHtml
		;

		$html = $this->fillMissingAttachments($message, $html, $attachments);

		$fields = [];

		if ((string)$message['BODY'] === '' && trim($bodyText) !== '')
		{
			$fields['BODY'] = rtrim($bodyText);
		}

		if ($html !== $storedHtml)
		{
			$fields['BODY_HTML'] = $html;
		}

		if ($fields === [])
		{
			return;
		}

		\CMailMessage::update($messageId, $fields, $context->mailboxId);

		Message::updateMailEntityOptionsRow($context->mailboxId, $messageId);
	}

	/**
	 * Whether the letter a decision names is still there - asked at the end of the writing and by a
	 * locking read, and both halves of that matter.
	 *
	 * The permanent deletion of a letter is no transaction of its own: it removes the attachments,
	 * the bindings, the thread, the placements and only then the row of the letter, every statement
	 * committing as it goes. An answer taken before the writing therefore ages while the writing
	 * lasts - and the placements it spoke for were already gone by then.
	 *
	 * The read locks because an ordinary one inside an open transaction is answered from the
	 * snapshot of that transaction, which knows nothing of a deletion committed after it began. A
	 * locking read is answered by the current row and holds it until this transaction ends, so a
	 * deletion that has reached the letter can no longer finish behind our back.
	 *
	 * What is left open is the deletion that reaches the row after this answer and commits before
	 * this transaction does. Closing that one belongs to the deletion, which would have to take the
	 * letter before it takes anything of it - it cannot be closed from here.
	 */
	private function messageStillExists(int $mailboxId, int $messageId): bool
	{
		$connection = Application::getConnection();

		$row = $connection->query(sprintf(
			'SELECT ID FROM %s WHERE ID = %u AND MAILBOX_ID = %u FOR UPDATE',
			$connection->getSqlHelper()->quote(MailMessageTable::getTableName()),
			$messageId,
			$mailboxId,
		))->fetch();

		return $row !== false && $row !== null;
	}

	/**
	 * The provisional row and the result of this uid are held for the whole
	 * operation, so two workers never close the same placement twice.
	 */
	private function lockPlacementAndResult(Context $context, string $uidId): void
	{
		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();

		$connection->query(sprintf(
			"SELECT ID FROM %s WHERE MAILBOX_ID = %u AND ID = '%s' FOR UPDATE",
			$helper->quote(MailMessageUidTable::getTableName()),
			$context->mailboxId,
			$helper->forSql($uidId),
		));

		$connection->query(sprintf(
			"SELECT ID FROM %s WHERE GENERATION_ID = %u AND UID_ID = '%s' FOR UPDATE",
			$helper->quote(SourceGenerationMatchTable::getTableName()),
			$context->generationId,
			$helper->forSql($uidId),
		));
	}

	/**
	 * The physical row of this generation starts serving the logical message.
	 */
	private function linkPlacement(Context $context, string $uidId, int $messageId): void
	{
		MailMessageUidTable::update(
			[
				'ID' => $uidId,
				'MAILBOX_ID' => $context->mailboxId,
			],
			[
				'MESSAGE_ID' => $messageId,
				'DELETE_TIME' => 0,
				'IS_OLD' => MailMessageUidTable::RECENT,
			],
		);
	}

	/**
	 * A letter the user had already read stays read after the transfer: the placement of the
	 * new generation is registered from the flags of a server that knows nothing of what was
	 * read here, so the state of the archived placement is carried over.
	 *
	 * In one direction only. An archived placement nobody read says nothing about the new
	 * one, whose server may well report it read; and a placement waiting to push a state of
	 * its own is the decision of the user, which no repeated import may undo.
	 */
	private function carryReadStateOfTheArchive(Context $context, string $uidId, int $messageId): void
	{
		$placements = MailMessageUidTable::getList([
			'select' => ['ID', 'IS_SEEN'],
			'filter' => [
				'=MAILBOX_ID' => $context->mailboxId,
				'=MESSAGE_ID' => $messageId,
			],
		])->fetchAll();

		$isRead = false;
		$imported = '';

		foreach ($placements as $placement)
		{
			$seen = (string)$placement['IS_SEEN'];

			if ((string)$placement['ID'] === $uidId)
			{
				$imported = $seen;

				continue;
			}

			$isRead = $isRead || in_array($seen, self::READ_STATES, true);
		}

		if (!$isRead || $imported !== self::UNREAD_STATE)
		{
			return;
		}

		MailMessageUidTable::update(
			[
				'ID' => $uidId,
				'MAILBOX_ID' => $context->mailboxId,
			],
			['IS_SEEN' => self::READ_STATE],
		);
	}

	/**
	 * The candidates an ambiguous decision could not tell apart. They stay a record
	 * of why the letter got its own message; nothing ever merges by them.
	 */
	private function storeCandidateDiagnostics(Context $context, string $uidId, MatchDecision $decision): void
	{
		if (!$decision->isAmbiguous() || $decision->candidates === [])
		{
			return;
		}

		$row = SourceGenerationMatchTable::getRow([
			'select' => ['ID'],
			'filter' => [
				'=GENERATION_ID' => $context->generationId,
				'=UID_ID' => $uidId,
			],
		]);

		if ($row === null)
		{
			return;
		}

		$primary = [
			'MAILBOX_ID' => $context->mailboxId,
			'ENTITY_TYPE' => MailEntityOptionsTable::SOURCE_GENERATION_MATCH_TYPE_NAME,
			'ENTITY_ID' => (string)$row['ID'],
			'PROPERTY_NAME' => self::CANDIDATES_PROPERTY,
		];

		$value = implode(',', array_slice(
			array_map(intval(...), $decision->candidates),
			0,
			self::MAX_STORED_CANDIDATES,
		));

		if (MailEntityDataTable::getRow(['select' => ['VALUE'], 'filter' => $primary]) === null)
		{
			MailEntityDataTable::add($primary + [
				'VALUE' => $value,
				'DATE_INSERT' => new DateTime(),
			]);

			return;
		}

		MailEntityDataTable::update($primary, ['VALUE' => $value]);
	}

	private function isBodyPending(int $mailboxId, int $messageId): bool
	{
		return MailEntityOptionsTable::getRow([
			'select' => ['VALUE'],
			'filter' => [
				'=MAILBOX_ID' => $mailboxId,
				'=ENTITY_TYPE' => MailEntityOptionsTable::MESSAGE_TYPE_NAME,
				'=ENTITY_ID' => (string)$messageId,
				'=PROPERTY_NAME' => self::UNSYNC_BODY_PROPERTY,
				'=VALUE' => 'Y',
			],
		]) !== null;
	}

	/**
	 * The html of a message saved without the sanitizer of the read path is stored
	 * already sanitized, exactly as the save path does it.
	 */
	private function prepareHtmlForView(array $message, string $bodyHtml): string
	{
		return empty($message[MailMessageTable::FIELD_SANITIZE_ON_VIEW])
			? Message::sanitizeHtmlForMessageView($bodyHtml)
			: $bodyHtml
		;
	}

	/**
	 * @return string The html the message keeps, with the content ids of the created attachments resolved.
	 */
	private function fillMissingAttachments(array $message, string $bodyHtml, array $attachments): string
	{
		if (
			$attachments === []
			|| (int)$message['ATTACHMENTS'] > 0
			|| Option::get('mail', 'save_attachments', B_MAIL_SAVE_ATTACHMENTS) !== 'Y'
		)
		{
			return $bodyHtml;
		}

		foreach ($attachments as $part)
		{
			// A part the fetch did not download would become an empty file: leave the whole set to the lazy path
			if (!is_array($part) || (string)($part['BODY'] ?? '') === '')
			{
				return $bodyHtml;
			}
		}

		foreach ($attachments as $part)
		{
			$attachmentId = \CMailMessage::addAttachment([
				'MESSAGE_ID' => (int)$message['ID'],
				'FILE_NAME' => (string)($part['FILENAME'] ?? ''),
				'CONTENT_TYPE' => (string)($part['CONTENT-TYPE'] ?? ''),
				'FILE_DATA' => $part['BODY'],
				'CONTENT_ID' => (string)($part['CONTENT-ID'] ?? ''),
			]);

			if ($attachmentId > 0 && $bodyHtml !== '')
			{
				$bodyHtml = Message::replaceBodyInlineImgContentId(
					$bodyHtml,
					(string)($part['CONTENT-ID'] ?? ''),
					$attachmentId,
				);
			}
		}

		return $bodyHtml;
	}
}

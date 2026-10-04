<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\Message;

use Bitrix\Mail\MailMessageTable;
use Bitrix\Mail\MailMessageUidTable;
use Bitrix\Main\ORM\Fields\ExpressionField;

/**
 * Reads what classification needs about a message.
 */
class ClassificationMessageRepository
{
	/**
	 * The mailbox is part of the filter, not a check of its own: a job naming a foreign mailbox
	 * must not read the letter at all.
	 *
	 * @return array{SUBJECT: ?string, BODY: ?string, BODY_HTML: ?string}|null Subject and the one body
	 *         the builder will take, or null when the message is gone.
	 */
	public function loadForClassification(int $mailboxId, int $messageId): ?array
	{
		$row = MailMessageTable::getList([
			'select' => ['SUBJECT', 'BODY_CAPPED', 'BODY_HTML_CAPPED'],
			'filter' => [
				'=ID' => $messageId,
				'=MAILBOX_ID' => $mailboxId,
			],
			'runtime' => [
				self::cappedMarkupBody(),
				self::cappedPlainBody(),
			],
			'limit' => 1,
		])->fetch();

		if (!$row)
		{
			return null;
		}

		// The orm turns down an alias repeating a field, so the keys are restored for the text builder.
		return [
			'SUBJECT' => $row['SUBJECT'],
			'BODY' => $row['BODY_CAPPED'],
			'BODY_HTML' => $row['BODY_HTML_CAPPED'],
		];
	}

	private static function cappedMarkupBody(): ExpressionField
	{
		return self::inheritFetchModifiers(
			new ExpressionField('BODY_HTML_CAPPED', self::cut('%s'), ['BODY_HTML']),
			'BODY_HTML',
		);
	}

	/**
	 * Only the body the builder will take is read, and the choice belongs to the query: choosing in php
	 * means both bodies travel over the connection first. Emptiness here is `BODY_HTML ?: BODY` of the
	 * legacy read path and not the trim of the builder.
	 */
	private static function cappedPlainBody(): ExpressionField
	{
		return self::inheritFetchModifiers(
			new ExpressionField(
				'BODY_CAPPED',
				"CASE WHEN %s <> '' THEN '' ELSE " . self::cut('%s') . ' END',
				['BODY_HTML', 'BODY'],
			),
			'BODY',
		);
	}

	/**
	 * The source limit of the text builder and not its text limit. SUBSTRING counts characters, so a
	 * multibyte letter comes back whole.
	 */
	private static function cut(string $field): string
	{
		return 'SUBSTRING(' . $field . ', 1, ' . ClassificationTextBuilder::MAX_SOURCE_LENGTH . ')';
	}

	/**
	 * An expression does not inherit the fetch modifiers of its field, and BODY_HTML needs them: emoji
	 * are stored as placeholders and decoded on the way out.
	 */
	private static function inheritFetchModifiers(ExpressionField $expression, string $field): ExpressionField
	{
		foreach (MailMessageTable::getEntity()->getField($field)->getFetchDataModifiers() as $modifier)
		{
			$expression->addFetchDataModifier($modifier);
		}

		return $expression;
	}

	/**
	 * Checks the live mailbox-message pair, so a result arriving for a deleted message never turns into an
	 * orphan mark. A missing uid row is the ordinary state of a deleted letter, not a letter mid-arrival:
	 * believing the message row here would label letters the user has just deleted.
	 */
	public function exists(int $mailboxId, int $messageId): bool
	{
		return MailMessageUidTable::getList([
			'select' => ['MESSAGE_ID'],
			'filter' => [
				'=MAILBOX_ID' => $mailboxId,
				'=MESSAGE_ID' => $messageId,
				'==DELETE_TIME' => 0,
			],
			'limit' => 1,
		])->fetch() !== false;
	}
}

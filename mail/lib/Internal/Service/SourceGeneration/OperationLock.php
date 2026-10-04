<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\SourceGeneration;

use Bitrix\Main\Application;

/**
 * The lock of the source generation operations of one mailbox.
 *
 * Three things take it, and every one of them writes physical rows of the mailbox or takes them
 * away: a pass of a migration, the initialization of the first generation, and the deletion of the
 * mailbox. Two of them running side by side leave rows with no owner - a placement of a deleted
 * letter, a generation snapshot of a deleted mailbox - so they take turns instead.
 *
 * The name lives here and not in the service that owns the operations: the initialization runs from
 * the hook of a new mailbox and from the update stepper, and the deletion runs from the legacy core.
 * Neither has a reason to know about the migration service, and a dependency on it for the sake of
 * one string is what makes a low level path break in a place that never mentions migrations.
 *
 * The lock is held by the session, so a process that dies releases it - nothing stays stuck.
 */
final class OperationLock
{
	private const PREFIX = 'mail_source_generation_migration_';

	public static function name(int $mailboxId): string
	{
		return self::PREFIX . $mailboxId;
	}

	/**
	 * @param int $timeoutSeconds 0 does not wait at all: a caller that finds the mailbox busy
	 *        leaves it to the one that holds it.
	 */
	public static function acquire(int $mailboxId, int $timeoutSeconds = 0): bool
	{
		return (bool)Application::getConnection()->lock(self::name($mailboxId), $timeoutSeconds);
	}

	public static function release(int $mailboxId): void
	{
		Application::getConnection()->unlock(self::name($mailboxId));
	}
}

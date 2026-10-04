<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internals;

use Bitrix\Main\Entity;

/**
 * Durable scheduling snapshot of a mailbox migration operation.
 *
 * Connection credentials deliberately stay in MailboxSourceGenerationTable. This table
 * contains only the state required by the common migration agent and by status readers.
 */
class MigrationOperationTable extends Entity\DataManager
{
	public const STATE_RUNNING = 'RUNNING';
	public const STATE_WAITING = 'WAITING';
	public const STATE_SCHEDULED = 'SCHEDULED';
	public const STATE_NEEDS_ATTENTION = 'NEEDS_ATTENTION';
	public const STATE_CANCELLING = 'CANCELLING';
	public const STATE_DONE = 'DONE';
	public const STATE_CANCELLED = 'CANCELLED';

	public const ERROR_OWNER_MAIL = 'mail';
	public const ERROR_OWNER_MAILSERVICE = 'mailservice';
	public const ERROR_OWNER_INFRASTRUCTURE = 'infrastructure';

	public static function getFilePath()
	{
		return __FILE__;
	}

	public static function getTableName()
	{
		return 'b_mail_migration_operation';
	}

	public static function getMap()
	{
		return [
			'ID' => [
				'data_type' => 'integer',
				'primary' => true,
				'autocomplete' => true,
			],
			'MAILBOX_ID' => [
				'data_type' => 'integer',
				'required' => true,
			],
			'OPERATION_ID' => [
				'data_type' => 'string',
				'required' => true,
			],
			'GENERATION_ID' => [
				'data_type' => 'integer',
				'default_value' => 0,
			],
			'EXEC_STATE' => [
				'data_type' => 'enum',
				'required' => true,
				'values' => [
					self::STATE_RUNNING,
					self::STATE_WAITING,
					self::STATE_SCHEDULED,
					self::STATE_NEEDS_ATTENTION,
					self::STATE_CANCELLING,
					self::STATE_DONE,
					self::STATE_CANCELLED,
				],
			],
			'STAGE' => [
				'data_type' => 'string',
				'required' => true,
			],
			'SNAPSHOT_ACCEPTED' => [
				'data_type' => 'boolean',
				'values' => ['N', 'Y'],
				'default_value' => 'N',
			],
			'SWITCH_AUTHORIZED' => [
				'data_type' => 'boolean',
				'values' => ['N', 'Y'],
				'default_value' => 'N',
			],
			'CANCEL_REQUESTED' => [
				'data_type' => 'boolean',
				'values' => ['N', 'Y'],
				'default_value' => 'N',
			],
			'AUTO_RETRY' => [
				'data_type' => 'boolean',
				'values' => ['N', 'Y'],
				'default_value' => 'Y',
			],
			'PUBLICATION_PENDING' => [
				'data_type' => 'boolean',
				'values' => ['N', 'Y'],
				'default_value' => 'N',
			],
			'NOTIFICATION_PENDING' => [
				'data_type' => 'boolean',
				'values' => ['N', 'Y'],
				'default_value' => 'N',
			],
			'ATTEMPTS' => [
				'data_type' => 'integer',
				'default_value' => 0,
			],
			'STALLED_ATTEMPTS' => [
				'data_type' => 'integer',
				'default_value' => 0,
			],
			'NEXT_RUN' => ['data_type' => 'datetime'],
			'LAST_PROGRESS' => ['data_type' => 'datetime'],
			'LAST_ERROR_CODE' => ['data_type' => 'string'],
			'ERROR_OWNER' => ['data_type' => 'string'],
			'MIGRATOR_SERVICE' => ['data_type' => 'string'],
			'DATE_CREATE' => [
				'data_type' => 'datetime',
				'required' => true,
			],
			'DATE_MODIFY' => ['data_type' => 'datetime'],
		];
	}
}

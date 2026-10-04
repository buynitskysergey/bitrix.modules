<?php

namespace Bitrix\Mail\Internals;

use Bitrix\Mail\MailboxTable;
use Bitrix\Main\Entity;

/**
 * Class MailboxSourceGenerationTable
 *
 * Physical IMAP source generations of a logical mailbox. The single source of truth
 * for IMAP credentials of every generation; connection fields of b_mail_mailbox are
 * a legacy projection of the active generation maintained by the switch service only.
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_MailboxSourceGeneration_Query query()
 * @method static EO_MailboxSourceGeneration_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_MailboxSourceGeneration_Result getById($id)
 * @method static EO_MailboxSourceGeneration_Result getList(array $parameters = [])
 * @method static EO_MailboxSourceGeneration_Entity getEntity()
 * @method static \Bitrix\Mail\Internals\EO_MailboxSourceGeneration createObject($setDefaultValues = true)
 * @method static \Bitrix\Mail\Internals\EO_MailboxSourceGeneration_Collection createCollection()
 * @method static \Bitrix\Mail\Internals\EO_MailboxSourceGeneration wakeUpObject($row)
 * @method static \Bitrix\Mail\Internals\EO_MailboxSourceGeneration_Collection wakeUpCollection($rows)
 */
class MailboxSourceGenerationTable extends Entity\DataManager
{
	public const STATUS_PREPARING = 'PREPARING';
	public const STATUS_ACTIVE = 'ACTIVE';
	public const STATUS_ARCHIVED = 'ARCHIVED';

	/** The deterministic operation of the G1 backfill: marks the initial generation of a mailbox. */
	public const OPERATION_G1_BACKFILL = 'g1-backfill';

	/**
	 * OPTIONS key of the managed transfer service the operation declared, empty for an
	 * operation nobody carries out for us.
	 */
	public const OPTION_MIGRATOR_SERVICE = 'migrator_service';

	/** OPTIONS key confirming that the source system authorized the final switch. */
	public const OPTION_FINAL_SWITCH_AUTHORIZED = 'final_switch_authorized';

	/** OPTIONS key that stops migration passes and hands the generation to cancellation cleanup. */
	public const OPTION_CANCEL_REQUESTED = 'migration_cancel_requested';

	public static function getFilePath()
	{
		return __FILE__;
	}

	public static function getTableName()
	{
		return 'b_mail_mailbox_source_generation';
	}

	/**
	 * The password of a generation is the password of the mailbox: the switch projects one
	 * into the other, so the two are the same credential and must be protected alike.
	 *
	 * Crypto is switched on per table name, and this table is nobody's default, so the
	 * setting of the mailbox is asked instead of one of its own. Without that the snapshot
	 * would silently stay on the legacy encryption of the module while the mailbox moved on.
	 */
	public static function passwordCryptoEnabled(): bool
	{
		return static::cryptoEnabled('PASSWORD', MailboxTable::getTableName());
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
			'STATUS' => [
				'data_type' => 'enum',
				'required' => true,
				'values' => [
					self::STATUS_PREPARING,
					self::STATUS_ACTIVE,
					self::STATUS_ARCHIVED,
				],
			],
			'REVISION' => [
				'data_type' => 'integer',
				'default_value' => 0,
			],
			'SERVICE_ID' => [
				'data_type' => 'integer',
				'default_value' => 0,
			],
			'SERVICE_NAME' => [
				'data_type' => 'string',
			],
			'EMAIL' => [
				'data_type' => 'string',
			],
			'SERVER' => [
				'data_type' => 'string',
			],
			'PORT' => [
				'data_type' => 'integer',
			],
			'USE_TLS' => [
				'data_type' => 'enum',
				'values' => ['N', 'Y', 'S'],
			],
			'LOGIN' => [
				'data_type' => 'string',
			],
			'PASSWORD' => [
				'data_type' => (static::passwordCryptoEnabled() ? 'crypto' : 'string'),
				'save_data_modification' => function ()
				{
					return [
						function ($value)
						{
							return static::passwordCryptoEnabled() ? $value : \CMailUtil::crypt($value);
						},
					];
				},
				'fetch_data_modification' => function ()
				{
					return [
						function ($value)
						{
							return static::passwordCryptoEnabled() ? $value : \CMailUtil::decrypt($value);
						},
					];
				},
			],
			'SMTP_SERVER' => [
				'data_type' => 'string',
			],
			'SMTP_PORT' => [
				'data_type' => 'integer',
			],
			'SMTP_PROTOCOL' => [
				'data_type' => 'enum',
				'values' => ['smtp', 'smtps'],
			],
			'SMTP_LOGIN' => [
				'data_type' => 'string',
			],
			'SMTP_PASSWORD' => [
				'data_type' => (static::passwordCryptoEnabled() ? 'crypto' : 'string'),
				'save_data_modification' => function ()
				{
					return [
						function ($value)
						{
							if ($value === null)
							{
								return null;
							}

							return static::passwordCryptoEnabled() ? $value : \CMailUtil::crypt($value);
						},
					];
				},
				'fetch_data_modification' => function ()
				{
					return [
						function ($value)
						{
							if ($value === null)
							{
								return null;
							}

							return static::passwordCryptoEnabled() ? $value : \CMailUtil::decrypt($value);
						},
					];
				},
			],
			'SMTP_LIMIT' => [
				'data_type' => 'integer',
			],
			'OPTIONS' => [
				'data_type' => 'text',
				'save_data_modification' => function ()
				{
					return [
						function ($options)
						{
							return serialize($options);
						},
					];
				},
				'fetch_data_modification' => function ()
				{
					return [
						function ($values)
						{
							return unserialize($values, ['allowed_classes' => false]);
						},
					];
				},
			],
			'DATE_CREATE' => [
				'data_type' => 'datetime',
				'required' => true,
			],
			'DATE_ACTIVATED' => [
				'data_type' => 'datetime',
			],
			'DATE_ARCHIVED' => [
				'data_type' => 'datetime',
			],
			'MAILBOX' => [
				'data_type' => 'Bitrix\Mail\Mailbox',
				'reference' => ['=this.MAILBOX_ID' => 'ref.ID'],
			],
		];
	}
}

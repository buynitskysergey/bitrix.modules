<?php

namespace Bitrix\Mail\Internals;

use Bitrix\Main\Entity;

/**
 * Class MessageFingerprintTable
 *
 * Strong-feature fingerprints of local messages used by the source generation
 * matching. Stores hashes only, never open message content.
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_MessageFingerprint_Query query()
 * @method static EO_MessageFingerprint_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_MessageFingerprint_Result getById($id)
 * @method static EO_MessageFingerprint_Result getList(array $parameters = [])
 * @method static EO_MessageFingerprint_Entity getEntity()
 * @method static \Bitrix\Mail\Internals\EO_MessageFingerprint createObject($setDefaultValues = true)
 * @method static \Bitrix\Mail\Internals\EO_MessageFingerprint_Collection createCollection()
 * @method static \Bitrix\Mail\Internals\EO_MessageFingerprint wakeUpObject($row)
 * @method static \Bitrix\Mail\Internals\EO_MessageFingerprint_Collection wakeUpCollection($rows)
 */
class MessageFingerprintTable extends Entity\DataManager
{
	public const KIND_MIGRATOR_ID = 'MIGRATOR_ID';
	public const KIND_MESSAGE_ID = 'MESSAGE_ID';
	public const KIND_CONTENT = 'CONTENT';

	public static function getFilePath()
	{
		return __FILE__;
	}

	public static function getTableName()
	{
		return 'b_mail_message_fingerprint';
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
			'MESSAGE_ID' => [
				'data_type' => 'integer',
				'required' => true,
			],
			'GENERATION_ID' => [
				'data_type' => 'integer',
				'default_value' => 0,
			],
			'KIND' => [
				'data_type' => 'enum',
				'required' => true,
				'values' => [
					self::KIND_MIGRATOR_ID,
					self::KIND_MESSAGE_ID,
					self::KIND_CONTENT,
				],
			],
			'ALGORITHM_VERSION' => [
				'data_type' => 'integer',
				'required' => true,
			],
			'HASH' => [
				'data_type' => 'string',
				'required' => true,
			],
			'IS_COMPLETE' => [
				'data_type' => 'boolean',
				'values' => ['N', 'Y'],
				'default_value' => 'N',
			],
			'DATE_CREATE' => [
				'data_type' => 'datetime',
				'required' => true,
			],
		];
	}
}

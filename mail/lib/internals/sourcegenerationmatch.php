<?php

namespace Bitrix\Mail\Internals;

use Bitrix\Main\Entity;

/**
 * Class SourceGenerationMatchTable
 *
 * Matching results between UID rows of a preparing generation and local messages.
 * MESSAGE_ID is the final local identity: the previous one for MATCHED, a new one
 * for NEW/AMBIGUOUS. Candidate lists and compared features live in entity options
 * of the match row, not here.
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_SourceGenerationMatch_Query query()
 * @method static EO_SourceGenerationMatch_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_SourceGenerationMatch_Result getById($id)
 * @method static EO_SourceGenerationMatch_Result getList(array $parameters = [])
 * @method static EO_SourceGenerationMatch_Entity getEntity()
 * @method static \Bitrix\Mail\Internals\EO_SourceGenerationMatch createObject($setDefaultValues = true)
 * @method static \Bitrix\Mail\Internals\EO_SourceGenerationMatch_Collection createCollection()
 * @method static \Bitrix\Mail\Internals\EO_SourceGenerationMatch wakeUpObject($row)
 * @method static \Bitrix\Mail\Internals\EO_SourceGenerationMatch_Collection wakeUpCollection($rows)
 */
class SourceGenerationMatchTable extends Entity\DataManager
{
	public const STATE_MATCHED = 'MATCHED';
	public const STATE_NEW = 'NEW';
	public const STATE_AMBIGUOUS = 'AMBIGUOUS';

	public static function getFilePath()
	{
		return __FILE__;
	}

	public static function getTableName()
	{
		return 'b_mail_source_generation_match';
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
			'GENERATION_ID' => [
				'data_type' => 'integer',
				'required' => true,
			],
			'UID_ID' => [
				'data_type' => 'string',
				'required' => true,
			],
			'OPERATION_ID' => [
				'data_type' => 'string',
				'required' => true,
			],
			'STATE' => [
				'data_type' => 'enum',
				'required' => true,
				'values' => [
					self::STATE_MATCHED,
					self::STATE_NEW,
					self::STATE_AMBIGUOUS,
				],
			],
			'MESSAGE_ID' => [
				'data_type' => 'integer',
				'default_value' => 0,
			],
			'NORMALIZER_VERSION' => [
				'data_type' => 'integer',
				'required' => true,
			],
			'REASON_CODE' => [
				'data_type' => 'string',
			],
			'ATTEMPTS' => [
				'data_type' => 'integer',
				'default_value' => 0,
			],
			'DATE_CREATE' => [
				'data_type' => 'datetime',
				'required' => true,
			],
			'DATE_UPDATE' => [
				'data_type' => 'datetime',
			],
			'GENERATION' => [
				'data_type' => 'Bitrix\Mail\Internals\MailboxSourceGeneration',
				'reference' => ['=this.GENERATION_ID' => 'ref.ID'],
			],
		];
	}
}

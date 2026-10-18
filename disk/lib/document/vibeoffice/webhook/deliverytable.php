<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice\Webhook;

use Bitrix\Disk\Internals\DataManager;
use Bitrix\Main\Application;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\TextField;
use Bitrix\Main\Type\DateTime;

/**
 * Idempotency ledger of processed vibeoffice webhook deliveries (P5.T1, SQL-DELIVERY).
 *
 * Retries resume pending/staged/applied work; DONE may still need after-commit cleanup.
 * Only historical DONE rows without HOST_DOC_KEY are subject to retention cleanup.
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_Delivery_Query query()
 * @method static EO_Delivery_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_Delivery_Result getById($id)
 * @method static EO_Delivery_Result getList(array $parameters = [])
 * @method static EO_Delivery_Entity getEntity()
 * @method static \Bitrix\Disk\Document\Vibeoffice\Webhook\EO_Delivery createObject($setDefaultValues = true)
 * @method static \Bitrix\Disk\Document\Vibeoffice\Webhook\EO_Delivery_Collection createCollection()
 * @method static \Bitrix\Disk\Document\Vibeoffice\Webhook\EO_Delivery wakeUpObject($row)
 * @method static \Bitrix\Disk\Document\Vibeoffice\Webhook\EO_Delivery_Collection wakeUpCollection($rows)
 */
final class DeliveryTable extends DataManager
{
	public const STATUS_PENDING = 'pending';
	public const STATUS_STAGED = 'staged';
	public const STATUS_APPLIED = 'applied';
	public const STATUS_DONE = 'done';

	/** Retention of historical legacy DONE rows (seconds). */
	private const TTL = 7 * 24 * 3600;

	/** Rows removed per DELETE so the cleanup never locks the whole ledger with one big statement. */
	private const DELETE_CHUNK_SIZE = 1000;

	public static function getTableName()
	{
		return 'b_disk_vibeoffice_delivery';
	}

	public static function getMap()
	{
		return [
			(new IntegerField('ID'))
				->configureSize(8)
				->configurePrimary()
				->configureAutocomplete()
			,
			(new IntegerField('DELIVERY_ID'))
				->configureSize(8)
				->configureRequired()
			,
			(new StringField('EXTERNAL_HASH'))
				->configureSize(128)
			,
			(new StringField('HOST_DOC_KEY'))->configureNullable()->configureSize(128),
			(new StringField('STATUS'))->configureSize(24)->configureDefaultValue(self::STATUS_DONE),
			(new IntegerField('SNAPSHOT_SEQ'))->configureSize(8)->configureNullable(),
			(new IntegerField('RESULT_OBJECT_ID'))->configureNullable(),
			(new IntegerField('RESULT_VERSION_ID'))->configureNullable(),
			(new IntegerField('STAGED_FILE_ID'))->configureNullable(),
			(new TextField('CLEANUP_FILE_IDS'))->configureNullable(),
			(new IntegerField('NOTIFIED'))->configureDefaultValue(0),
			(new StringField('EVENT'))
				->configureRequired()
				->configureSize(64)
			,
			(new DatetimeField('PROCESSED_TIME'))
				->configureRequired()
				->configureDefaultValue(static function() {
					return new DateTime();
				})
			,
		];
	}

	/**
	 * Background agent that prunes old processed-delivery rows (idempotency ledger).
	 *
	 * Modeled on {@see \Bitrix\Disk\Document\OnlyOffice\RestrictionManager::deleteOldOrPendingAgent()};
	 * TTL is not normative (SQL-DELIVERY). Self-rescheduling agent.
	 */
	public static function deleteOldDeliveriesAgent(): string
	{
		self::deleteOldDeliveries();

		return self::class . '::deleteOldDeliveriesAgent();';
	}

	public static function deleteOldDeliveries(?DateTime $now = null): void
	{
		$connection = Application::getConnection();
		$tableName = self::getTableName();
		$ttlTime = DateTime::createFromTimestamp(($now?->getTimestamp() ?? time()) - self::TTL);

		// Delete in bounded batches (supported by both MySQL and PostgreSQL via SELECT ... LIMIT,
		// unlike DELETE ... LIMIT which is MySQL-only) so a large backlog never blocks the ledger
		// with one long-running DELETE. The PROCESSED_TIME index keeps each batch's scan cheap.
		do
		{
			$ids = self::query()
				->setSelect(['ID'])
				->whereNull('HOST_DOC_KEY')
				->where('STATUS', self::STATUS_DONE)
				->where('PROCESSED_TIME', '<', $ttlTime)
				->setLimit(self::DELETE_CHUNK_SIZE)
				->fetchAll()
			;

			$ids = array_map('intval', array_column($ids, 'ID'));
			if (!$ids)
			{
				break;
			}

			$connection->queryExecute(
				(new SqlExpression(
					'DELETE FROM ?# WHERE ID IN (?@)',
					$tableName,
					$ids,
				))->compile()
			);
		}
		while (count($ids) === self::DELETE_CHUNK_SIZE);
	}
}

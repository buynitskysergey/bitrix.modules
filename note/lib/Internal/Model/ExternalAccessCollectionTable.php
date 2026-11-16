<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\Relations\Reference;

/**
 * Class ExternalAccessCollectionTable
 *
 * The scope of one external access record. The unique index on COLLECTION_ID is the
 * "one link per knowledge base" rule, held by the schema rather than by code.
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_ExternalAccessCollection_Query query()
 * @method static EO_ExternalAccessCollection_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_ExternalAccessCollection_Result getById($id)
 * @method static EO_ExternalAccessCollection_Result getList(array $parameters = [])
 * @method static EO_ExternalAccessCollection_Entity getEntity()
 * @method static \Bitrix\Note\Internal\Model\EO_ExternalAccessCollection createObject($setDefaultValues = true)
 * @method static \Bitrix\Note\Internal\Model\EO_ExternalAccessCollection_Collection createCollection()
 * @method static \Bitrix\Note\Internal\Model\EO_ExternalAccessCollection wakeUpObject($row)
 * @method static \Bitrix\Note\Internal\Model\EO_ExternalAccessCollection_Collection wakeUpCollection($rows)
 */
class ExternalAccessCollectionTable extends DataManager
{
	use DeleteByFilterTrait;

	public static function getTableName(): string
	{
		return 'b_note_external_access_collection';
	}

	public static function getMap(): array
	{
		return [
			new IntegerField('ACCESS_ID', [
				'primary' => true,
				'required' => true,
			]),
			new IntegerField('COLLECTION_ID', [
				'primary' => true,
				'required' => true,
			]),
			new Reference(
				'ACCESS',
				ExternalAccessTable::class,
				['=this.ACCESS_ID' => 'ref.ID'],
				['join_type' => 'INNER'],
			),
		];
	}
}

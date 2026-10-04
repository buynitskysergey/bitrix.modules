<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields\IntegerField;

/**
 * Class EventAuthorTable
 *
 * Co-authors of a change, tied to the EVENT (not the version): events are
 * permanent (not TTL-cleaned), so authorship survives after the version
 * snapshot is removed by TTL. Populated only for content_changed events.
 *
 * Fields:
 * <ul>
 * <li> EVENT_ID bigint mandatory
 * <li> USER_ID int mandatory
 * </ul>
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_EventAuthor_Query query()
 * @method static EO_EventAuthor_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_EventAuthor_Result getById($id)
 * @method static EO_EventAuthor_Result getList(array $parameters = [])
 * @method static EO_EventAuthor_Entity getEntity()
 * @method static \Bitrix\Note\Internal\Model\EO_EventAuthor createObject($setDefaultValues = true)
 * @method static \Bitrix\Note\Internal\Model\EO_EventAuthor_Collection createCollection()
 * @method static \Bitrix\Note\Internal\Model\EO_EventAuthor wakeUpObject($row)
 * @method static \Bitrix\Note\Internal\Model\EO_EventAuthor_Collection wakeUpCollection($rows)
 */
class EventAuthorTable extends DataManager
{
	use DeleteByFilterTrait;

	public static function getTableName(): string
	{
		return 'b_note_event_author';
	}

	public static function getMap(): array
	{
		return [
			new IntegerField('EVENT_ID', [
				'primary' => true,
				'required' => true,
			]),
			new IntegerField('USER_ID', [
				'primary' => true,
				'required' => true,
			]),
		];
	}
}

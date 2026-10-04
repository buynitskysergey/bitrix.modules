<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\Type\DateTime;

/**
 * [P1.T1 / SQL-01] One row per (source document, target document) link found in the source's
 * content, rewritten as a whole set by DocumentLinkRepository on the
 * UNIQUE(SOURCE_ID, TARGET_ID) index.
 *
 * The row carries nothing but the pair: the backlink list shows the source's title, position and
 * timestamp, and all of those are read through the SOURCE join, never copied here. Existence of
 * the target is not checked on write and there are no foreign keys - a link may point at a
 * document that does not exist yet or any more.
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_DocumentLink_Query query()
 * @method static EO_DocumentLink_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_DocumentLink_Result getById($id)
 * @method static EO_DocumentLink_Result getList(array $parameters = [])
 * @method static EO_DocumentLink_Entity getEntity()
 * @method static \Bitrix\Note\Internal\Model\EO_DocumentLink createObject($setDefaultValues = true)
 * @method static \Bitrix\Note\Internal\Model\EO_DocumentLink_Collection createCollection()
 * @method static \Bitrix\Note\Internal\Model\EO_DocumentLink wakeUpObject($row)
 * @method static \Bitrix\Note\Internal\Model\EO_DocumentLink_Collection wakeUpCollection($rows)
 */
class DocumentLinkTable extends DataManager
{
	use DeleteByFilterTrait;

	public static function getTableName(): string
	{
		return 'b_note_document_link';
	}

	public static function getMap(): array
	{
		return [
			new IntegerField('ID', [
				'primary' => true,
				'autocomplete' => true,
			]),
			new IntegerField('SOURCE_ID', [
				'required' => true,
			]),
			new IntegerField('TARGET_ID', [
				'required' => true,
			]),
			new DatetimeField('CREATED_AT', [
				'required' => true,
				'default_value' => static fn() => new DateTime(),
			]),
			// Sort key, title and visibility of a backlink all live on the source document, so the
			// backlink read is a join; SOURCE.RECYCLE_BIN reaches the trash through it as well.
			new Reference(
				'SOURCE',
				DocumentTable::class,
				['=this.SOURCE_ID' => 'ref.ID'],
				['join_type' => 'LEFT'],
			),
		];
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Internal\Model;

use Bitrix\Main\Application;
use Bitrix\Main\Error;
use Bitrix\Main\ORM\Data\AddResult;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\Type\DateTime;

/**
 * Class ReportDiscussionTable
 *
 * Links a full report to the pair chat it is discussed in and stores per-message
 * delivery markers (MAIN_MESSAGE_ID for the AI/robot message, COMMENT_MESSAGE_ID
 * for the handwritten one). NULL marker means the message is not delivered yet and
 * a retry may send it. Uniqueness is by the (REPORT_ID, MANAGER_ID) pair: one report
 * may live in several pair chats, one per discussing manager.
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_ReportDiscussion_Query query()
 * @method static EO_ReportDiscussion_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_ReportDiscussion_Result getById($id)
 * @method static EO_ReportDiscussion_Result getList(array $parameters = [])
 * @method static EO_ReportDiscussion_Entity getEntity()
 * @method static \Bitrix\Timeman\V2\Internal\Model\EO_ReportDiscussion createObject($setDefaultValues = true)
 * @method static \Bitrix\Timeman\V2\Internal\Model\EO_ReportDiscussion_Collection createCollection()
 * @method static \Bitrix\Timeman\V2\Internal\Model\EO_ReportDiscussion wakeUpObject($row)
 * @method static \Bitrix\Timeman\V2\Internal\Model\EO_ReportDiscussion_Collection wakeUpCollection($rows)
 */
class ReportDiscussionTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'b_timeman_report_discussion';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete(),
			(new IntegerField('REPORT_ID'))
				->configureRequired(),
			(new IntegerField('CHAT_ID'))
				->configureRequired(),
			(new IntegerField('MANAGER_ID'))
				->configureRequired(),
			(new IntegerField('EMPLOYEE_ID'))
				->configureRequired(),
			(new IntegerField('MAIN_MESSAGE_ID'))
				->configureNullable(),
			(new IntegerField('COMMENT_MESSAGE_ID'))
				->configureNullable(),
			(new DatetimeField('CREATED_AT'))
				->configureRequired()
				->configureDefaultValue(static fn (): DateTime => new DateTime()),
		];
	}

	/**
	 * Idempotently upserts the link by the (REPORT_ID, MANAGER_ID) key. Message
	 * markers are never part of the payload here, so an existing row keeps them.
	 *
	 * @param array<string, mixed> $data
	 */
	public static function merge(array $data): AddResult
	{
		$result = new AddResult();

		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();

		$mergeFields = ['REPORT_ID', 'MANAGER_ID'];
		$updateData = $data;
		foreach ($mergeFields as $field)
		{
			unset($updateData[$field]);
		}

		$merge = $helper->prepareMerge(static::getTableName(), $mergeFields, $data, $updateData);
		if (($merge[0] ?? '') === '')
		{
			return $result->addError(new Error('Failed to build merge query'));
		}

		$connection->query($merge[0]);
		$result->setId((int)$connection->getInsertedId());

		return $result;
	}

	/**
	 * Returns the CHAT_ID recorded for the given (MANAGER_ID, EMPLOYEE_ID) pair, or null when the
	 * pair has no discussion chat yet. The discussion table is the single source of truth for which
	 * chat belongs to a pair: a chat found here was created and recorded by us and is trusted
	 * regardless of who was later added to it. The most recent row wins (ordered by ID desc).
	 */
	public static function findPairChatId(int $managerId, int $employeeId): ?int
	{
		$row = static::getList([
			'select' => ['CHAT_ID'],
			'filter' => [
				'=MANAGER_ID' => $managerId,
				'=EMPLOYEE_ID' => $employeeId,
			],
			'order' => ['ID' => 'DESC'],
			'limit' => 1,
		])->fetch();

		return $row === false ? null : (int)$row['CHAT_ID'];
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function getByReportAndManager(int $reportId, int $managerId): ?array
	{
		$row = static::getList([
			'filter' => [
				'=REPORT_ID' => $reportId,
				'=MANAGER_ID' => $managerId,
			],
			'limit' => 1,
		])->fetch();

		return $row === false ? null : $row;
	}
}

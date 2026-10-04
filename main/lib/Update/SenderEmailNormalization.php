<?php

declare(strict_types=1);

namespace Bitrix\Main\Update;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Diag\EventLogger;
use Bitrix\Main\Mail\Address;
use Bitrix\Main\Mail\Internal\SenderTable;

/**
 * One-off conversion of the accumulated sender addresses to their normalized form. Records written
 * after the update are normalized by the entity itself (@see Address::normalizeEmail); this walk
 * only catches up with the rows written before it.
 */
class SenderEmailNormalization extends Stepper
{
	/** Read on every step, so that the walk can be stopped and resumed without a hotfix. */
	public const STOP_OPTION = 'sender_email_normalization_stop';

	/** Audit type of the event log record left by a row the walk could not write. */
	public const FAILED_ROW_AUDIT_TYPE = 'MAIL_SENDER_NORMALIZATION_FAILED';

	protected static $moduleId = 'main';

	protected $limit = 100;

	public function execute(array &$option)
	{
		if (Option::get(self::$moduleId, self::STOP_OPTION) === 'Y')
		{
			// the agent has to stay alive: removing the switch must resume the walk, not restart it
			return self::CONTINUE_EXECUTION;
		}

		// `lastId` is the only key the framework does not write by itself: a step that returns before
		// the walk starts leaves a zero in `count`, and a check keyed on `count` would take it for the total
		if (!isset($option['lastId']))
		{
			$option['count'] = $this->countSenders();
			$option['steps'] = 0;
			$option['lastId'] = 0;
		}

		$rows = $this->readPortion((int)$option['lastId']);

		if (!$rows)
		{
			return self::FINISH_EXECUTION;
		}

		foreach ($rows as $row)
		{
			$this->normalizeRow($row);

			$option['lastId'] = (int)$row['ID'];
		}

		$option['steps'] = (int)$option['steps'] + count($rows);

		return self::CONTINUE_EXECUTION;
	}

	private function normalizeRow(array $row): void
	{
		// the comparison belongs in PHP: on MySQL `EMAIL <> LOWER(EMAIL)` finds nothing because of the collation
		$normalized = Address::normalizeEmail((string)$row['EMAIL']);
		if ($normalized === $row['EMAIL'])
		{
			return;
		}

		if (!$this->stillHoldsTheAddressOfThePortion((int)$row['ID'], (string)$row['EMAIL']))
		{
			// somebody saved the record between the read of the portion and this write. The entity
			// normalizes on save, so their value is already in the form the walk exists for: leaving it
			// alone is the whole fix, and it is a normal outcome rather than a failure to report.
			return;
		}

		// the write goes through the entity: it also drops the caches of the record that a raw UPDATE would desync
		$result = SenderTable::update((int)$row['ID'], ['EMAIL' => $normalized]);
		if ($result->isSuccess())
		{
			return;
		}

		// the bookmark has already moved past this row and nobody will read it again, so the one thing
		// that must not be lost is the fact. The walk itself goes on: one row is no reason to stop it.
		(new EventLogger(self::$moduleId, self::FAILED_ROW_AUDIT_TYPE))->warning(
			'Sender #{senderId} kept its address unnormalized: {errors}',
			[
				'senderId' => (int)$row['ID'],
				'errors' => implode('; ', $result->getErrorMessages()),
			]
		);
	}

	/**
	 * Costs one lookup by the primary key, and only for a row that is about to be written anyway: a row
	 * already stored in its normalized form never reaches this point.
	 */
	private function stillHoldsTheAddressOfThePortion(int $senderId, string $email): bool
	{
		$connection = Application::getConnection();

		$stored = $connection->queryScalar(
			'SELECT EMAIL FROM ' . $connection->getSqlHelper()->quote(SenderTable::getTableName())
			. ' WHERE ID = ' . $senderId
		);

		return $stored === $email;
	}

	private function countSenders(): int
	{
		$connection = Application::getConnection();

		return (int)$connection->queryScalar(
			'SELECT COUNT(*) FROM ' . $connection->getSqlHelper()->quote(SenderTable::getTableName())
		);
	}

	/**
	 * A cursor over the primary key without OFFSET: the walk must not re-read the rows it has passed.
	 */
	private function readPortion(int $lastId): array
	{
		$connection = Application::getConnection();
		$table = $connection->getSqlHelper()->quote(SenderTable::getTableName());

		return $connection->query(
			"SELECT ID, EMAIL FROM {$table} WHERE ID > {$lastId} ORDER BY ID ASC LIMIT {$this->limit}"
		)->fetchAll();
	}
}

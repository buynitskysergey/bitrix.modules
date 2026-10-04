<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\LastValues;

use Bitrix\Bizproc\Internal\Config\LastValues;
use Bitrix\Bizproc\Internal\Model\LastValues\WorkflowLastValuesTable;
use Bitrix\Bizproc\Workflow\Entity\WorkflowInstanceTable;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable;
use Bitrix\Main\Application;
use Bitrix\Main\Diag\LoggerFactory;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Web\Json;
use Psr\Log\LoggerInterface;

/**
 * Captures values of a finished run into the template snapshot. The only public entry point of the
 * capture: it runs on the completion hot path, so the feature flag is checked first and the value map
 * is collected only when a capture is really due. The stored snapshot itself is the only source of the
 * throttle state, so a snapshot lost to cleanup or removed by hand is captured again by the next
 * finished run instead of waiting for a throttle marker to expire.
 */
class CaptureService
{
	private const LOGGER_ID = 'bizproc.last_values';

	private const TEMPLATE_VERSIONS_LIMIT = 256;

	/**
	 * Template versions read within the process, null for a template that is not captured at all. A capture
	 * that follows a template resave in the same process stores the previous version key, and the reader
	 * discards such a snapshot as outdated - but it never overwrites a snapshot already stored for a newer
	 * version. The map is dropped as a whole once it outgrows the limit, so a long-lived process does not
	 * keep a version of every template that has passed through it.
	 *
	 * @var array<int, DateTime|null>
	 */
	private static array $templateVersions = [];

	private LastValues $config;
	private ?ValuesCollector $collector;
	private ?LoggerInterface $logger = null;

	public function __construct(?LastValues $config = null, ?ValuesCollector $collector = null)
	{
		$this->config = $config ?? new LastValues();
		$this->collector = $collector;
	}

	/**
	 * Stores the snapshot of the finished run. Never throws: a snapshot is derived data and its loss
	 * must not break the completion of the process.
	 *
	 * @param \CBPActivity $rootActivity root activity of the finished run
	 * @param int $status CBPWorkflowStatus of the completion
	 */
	public function capture(\CBPActivity $rootActivity, int $status): void
	{
		try
		{
			$this->captureSnapshot($rootActivity, $status);
		}
		catch (\Throwable $exception)
		{
			// identifiers only: captured values must never reach the log
			$this->getLogger()?->warning(
				'Bizproc last values capture failed for template {templateId}, workflow {workflowId}: {message}',
				[
					'templateId' => $rootActivity->getWorkflowTemplateId(),
					'workflowId' => $rootActivity->getWorkflowInstanceId(),
					'message' => $exception->getMessage(),
				],
			);
		}
	}

	private function captureSnapshot(\CBPActivity $rootActivity, int $status): void
	{
		if (!$this->config->isCaptureEnabled())
		{
			return;
		}

		$templateId = (int)$rootActivity->getWorkflowTemplateId();
		if ($templateId <= 0)
		{
			return;
		}

		$documentId = (array)$rootActivity->getDocumentId();
		if (!$this->isCompleteDocumentId($documentId))
		{
			// the reader never gives out such a snapshot, so the row would only hold the throttle window
			return;
		}

		$versionKey = $this->getTemplateVersion($templateId);
		if ($versionKey === null)
		{
			return;
		}

		$row = $this->getStoredSnapshotState($templateId);
		$now = time();

		if ($row && $this->getTimestamp($row['VERSION_KEY']) > $versionKey->getTimestamp())
		{
			// a snapshot of a newer template version is already stored: a memoized version key of a
			// long-lived process must not overwrite it
			return;
		}

		if ($row && $this->isThrottled($row, $versionKey, $now))
		{
			return;
		}

		$startedAt = $this->getInstanceStartTime($rootActivity) ?? $now;
		if ($versionKey->getTimestamp() > $startedAt)
		{
			// the run went by the previous version of the template, its values are not mixed in
			return;
		}

		if ($row && !$this->reserveCapture($rootActivity, $templateId, $versionKey, $now))
		{
			return;
		}

		$values = $this->getCollector()->collect($rootActivity);
		if ($values === [])
		{
			return;
		}

		$fields = $this->buildSnapshotFields(
			$rootActivity,
			$documentId,
			$versionKey,
			$status,
			$this->encodeValues($values),
		);

		// only an existing row can be reserved and rewritten conditionally; the first snapshot of a
		// template has no row to reserve, and a placeholder row created up front would be a snapshot of
		// its own - a capture that collects nothing or fails would leave it behind
		if ($row)
		{
			$this->rewriteSnapshot($templateId, $versionKey, $fields);
		}
		else
		{
			$this->merge($templateId, $fields);
		}
	}

	/**
	 * Atomically reserves the right to capture: the version of the stored snapshot and the throttle
	 * interval are checked by the write itself, so out of several completions of one template inside the
	 * interval only one goes on to walk the tree. WORKFLOW_ID is set along with the stamp on purpose -
	 * MySQL counts changed rows and not matched ones, so with throttling off two completions within the
	 * same second would otherwise both look like a lost reservation.
	 */
	private function reserveCapture(
		\CBPActivity $rootActivity,
		int $templateId,
		DateTime $versionKey,
		int $now,
	): bool
	{
		$connection = Application::getConnection();
		$sqlHelper = $connection->getSqlHelper();
		$tableName = WorkflowLastValuesTable::getTableName();

		[$update] = $sqlHelper->prepareUpdate($tableName, [
			'WORKFLOW_ID' => (string)$rootActivity->getWorkflowInstanceId(),
			'UPDATED_AT' => DateTime::createFromTimestamp($now),
		]);

		$versionColumn = $sqlHelper->quote('VERSION_KEY');
		$version = $sqlHelper->convertToDbDateTime($versionKey);

		$sql = 'UPDATE ' . $sqlHelper->quote($tableName)
			. ' SET ' . $update
			. ' WHERE ' . $sqlHelper->quote('TEMPLATE_ID') . ' = ' . $templateId
			. ' AND ' . $versionColumn . ' <= ' . $version
		;

		// the interval is counted off the local clock, like isThrottled() does, and not by SQL means
		$interval = $this->config->getThrottleInterval();
		if ($interval > 0)
		{
			$updatedColumn = $sqlHelper->quote('UPDATED_AT');
			$localTime = $sqlHelper->convertToDbDateTime(DateTime::createFromTimestamp($now));
			$intervalStart = $sqlHelper->convertToDbDateTime(DateTime::createFromTimestamp($now - $interval));

			$sql .= ' AND (' . $versionColumn . ' <> ' . $version
				. ' OR ' . $updatedColumn . ' > ' . $localTime
				. ' OR ' . $updatedColumn . ' <= ' . $intervalStart
				. ')'
			;
		}

		$connection->queryExecute($sql);

		// nothing between the write and the counter: it is read from the connection
		return $connection->getAffectedRowsCount() > 0;
	}

	/**
	 * The whole identifier of the source document is what makes the row readable: the reader denies a
	 * snapshot without MODULE_ID, ENTITY and DOCUMENT_ID by default (ALG-02), so the writer does not
	 * create one.
	 */
	private function isCompleteDocumentId(array $documentId): bool
	{
		return (string)($documentId[0] ?? '') !== ''
			&& (string)($documentId[1] ?? '') !== ''
			&& (string)($documentId[2] ?? '') !== ''
		;
	}

	/**
	 * Only the timestamps the decision needs: VALUES_DATA of the previous capture is not read on the
	 * completion path.
	 *
	 * @return array{VERSION_KEY: DateTime, UPDATED_AT: DateTime}|null
	 */
	private function getStoredSnapshotState(int $templateId): ?array
	{
		return WorkflowLastValuesTable::query()
			->setSelect(['VERSION_KEY', 'UPDATED_AT'])
			->where('TEMPLATE_ID', $templateId)
			->setLimit(1)
			->fetch() ?: null
		;
	}

	/**
	 * Throttling is counted inside a template version and only against a snapshot that is really
	 * stored: the interval runs from UPDATED_AT of the row, so nothing keeps a capture back once the
	 * row is gone. A stamp ahead of the local clock (skew between cluster nodes, a manual edit) is not a
	 * write time to count the interval from, so it does not hold the capture back: the next capture rewrites
	 * UPDATED_AT with the local time and the interval counts from it again. An interval of 0 turns
	 * throttling off completely.
	 */
	private function isThrottled(array $row, DateTime $versionKey, int $now): bool
	{
		$interval = $this->config->getThrottleInterval();
		if ($interval <= 0 || $this->getTimestamp($row['VERSION_KEY']) !== $versionKey->getTimestamp())
		{
			return false;
		}

		$updatedAt = $this->getTimestamp($row['UPDATED_AT']);

		return $updatedAt <= $now && $updatedAt + $interval > $now;
	}

	/**
	 * @param array<string, Dto\CapturedValue> $values
	 */
	private function encodeValues(array $values): string
	{
		$limit = $this->config->getSnapshotSizeLimit();
		$json = Json::encode($values);

		return strlen($json) > $limit ? $this->getCollector()->shrink($values, $limit) : $json;
	}

	/**
	 * The snapshot is written at the moment of the completion, so both stamps hold the same write time:
	 * COMPLETED_AT is the base of the retention period, UPDATED_AT the base of the throttling.
	 *
	 * @return array<string, mixed> column => value, without the primary key
	 */
	private function buildSnapshotFields(
		\CBPActivity $rootActivity,
		array $documentId,
		DateTime $versionKey,
		int $status,
		string $values,
	): array
	{
		$now = new DateTime();

		return [
			'VERSION_KEY' => $versionKey,
			'WORKFLOW_ID' => (string)$rootActivity->getWorkflowInstanceId(),
			'MODULE_ID' => (string)$documentId[0],
			'ENTITY' => (string)$documentId[1],
			'DOCUMENT_ID' => (string)$documentId[2],
			'STATUS' => $status,
			'COMPLETED_AT' => $now,
			'UPDATED_AT' => $now,
			'VALUES_DATA' => $values,
		];
	}

	/**
	 * Final write of a reserved capture. The version condition is repeated here because the tree is walked
	 * outside the reservation: another process may have stored a snapshot of a newer template version
	 * meanwhile, and that snapshot wins. No row is updated then - a lost race is a regular outcome of the
	 * capture, not a failure.
	 *
	 * @param array<string, mixed> $fields
	 */
	private function rewriteSnapshot(int $templateId, DateTime $versionKey, array $fields): void
	{
		$connection = Application::getConnection();
		$sqlHelper = $connection->getSqlHelper();
		$tableName = WorkflowLastValuesTable::getTableName();

		[$update, $binds] = $sqlHelper->prepareUpdate($tableName, $fields);

		$sql = 'UPDATE ' . $sqlHelper->quote($tableName)
			. ' SET ' . $update
			. ' WHERE ' . $sqlHelper->quote('TEMPLATE_ID') . ' = ' . $templateId
			. ' AND ' . $sqlHelper->quote('VERSION_KEY') . ' <= '
				. $sqlHelper->convertToDbDateTime($versionKey)
		;

		$connection->queryExecute($sql, $binds);
	}

	/**
	 * The very first snapshot of a template: there is no row to reserve and no stored version to lose a
	 * race to, and the merge keeps parallel completions from fighting over the primary key.
	 *
	 * @param array<string, mixed> $fields
	 */
	private function merge(int $templateId, array $fields): void
	{
		$connection = Application::getConnection();
		$sqlHelper = $connection->getSqlHelper();

		$queries = $sqlHelper->prepareMerge(
			WorkflowLastValuesTable::getTableName(),
			['TEMPLATE_ID'],
			['TEMPLATE_ID' => $templateId] + $fields,
			$fields,
		);

		foreach ($queries as $query)
		{
			if ($query !== '')
			{
				$connection->queryExecute($query);
			}
		}
	}

	private function getCollector(): ValuesCollector
	{
		return $this->collector ??= new ValuesCollector($this->config);
	}

	/**
	 * Version key of a template worth capturing, null when there is nothing to capture for: the template
	 * is already deleted, or it is a system one (non-empty SYSTEM_CODE), and the editor never resolves such
	 * a template, so its snapshot would be stored without a reader.
	 */
	private function getTemplateVersion(int $templateId): ?DateTime
	{
		if (!array_key_exists($templateId, self::$templateVersions))
		{
			if (count(self::$templateVersions) >= self::TEMPLATE_VERSIONS_LIMIT)
			{
				self::$templateVersions = [];
			}

			$template = WorkflowTemplateTable::query()
				->setSelect(['MODIFIED', 'SYSTEM_CODE'])
				->where('ID', $templateId)
				->setLimit(1)
				->fetch() ?: []
			;

			$modified = $template['MODIFIED'] ?? null;
			$isSystemTemplate = (string)($template['SYSTEM_CODE'] ?? '') !== '';

			self::$templateVersions[$templateId] = !$isSystemTemplate && $modified instanceof DateTime
				? $modified
				: null
			;
		}

		return self::$templateVersions[$templateId];
	}

	private function getInstanceStartTime(\CBPActivity $rootActivity): ?int
	{
		$instanceId = (string)$rootActivity->getWorkflowInstanceId();
		if ($instanceId === '')
		{
			return null;
		}

		$started = WorkflowInstanceTable::query()
			->setSelect(['STARTED'])
			->where('ID', $instanceId)
			->setLimit(1)
			->fetch()['STARTED'] ?? null
		;

		return $started instanceof DateTime ? $started->getTimestamp() : null;
	}

	private function getTimestamp(mixed $value): int
	{
		return $value instanceof DateTime ? $value->getTimestamp() : 0;
	}

	/**
	 * Null while the logger is switched off in the registry: there is nothing to write the diagnostics to,
	 * so the capture skips it instead of feeding a NullLogger.
	 */
	private function getLogger(): ?LoggerInterface
	{
		return $this->logger ??= (new LoggerFactory(alwaysReturnLogger: false))->createById(self::LOGGER_ID);
	}
}

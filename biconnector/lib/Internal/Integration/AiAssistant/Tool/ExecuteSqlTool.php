<?php

namespace Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Integration\Superset\Integrator\IntegratorFactory;
use Bitrix\Main\Loader;

final class ExecuteSqlTool extends BaseBiTool
{
	private const DEFAULT_LIMIT = 1000;
	private const MAX_LIMIT = 10000;
	private const MAX_SQL_LENGTH = 50000;

	public function getName(): string
	{
		return 'execute_sql';
	}

	public function getDescription(): string
	{
		return 'Executes a read-only SQL SELECT query against the BI database through '
			. 'Superset SQL Lab and returns the result rows and column metadata. '
			. 'Use this for ad-hoc analytical queries when you need the data itself '
			. '(e.g. "top 10 managers by sum of closed deals") rather than creating a '
			. 'dataset or chart. Only SELECT statements are allowed; the query runs within '
			. 'standard Superset limits (timeout and row cap). '
			. 'Workflow: 1) call list_bi_tables / get_bi_table_schema to learn the real '
			. 'table and column names, 2) call this tool with a full SELECT statement. '
			. 'Reference tables as "bitrix24"."TABLE_NAME". '
			. 'Returns `columns` (name, type), `rows`, `rowCount` and the applied `limit`.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'sql' => [
					'type' => 'string',
					'minLength' => 1,
					'description' => 'Raw SQL SELECT statement to execute. '
						. 'Must be a single read-only SELECT valid for the Trino BI database.',
				],
				'limit' => [
					'type' => 'integer',
					'minimum' => 1,
					'maximum' => self::MAX_LIMIT,
					'description' => 'Maximum number of rows to return. Default ' . self::DEFAULT_LIMIT
						. ', maximum ' . self::MAX_LIMIT . '. Values over max are clamped.',
				],
			],
			'required' => ['sql'],
			'additionalProperties' => false,
		];
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		Loader::includeModule('biconnector');

		if (!$this->getAccessController($userId)->check(ActionDictionary::ACTION_BIC_DASHBOARD_EDIT))
		{
			throw new McpException('Access denied: report editing permission (ACTION_BIC_DASHBOARD_EDIT) is required to run raw SQL.');
		}

		// Raw read bypasses the Proactive Filter (WAF), which otherwise mangles
		// SQL keywords (SELECT -> SEL ECT, FROM -> FR OM) in the parsed request.
		$sql = trim((string)$this->getRawArgument('sql', $args));
		if ($sql === '')
		{
			throw new McpException('sql is required: provide the SQL SELECT query to execute.');
		}
		if (mb_strlen($sql) > self::MAX_SQL_LENGTH)
		{
			throw new McpException(
				'sql is too long: the query must be at most ' . self::MAX_SQL_LENGTH
				. ' characters. Simplify the query or split it into smaller ones.'
			);
		}

		$limit = null;
		if (isset($args['limit']) && is_numeric($args['limit']))
		{
			$limit = max(1, min(self::MAX_LIMIT, (int)$args['limit']));
		}

		$response = IntegratorFactory::getInstance()->executeSql($sql, $limit);
		if ($response->hasErrors())
		{
			throw self::sqlLabException(
				$response->getErrors(),
				['stage' => 'execute_sql', 'user_id' => $userId],
			);
		}

		$data = $response->getData();
		if (!is_array($data))
		{
			$data = [];
		}

		return [
			'columns' => is_array($data['columns'] ?? null) ? $data['columns'] : [],
			'rows' => is_array($data['rows'] ?? null) ? $data['rows'] : [],
			'rowCount' => (int)($data['rowCount'] ?? 0),
			'limit' => (int)($data['limit'] ?? ($limit ?? self::DEFAULT_LIMIT)),
		];
	}
}

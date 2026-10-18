<?php

namespace Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Configuration\Feature;
use Bitrix\BIConnector\Integration\Superset\Integrator\IntegratorFactory;
use Bitrix\Main\Loader;

final class GetDatasetTool extends BaseBiTool
{
	public function getName(): string
	{
		return 'get_dataset';
	}

	public function getDescription(): string
	{
		return 'Returns the full definition of a single Superset dataset by its `id`. '
			. 'Use list_datasets first to discover ids. '
			. 'The response contains `id`, `table_name`, optional `description`, '
			. 'and the list of `columns` (each with `name`, `type`, optional '
			. '`verbose_name`, `description` and `is_dttm`). '
			. 'Read-only: this tool never modifies the dataset.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'datasetId' => [
					'type' => 'integer',
					'description' => 'Dataset id to read (from list_datasets). Positive integer.',
					'minimum' => 1,
				],
			],
			'required' => ['datasetId'],
			'additionalProperties' => false,
		];
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		Loader::includeModule('biconnector');

		if (!Feature::isBuilderEnabled())
		{
			throw new McpException('BI Builder feature is not enabled on this portal.');
		}

		if (!$this->getAccessController($userId)->check(ActionDictionary::ACTION_BIC_ACCESS))
		{
			throw new McpException('Access denied: ACTION_BIC_ACCESS is required.');
		}

		$datasetId = (int)($args['datasetId'] ?? 0);
		if ($datasetId <= 0)
		{
			throw new McpException('datasetId is required and must be a positive integer.');
		}

		$response = IntegratorFactory::getInstance()->getDatasetById($datasetId);
		if ($response->hasErrors())
		{
			throw self::unavailableBiException(
				$response->getErrors(),
				['stage' => 'get_dataset', 'user_id' => $userId, 'dataset_id' => $datasetId],
			);
		}

		$data = $response->getData();
		if (!is_array($data) || (int)($data['id'] ?? 0) <= 0)
		{
			throw new McpException(
				"Dataset with id={$datasetId} was not found. Call list_datasets to get a valid id."
			);
		}

		$columns = [];
		foreach (is_array($data['columns'] ?? null) ? $data['columns'] : [] as $column)
		{
			if (!is_array($column))
			{
				continue;
			}

			$entry = [
				'name' => (string)($column['column_name'] ?? ''),
				'type' => (string)($column['type'] ?? ''),
				'is_dttm' => (bool)($column['is_dttm'] ?? false),
			];

			if (isset($column['verbose_name']) && $column['verbose_name'] !== '' && $column['verbose_name'] !== null)
			{
				$entry['verbose_name'] = (string)$column['verbose_name'];
			}

			if (isset($column['description']) && $column['description'] !== '' && $column['description'] !== null)
			{
				$entry['description'] = (string)$column['description'];
			}

			$columns[] = $entry;
		}

		$sql = (string)($data['sql'] ?? '');

		return [
			'id' => (int)($data['id'] ?? 0),
			'table_name' => (string)($data['table_name'] ?? ''),
			'description' => (string)($data['description'] ?? ''),
			'is_virtual' => $sql !== '',
			'sql' => $sql,
			'columns' => $columns,
		];
	}
}

<?php

namespace Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Configuration\Feature;
use Bitrix\BIConnector\Integration\Superset\Integrator\IntegratorFactory;
use Bitrix\BIConnector\Integration\Superset\Integrator\Request\IntegratorResponse;
use Bitrix\Main\Loader;

final class DeleteDatasetTool extends BaseBiTool
{
	public function getName(): string
	{
		return 'delete_dataset';
	}

	public function getDescription(): string
	{
		return 'Permanently deletes a dataset from BI Constructor (Apache Superset). '
			. 'DESTRUCTIVE and irreversible. Any charts built on top of this dataset '
			. 'will break — delete or repoint them first. '
			. 'TWO-STEP CONFIRMATION (MANDATORY): the first call (omit `confirm`, or pass '
			. '`confirm=false`) returns a preview (dataset name) and changes nothing. Relay it '
			. 'to the user, get EXPLICIT approval, then call again with `confirm=true`. Never '
			. 'set `confirm=true` on your own, never chain the two calls, never infer approval '
			. 'from earlier conversation. '
			. 'Returns the preview on the first call, and `{deleted:true, id, name}` on '
			. 'the confirmed call. Idempotent: deleting an already-deleted dataset is a safe '
			. 'no-op and returns `{deleted:true, already_deleted:true}`.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'datasetId' => [
					'type' => 'integer',
					'description' => 'Dataset id to delete (from list_datasets). Positive integer.',
					'minimum' => 1,
				],
				'confirm' => [
					'type' => 'boolean',
					'description' => 'Set to true ONLY after the user has explicitly approved '
						. 'this previewed deletion. Omit or set false to only preview the impact. '
						. 'Do NOT self-confirm or assume approval from earlier context.',
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

		$datasetId = (int)($args['datasetId'] ?? 0);
		if ($datasetId <= 0)
		{
			throw new McpException('datasetId is required and must be a positive integer.');
		}

		$accessController = $this->getAccessController($userId);
		if (!$accessController->check(ActionDictionary::ACTION_BIC_ACCESS))
		{
			throw new McpException('Access denied: ACTION_BIC_ACCESS is required.');
		}
		if (!$accessController->check(ActionDictionary::ACTION_BIC_DASHBOARD_EDIT))
		{
			throw new McpException('Access denied: ACTION_BIC_DASHBOARD_EDIT is required to delete datasets.');
		}

		$integrator = IntegratorFactory::getInstance();

		// Point-load the dataset (not a full list walk) to resolve its name and
		// owners for the object-level scope check and to confirm it exists before
		// a destructive call.
		$getResponse = $integrator->getDatasetById($datasetId);
		if ($getResponse->hasErrors())
		{
			// Idempotent: the dataset is already gone (retried/second delete).
			// Report success instead of a confusing "BI unavailable" error —
			// mirrors delete_chart / delete_dashboard.
			if ($getResponse->getStatus() === IntegratorResponse::STATUS_NOT_FOUND)
			{
				return [
					'deleted' => true,
					'id' => $datasetId,
					'name' => '',
					'already_deleted' => true,
				];
			}

			// A real service failure (not a genuinely absent dataset).
			throw self::unavailableBiException(
				$getResponse->getErrors(),
				['stage' => 'delete_dataset.load', 'user_id' => $userId, 'dataset_id' => $datasetId],
			);
		}

		$current = $getResponse->getData();
		if (!is_array($current) || (int)($current['id'] ?? 0) <= 0)
		{
			// Genuinely absent (or never existed) — same idempotent no-op.
			return [
				'deleted' => true,
				'id' => $datasetId,
				'name' => '',
				'already_deleted' => true,
			];
		}

		$name = (string)($current['table_name'] ?? '');

		// Object-level scope: only an owner of the dataset may delete it.
		$this->assertDatasetOwned($userId, is_array($current['owners'] ?? null) ? $current['owners'] : []);

		$confirm = ($args['confirm'] ?? false) === true;
		if (!$confirm)
		{
			return [
				'requiresConfirmation' => true,
				'action' => 'delete_dataset',
				'id' => $datasetId,
				'name' => $name,
				'impact' => 'The dataset will be permanently deleted. Any charts built on '
					. 'it will break. This cannot be undone.',
				'confirmHint' => 'Show this preview to the user; only AFTER they explicitly approve, call delete_dataset again with the same datasetId and confirm=true.',
			];
		}

		$response = $integrator->deleteDataset($datasetId);
		if ($response->hasErrors())
		{
			// Race: deleted between preview and confirm. Treat 404 as success so a
			// retried confirm does not surface a false error.
			if ($response->getStatus() === IntegratorResponse::STATUS_NOT_FOUND)
			{
				return [
					'deleted' => true,
					'id' => $datasetId,
					'name' => $name,
					'already_deleted' => true,
				];
			}

			throw self::unavailableBiException(
				$response->getErrors(),
				['stage' => 'delete_dataset', 'user_id' => $userId, 'dataset_id' => $datasetId],
			);
		}

		return [
			'deleted' => true,
			'id' => $datasetId,
			'name' => $name,
		];
	}
}

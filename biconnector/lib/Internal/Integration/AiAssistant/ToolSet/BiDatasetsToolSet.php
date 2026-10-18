<?php

namespace Bitrix\BIConnector\Internal\Integration\AiAssistant\ToolSet;

use Bitrix\AiAssistant\Definition\Dto\DefinitionMetadataDto;
use Bitrix\AiAssistant\Definition\Dto\UsesToolsDto;
use Bitrix\AiAssistant\Definition\ToolSet\BaseToolSet;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool\CreateDatasetFromSqlTool;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool\CreateVirtualDatasetTool;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool\DeleteDatasetTool;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool\GetDatasetTool;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool\ListDatasetsTool;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool\UpdateDatasetTool;

final class BiDatasetsToolSet extends BaseToolSet
{
	public function getCode(): string
	{
		return 'bi_datasets';
	}

	public function getMetadata(): DefinitionMetadataDto
	{
		return new DefinitionMetadataDto(
			'BI Datasets',
			'Tools for managing virtual datasets in BI Constructor (Apache Superset). '
			. 'List and inspect existing datasets, create new virtual datasets either by '
			. 'projecting columns from a physical BI table or from a raw SQL SELECT, update '
			. 'a dataset\'s projected columns, and delete datasets. Deleting a dataset is '
			. 'destructive (dependent charts may break) and requires explicit two-step user '
			. 'confirmation.',
		);
	}

	public function getUsesTools(): UsesToolsDto
	{
		return new UsesToolsDto([
			ListDatasetsTool::class,
			GetDatasetTool::class,
			CreateVirtualDatasetTool::class,
			CreateDatasetFromSqlTool::class,
			UpdateDatasetTool::class,
			DeleteDatasetTool::class,
		]);
	}

	public function getAdditionalRequiredModules(): array
	{
		return ['biconnector'];
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Controller;

use Bitrix\Bizproc\Internal\Service\Container;
use Bitrix\Bizproc\Public\Activity\ActivityControlsBuilder;
use Bitrix\Bizproc\Public\Activity\Interface\NodeFilterMetadataProvider;
use Bitrix\BizprocDesigner\Internal\Trait\ActivitySettingsDecoder;
use Bitrix\BizprocDesigner\Public\Command;
use Bitrix\BizprocDesigner\Public\Service\Activity\TriggerUpgradeResolver;
use Bitrix\Main\Engine\JsonController;
use Bitrix\Main\Loader;
use Bitrix\Bizproc\Api\Enum\ErrorMessage;

class Activity extends JsonController
{
	use ActivitySettingsDecoder;

	protected function init()
	{
		parent::init();
		Loader::requireModule('bizproc');
	}

	public function getNodeFilterMetadataAction(
		string $activityType,
		array $documentType = [],
		bool $onlyDynamicEntities = false,
		bool $includeRelatedEntityTypes = false,
	): ?array
	{
		$user = new \CBPWorkflowTemplateUser(\CBPWorkflowTemplateUser::CurrentUser);
		$canWrite = \CBPDocument::CanUserOperateDocumentType(
			\CBPCanUserOperateOperation::CreateWorkflow,
			$user->getId(),
			$documentType,
		);
		if (!$canWrite)
		{
			$this->errorCollection->setError(ErrorMessage::ACCESS_DENIED->getError());

			return null;
		}

		if (!Container::instance()->getActivitySearcherService()->includeActivityFile($activityType))
		{
			$this->errorCollection->setError(ErrorMessage::ACTIVITY_NOT_FOUND->getError());

			return null;
		}

		$className = 'CBP' . $activityType;
		if (!is_a($className, NodeFilterMetadataProvider::class, true))
		{
			$this->errorCollection->setError(ErrorMessage::ACTIVITY_NOT_FOUND->getError());

			return null;
		}

		$metadata = $className::getNodeFilterMetadata($documentType, $onlyDynamicEntities, $includeRelatedEntityTypes);

		$documentFields = [];
		$documentName = '';
		if (count($documentType) >= 2)
		{
			$documentFields = array_values(\Bitrix\Bizproc\Automation\Helper::getDocumentFields($documentType));
			$documentName = (string)\CBPRuntime::getRuntime()
				->getDocumentService()
				->getEntityName((string)$documentType[0], (string)$documentType[1])
			;
		}

		return [
			'entityTypeOptions' => $metadata['entityTypeOptions'] ?? [],
			'filterFieldsMap' => $metadata['filterFieldsMap'] ?? [],
			'documentTypeMap' => $metadata['documentTypeMap'] ?? [],
			'documentFields' => $documentFields,
			'documentName' => $documentName,
		];
	}

	public function getSettingsControlsAction(
		array $documentType,
		array $activity,
		array $workflow = [],
		array $options = [],
	): ?array
	{
		// Same object authorization as the sibling actions: the client-supplied documentType reaches
		// getPropertiesMap(), whose controls expose document metadata (categories, stages) in Options.
		$user = new \CBPWorkflowTemplateUser(\CBPWorkflowTemplateUser::CurrentUser);
		$canWrite = \CBPDocument::CanUserOperateDocumentType(
			\CBPCanUserOperateOperation::CreateWorkflow,
			$user->getId(),
			$documentType,
		);
		if (!$canWrite)
		{
			$this->errorCollection->setError(ErrorMessage::ACCESS_DENIED->getError());

			return null;
		}

		$brokenLinks = [];
		$activityName = $activity['Name'] ?? '';
		$hideEditorComment = (bool)($options['hideEditorComment'] ?? false);
		[
			'template' => $workflowTemplate,
			'parameters' => $workflowParameters,
			'variables' => $workflowVariables,
			'constants' => $workflowConstants,
		] = $this->decodeActivitySettings($workflow, $documentType);

		$activity = $this->applyTriggerUpgrade($activity, $documentType);

		$description = Container::instance()->getActivitySearcherService()->searchByCode($activity['Type']);
		$result = [
			'brokenLinks' => $brokenLinks,
			'controls' => null,
			'useDocumentContext' => $description?->getFilter() !== null,
		];
		$configurator = \CBPActivity::createConfigurator(
			$activity['Type'],
			$activity['Properties'],
			$documentType,
		);


		if (!$configurator->getActivityType())
		{
			return $result;
		}

		if ($activityName && $workflowTemplate)
		{
			$analyzer =
				(new \Bitrix\Bizproc\Public\Service\Template\ActivityUsageAnalyzer($workflowTemplate))
					->setParameters($workflowParameters)
					->setVariables($workflowVariables)
					->setConstants($workflowConstants)
					->setGlobalConstants(\Bitrix\Bizproc\Workflow\Type\GlobalConst::getAll($documentType))
					->setGlobalVariables(\Bitrix\Bizproc\Workflow\Type\GlobalVar::getAll($documentType))
					->setDocumentFields(\Bitrix\Bizproc\Automation\Helper::getDocumentFields($documentType))
			;
			$brokenLinks = $analyzer->analyzeUsages($activityName);
		}

		$controlsBuilder = new ActivityControlsBuilder($configurator, $activity);
		$controls = $controlsBuilder->build();

		if ($hideEditorComment)
		{
			$controls = array_values(array_filter(
				$controls,
				static fn($control) => ($control->toArray()['property']['FieldName'] ?? '') !== 'activity_editor_comment',
			));
		}

		$result['controls'] = $controls;
		$result['brokenLinks'] = $brokenLinks;

		return $result;
	}

	public function saveSettingsAction(): ?array
	{
		$user = new \CBPWorkflowTemplateUser(\CBPWorkflowTemplateUser::CurrentUser);

		$json = $this->getRequest()->getJsonList();
		$currentRequest = $json->toArray();
		$documentType = (array)$currentRequest['documentType'];
		$canWrite = \CBPDocument::CanUserOperateDocumentType(
			\CBPCanUserOperateOperation::CreateWorkflow,
			$user->getId(),
			$documentType
		);

		if (!$canWrite)
		{
			$this->errorCollection->setError(ErrorMessage::ACCESS_DENIED->getError());

			return null;
		}

		$activityName = (string)($currentRequest['id'] ?? $currentRequest['activity_id'] ?? '');
		$isActivated = $currentRequest['activated'] ?? 'Y';

		[
			'template' => $workflowTemplate,
			'parameters' => $workflowParameters,
			'variables' => $workflowVariables,
			'constants' => $workflowConstants,
			'properties' => $activityProperties,
		] = $this->decodeActivitySettings($currentRequest, $documentType);

		/** @var Command\Activity\Settings\SaveCommandResult $result */
		$result =
			(new Command\Activity\Settings\SaveCommand(
				new Command\Activity\Settings\SaveCommandDto(
					activity: new Command\Activity\Settings\SaveCommandActivityDto(
						type: (string)($currentRequest['activityType'] ?? ''),
						name: $activityName,
						properties: $activityProperties,
						title: \CBPHelper::stringify($activityProperties['title'] ?? ''),
						editorComment: \CBPHelper::stringify($currentRequest['activity_editor_comment'] ?? ''),
						isActivated: ($isActivated === 'Y'),
					),
					documentType: $documentType,
					template: $workflowTemplate,
					variables: $workflowVariables,
					parameters: $workflowParameters,
					constants: $workflowConstants,
				)
			))
				->run()
		;

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $result->getSettings()?->toArray();
	}

	/**
	 * The settings form of a node saved with a deprecated trigger class is built from the class that
	 * replaces it, otherwise the owner of such a node would not see the controls the new class added and
	 * could only re-save it blindly. Nothing is written into the template here: the type of the node changes
	 * on save (see {@see \Bitrix\BizprocDesigner\Public\Command\Activity\Settings\SaveCommandHandler}).
	 */
	private function applyTriggerUpgrade(array $activity, array $documentType): array
	{
		$properties = is_array($activity['Properties'] ?? null) ? $activity['Properties'] : [];

		$upgradeResolver = new TriggerUpgradeResolver();
		$upgrade = $upgradeResolver->resolveUpgradedType(
			(string)($activity['Type'] ?? ''),
			$upgradeResolver->resolveNodeDocumentType([$properties['Document'] ?? null], $documentType),
		);

		$activity['Type'] = $upgrade['type'];
		$activity['Properties'] = $upgradeResolver->applyUpgradeProperties($properties, $upgrade['properties']);

		return $activity;
	}
}

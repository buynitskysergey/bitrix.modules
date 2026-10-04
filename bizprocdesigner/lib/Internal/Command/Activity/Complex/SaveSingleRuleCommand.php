<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Command\Activity\Complex;

use Bitrix\Bizproc\Api\Enum\ErrorMessage;
use Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\PortRuleDto;
use Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\Rule\ActionExpressionDto;
use Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\Rule\BaseSettingsExpressionDto;
use Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\Rule\ConditionExpressionDto;
use Bitrix\BizprocDesigner\Infrastructure\Enum\ConstructionType;
use Bitrix\BizprocDesigner\Internal\Command\AbstractCommand;

use Bitrix\BizprocDesigner\Internal\Trait\ActivitySettingsDecoder;
use Bitrix\BizprocDesigner\Internal\Trait\ConditionValueResolver;
use Bitrix\BizprocDesigner\Internal\Trait\NodeActionResolver;
use Bitrix\BizprocDesigner\Public\Command\Activity;
use Bitrix\BizprocDesigner\Public\Service\Activity\TriggerUpgradeResolver;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\Main\Web\Json;

class SaveSingleRuleCommand extends AbstractCommand
{
	use ActivitySettingsDecoder;
	use ConditionValueResolver;
	use NodeActionResolver;

	public function __construct(
		public readonly PortRuleDto $portRuleDto,
		public array $documentType,
	)
	{
	}

	protected function execute(): Result
	{
		$resultPortRule = clone $this->portRuleDto;

		$result = $this->processActionExpressions($resultPortRule);
		if (!$result->isSuccess())
		{
			return $result;
		}

		$result = $this->processBaseSettingsExpressions($resultPortRule);
		if (!$result->isSuccess())
		{
			return $result;
		}

		$result = $this->processConditionExpressions($resultPortRule);
		if (!$result->isSuccess())
		{
			return $result;
		}

		return new SaveSingleRuleCommandResult($resultPortRule);
	}

	private function processActionExpressions(PortRuleDto $resultPortRule): Result
	{
		$actionExpressionList = $this->extractActionExpressionList($resultPortRule);

		foreach ($actionExpressionList as $actionExpression)
		{
			if (empty($actionExpression->rawActivityData))
			{
				continue;
			}

			// Node-action-backed ACTION: internalise field values through the backing node-action's
			// getPropertiesMap (FieldType::extractValue) instead of getActivitySettings, mirroring the
			// BASE_SETTINGS path. This targets legacy dual activities (robot + node-action, e.g.
			// CBPIMNotifyActivity): as an ACTION they render the unified getPropertiesMap form
			// (FieldName message_user_from), but getActivitySettings() routes through their legacy
			// getPropertiesDialogValues, which reads legacy dialog keys (from_user_id) and never sees
			// those FieldNames — so every value is dropped and the raw printable string reaches the USER
			// validator. Legacy-dialog node-actions (CBPSetFieldActivity, CBPCrmCreateToDoActivity) do
			// not implement IBPConfigurableActivity, so internalizeNodeActionProperties() resolves an
			// empty propertiesMap and returns null → the getActivitySettings() path below is unchanged.
			$nodeActionResult = $this->extractNodeActionAction($actionExpression);
			if ($nodeActionResult !== null)
			{
				if (!$nodeActionResult->isSuccess())
				{
					return $nodeActionResult;
				}

				continue;
			}

			$saveActivityResult = $this->getActivitySettings($actionExpression);
			if (
				!$saveActivityResult instanceof Activity\Settings\SaveCommandResult
				|| !$saveActivityResult->isSuccess()
			)
			{
				return $saveActivityResult;
			}

			$actionExpression->rawActivityData = null;
			$activityData = $saveActivityResult->getSettings()?->toArray() ?? [];
			if (!empty($activityData))
			{
				$activityData['Document'] = $actionExpression->document;
			}

			$actionExpression->activityData = $activityData;
		}

		return new Result();
	}

	/**
	 * @param PortRuleDto $portRuleDto
	 * @return list<ActionExpressionDto>
	 */
	private function extractActionExpressionList(PortRuleDto $portRuleDto): array
	{
		$actionExpressionList = [];

		$rules = $portRuleDto->rules;
		foreach ($rules as $rule)
		{
			foreach ($rule->constructions as $construction)
			{
				if (
					$construction->constructionType !== ConstructionType::ACTION
					&& $construction->constructionType !== ConstructionType::FILTER
				)
				{
					continue;
				}

				$expression = $construction->expression;

				if (!$expression instanceof ActionExpressionDto)
				{
					continue;
				}

				$actionExpressionList[] = $expression;
			}
		}

		return $actionExpressionList;
	}

	/**
	 * Normalise a node-action-backed ACTION construction: rawActivityData → activityData, internalising
	 * field values through the backing node-action's getPropertiesMap (see internalizeNodeActionProperties).
	 * Only node-action backing types are considered; among those, non-unified activities (legacy dialog,
	 * empty propertiesMap) fall back to the shared getActivitySettings() pipeline. Returns:
	 *   - null           not a unified node-action ACTION → caller falls back to getActivitySettings
	 *                    (modern / legacy-dialog path unchanged);
	 *   - Result(errors) extraction/validation failed;
	 *   - Result(ok)     activityData populated, rawActivityData cleared.
	 */
	private function extractNodeActionAction(ActionExpressionDto $actionExpression): ?Result
	{
		$rawActivityData = $actionExpression->rawActivityData;
		$activityType = (string)($rawActivityData['activityType'] ?? '');

		if (!$this->isNodeActionBackingType($activityType))
		{
			return null;
		}

		$internalizeResult = $this->internalizeNodeActionProperties($activityType, $rawActivityData);
		if ($internalizeResult === null)
		{
			return null;
		}

		if (!$internalizeResult->isSuccess())
		{
			return $internalizeResult;
		}

		[
			'properties' => $properties,
			'activated' => $isActivated,
			'title' => $title,
		] = $internalizeResult->getData();

		$actionExpression->rawActivityData = null;
		$actionExpression->activityData = [
			'Name' => (string)($rawActivityData['id'] ?? ''),
			'Type' => $activityType,
			'Activated' => $isActivated ? 'Y' : 'N',
			'Properties' => array_merge($properties, ['Title' => $title]),
			'Document' => $actionExpression->document,
		];

		return new Result();
	}

	/**
	 * Runs the shared activity save pipeline for one expression. A success always carries
	 * Activity\Settings\SaveCommandResult, so the callers narrow the result to it before they read the
	 * settings; every other outcome - the rejected nested document type included - is a failed Result they
	 * propagate as is.
	 */
	private function getActivitySettings(ActionExpressionDto $actionExpression): Result
	{
		$documentTypeResult = $this->extractDocumentType($actionExpression->rawActivityData);
		if (!$documentTypeResult->isSuccess())
		{
			return $documentTypeResult;
		}

		$documentType = $documentTypeResult->getData()['documentType'] ?? $this->documentType;
		$rawActivityData = $this->modifyRawActivityDataTemplate(
			$this->replaceNestedDocumentType($actionExpression->rawActivityData, $documentType),
		);

		$activityName = (string)$rawActivityData['id'];
		$isActivated = $rawActivityData['activated'] ?? 'Y';

		[
			'template' => $workflowTemplate,
			'parameters' => $workflowParameters,
			'variables' => $workflowVariables,
			'constants' => $workflowConstants,
			'properties' => $activityProperties,
		] = $this->decodeActivitySettings($rawActivityData, $documentType);

		$result =
			(new Activity\Settings\SaveCommand(
				new Activity\Settings\SaveCommandDto(
					activity: new Activity\Settings\SaveCommandActivityDto(
						type: (string)($rawActivityData['activityType'] ?? ''),
						name: $activityName,
						properties: $activityProperties,
						title: $activityProperties['title'] ?? '',
						isActivated: $isActivated === 'Y',
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

		// run() is typed as Result: a command may answer from beforeRun() without its own result type. Such an
		// answer carries no settings to normalise the expression with, so it is refused instead of being
		// counted as a save.
		if ($result->isSuccess() && !$result instanceof Activity\Settings\SaveCommandResult)
		{
			return (new Result())->addError(ErrorMessage::UNKNOWN_ERROR->getCodedError());
		}

		return $result;
	}

	/**
	 * Normalise BASE_SETTINGS constructions: rawActivityData → activityData.Properties.
	 * Mirrors processActionExpressions() so that ConvertRuleCommand::mergeBaseSettingsProperties()
	 * can read activityData.Properties after SaveSingleRule completes.
	 */
	private function processBaseSettingsExpressions(PortRuleDto $resultPortRule): Result
	{
		Loader::requireModule('bizproc');

		foreach ($this->extractBaseSettingsExpressionList($resultPortRule) as $expression)
		{
			if (empty($expression->rawActivityData))
			{
				continue;
			}

			$triggerTypeResult = $this->applyNodeTriggerType($expression);
			if (!$triggerTypeResult->isSuccess())
			{
				return $triggerTypeResult;
			}

			// Node-action-backed base-settings: extract field values through the node-action's
			// getPropertiesMap (FieldType::extractValue), internalising printable USER/entity values
			// ("Admin Adminov [1]" -> "user_1") exactly like ACTION constructions do. This must NOT go
			// through the shared getActivitySettings() pipeline: a legacy dual activity (robot +
			// node-action, e.g. IMNotifyActivity) extracts via its own getPropertiesDialogValues, which
			// reads legacy dialog field names and never sees the getPropertiesMap FieldNames that the
			// base-settings form submits — so every field value is dropped and the raw printable string
			// reaches the USER validator ("Свойство '…' указано не корректно").
			$nodeActionResult = $this->extractNodeActionBaseSettings($expression);
			if ($nodeActionResult !== null)
			{
				if (!$nodeActionResult->isSuccess())
				{
					return $nodeActionResult;
				}

				continue;
			}

			// Guard: an empty id makes modifyRawActivityDataTemplate inject an empty-string key
			// into the workflow template, so findActivityByName returns null and all field values
			// are silently lost. Skip the getActivitySettings pipeline; the existing rawActivityData
			// (if any) remains as-is, which is the safe fallback for a malformed payload.
			if ((string)($expression->rawActivityData['id'] ?? '') === '')
			{
				continue;
			}

			$saveActivityResult = $this->getActivitySettings(
				$this->makeActionExpressionForBaseSettings($expression),
			);

			if (
				!$saveActivityResult instanceof Activity\Settings\SaveCommandResult
				|| !$saveActivityResult->isSuccess()
			)
			{
				return $saveActivityResult;
			}

			$expression->rawActivityData = null;
			$activityData = $saveActivityResult->getSettings()?->toArray() ?? [];
			if (!empty($activityData))
			{
				$activityData['Document'] = null;
			}

			$expression->activityData = $activityData;
		}

		return new Result();
	}

	/**
	 * The class of the node is not chosen in the editor: it either stays as it is or is replaced by the
	 * upgrade the owner module declares ({@see TriggerUpgradeResolver}). The replacement itself belongs to the
	 * shared save handler further down the pipeline
	 * ({@see \Bitrix\BizprocDesigner\Public\Command\Activity\Settings\SaveCommandHandler}), so the class the
	 * node carries is what is handed to it - the submitted one is only ever checked against it.
	 *
	 * The handler cannot answer for this path on its own: the template it reads the current class from is
	 * rebuilt here from the submitted one ({@see self::modifyRawActivityDataTemplate()}), so a class made up by
	 * the client would reach it as the class of the node. Both spellings the editor legitimately sends - the
	 * class of the node and its upgrade target, since the form of a deprecated node is built from the latter -
	 * are accepted, and anything else is refused with the code the ordinary settings path answers with, so the
	 * unified panel is not a way around it.
	 *
	 * A node-action proxy construction is left alone: it becomes a child activity of its own, and its class is
	 * the sub-action the user picked, not the class of the node.
	 */
	private function applyNodeTriggerType(BaseSettingsExpressionDto $expression): Result
	{
		if ($expression->actionId !== null)
		{
			return new Result();
		}

		$sourceType = $this->resolveNodeActivityType($expression);
		if ($sourceType === '')
		{
			return new Result();
		}

		$rawActivityData = $expression->rawActivityData ?? [];
		$savedActivityData = $expression->activityData ?? [];

		$documentTypeResult = $this->extractDocumentType($rawActivityData);
		if (!$documentTypeResult->isSuccess())
		{
			return $documentTypeResult;
		}

		$resolver = new TriggerUpgradeResolver();
		$upgrade = $resolver->resolveUpgradedType(
			$sourceType,
			$resolver->resolveNodeDocumentType(
				[
					$rawActivityData['Document'] ?? null,
					$savedActivityData['Properties']['Document'] ?? null,
				],
				$documentTypeResult->getData()['documentType'] ?? $this->documentType,
			),
		);

		$submittedType = (string)($rawActivityData['activityType'] ?? '');
		if (
			!$resolver->isSameType($submittedType, $sourceType)
			&& !$resolver->isSameType($submittedType, $upgrade['type'])
		)
		{
			return (new Result())->addError(
				new Error('Trigger type transition is not allowed', 'TRIGGER_TYPE_TRANSITION_NOT_ALLOWED')
			);
		}

		$expression->rawActivityData['activityType'] = $sourceType;

		return new Result();
	}

	/**
	 * The class the node carries now, read before the pipeline is entered:
	 * modifyRawActivityDataTemplate() overwrites the node with the submitted class further down.
	 *
	 * The node of the template the form submitted answers first - it is the very source the shared save
	 * handler reads the class from, so the two checks cannot disagree about the node - and the activity
	 * data saved for the construction is only the fallback for a payload carrying no template. The final
	 * word is not here either way: the full save checks the construction against the host activity of its
	 * own request ({@see ValidateSingleRuleCommand}) before the properties are merged into the node.
	 */
	private function resolveNodeActivityType(BaseSettingsExpressionDto $expression): string
	{
		$rawActivityData = $expression->rawActivityData ?? [];
		$activityName = (string)($rawActivityData['id'] ?? '');
		if ($activityName !== '')
		{
			$template = $this->decodeTemplateData($rawActivityData)['template'];
			$node = is_array($template)
				? \CBPWorkflowTemplateLoader::findActivityByName($template, $activityName)
				: null
			;

			$templateType = is_array($node) ? (string)($node['Type'] ?? '') : '';
			if ($templateType !== '')
			{
				return $templateType;
			}
		}

		return (string)(($expression->activityData ?? [])['Type'] ?? '');
	}

	/**
	 * @param PortRuleDto $portRuleDto
	 * @return list<BaseSettingsExpressionDto>
	 */
	private function extractBaseSettingsExpressionList(PortRuleDto $portRuleDto): array
	{
		$list = [];

		foreach ($portRuleDto->rules as $rule)
		{
			foreach ($rule->constructions as $construction)
			{
				if ($construction->constructionType !== ConstructionType::BASE_SETTINGS)
				{
					continue;
				}

				$expression = $construction->expression;
				if (!$expression instanceof BaseSettingsExpressionDto)
				{
					continue;
				}

				$list[] = $expression;
			}
		}

		return $list;
	}

	/**
	 * Wrap a BaseSettingsExpressionDto into a minimal ActionExpressionDto so that
	 * the shared getActivitySettings() pipeline (prepareForm / decodeActivitySettings)
	 * can process it. BASE_SETTINGS has no actionId or document — those fields are
	 * left null; only rawActivityData and activityData are relevant.
	 */
	private function makeActionExpressionForBaseSettings(BaseSettingsExpressionDto $expression): ActionExpressionDto
	{
		return new ActionExpressionDto(
			actionId: null,
			rawActivityData: $expression->rawActivityData,
			activityData: $expression->activityData,
			document: null,
		);
	}

	/**
	 * Normalise a node-action-backed BASE_SETTINGS construction: rawActivityData → activityData.
	 *
	 * Field values are extracted through the backing node-action's getPropertiesMap using
	 * FieldType::extractValue (the same mechanism BaseActivity node-actions use for ACTION blocks),
	 * which internalises printable values (USER "Admin Adminov [1]" → "user_1", etc.) before the
	 * value is persisted or validated. Returns:
	 *   - null           — the construction is not node-action-backed → caller falls back to the
	 *                      host-merge getActivitySettings() pipeline (legacy behavior unchanged);
	 *   - Result(errors) — extraction/validation failed;
	 *   - Result(ok)     — activityData populated, rawActivityData cleared.
	 */
	private function extractNodeActionBaseSettings(BaseSettingsExpressionDto $expression): ?Result
	{
		$rawActivityData = $expression->rawActivityData;
		$activityType = (string)($rawActivityData['activityType'] ?? '');

		if (!$this->isNodeActionBaseSettings($expression, $activityType))
		{
			return null;
		}

		$internalizeResult = $this->internalizeNodeActionProperties($activityType, $rawActivityData);
		if ($internalizeResult === null)
		{
			return null;
		}

		if (!$internalizeResult->isSuccess())
		{
			return $internalizeResult;
		}

		[
			'properties' => $properties,
			'activated' => $isActivated,
		] = $internalizeResult->getData();

		$expression->rawActivityData = null;
		$expression->activityData = [
			'Name' => (string)($rawActivityData['id'] ?? ''),
			'Type' => $activityType,
			'Activated' => $isActivated ? 'Y' : 'N',
			'Properties' => $properties,
			'Document' => null,
		];

		return new Result();
	}

	/**
	 * Internalise a unified node-action's field values from rawActivityData.
	 *
	 * Values are extracted through the backing node-action's getPropertiesMap using
	 * FieldType::extractValue (printable USER "Admin Adminov [1]" → "user_1", etc.) and then validated
	 * with the node-action's own ValidateProperties — exactly what a correct getPropertiesDialogValues
	 * does for the unified (getPropertiesMap-rendered) form.
	 *
	 * The extraction is applied only when the activity actually renders that unified form, i.e. when
	 * CBPActivity::createConfigurator() resolves a non-empty propertiesMap — which happens exactly when
	 * the class implements IBPConfigurableActivity. This is the same gate ActivityControlsBuilder uses to
	 * build the form, so extraction and rendering stay consistent by construction. Legacy-dialog
	 * activities (e.g. CBPSetFieldActivity, CBPCrmCreateToDoActivity) do not implement
	 * IBPConfigurableActivity, resolve an empty map here, and are left to the shared getActivitySettings()
	 * pipeline unchanged. A legacy dual activity (robot + node-action, e.g. CBPIMNotifyActivity) does
	 * render the unified form but extracts via its own legacy getPropertiesDialogValues, which reads
	 * legacy dialog field names and never sees the getPropertiesMap FieldNames the form submits — so
	 * getActivitySettings() drops every value; this helper is what fixes it.
	 *
	 * @return Result|null
	 *   - null           the activity does not render the unified form → caller falls back to getActivitySettings;
	 *   - Result(errors) extraction/validation failed;
	 *   - Result(ok)     data: ['properties' => array, 'activated' => bool].
	 */
	private function internalizeNodeActionProperties(string $activityType, array $rawActivityData): ?Result
	{
		if ($activityType === '')
		{
			return null;
		}

		Loader::requireModule('bizproc');

		$documentTypeResult = $this->extractDocumentType($rawActivityData);
		if (!$documentTypeResult->isSuccess())
		{
			return $documentTypeResult;
		}

		$documentType = $documentTypeResult->getData()['documentType'] ?? $this->documentType;

		['properties' => $request] = $this->decodeActivitySettings(
			$this->replaceNestedDocumentType($rawActivityData, $documentType),
			$documentType,
		);
		$request = is_array($request) ? $request : [];

		// The document type must reach getPropertiesMap(): fields whose metadata depends on it (select
		// Options, document field lists) build empty otherwise, and FieldType::extractValue then drops the
		// submitted value. This is the same document type ActivityControlsBuilder renders the form with.
		$configurator = \CBPActivity::createConfigurator($activityType, $request, $documentType);
		$propertiesMap = $configurator->getPropertiesMap();
		if (empty($propertiesMap))
		{
			// Not a unified (getPropertiesMap-rendered) node-action — defer to the shared pipeline.
			return null;
		}

		$documentService = \CBPRuntime::getRuntime()->getDocumentService();
		$properties = [];
		$errors = [];
		foreach ($propertiesMap as $propertyKey => $fieldProperties)
		{
			$fieldReference = ['Field' => (string)($fieldProperties['FieldName'] ?? $propertyKey)];
			$field = $documentService->getFieldTypeObject($documentType, $fieldProperties);
			if ($field)
			{
				$fieldErrors = [];
				$value = $field->extractValue($fieldReference, $request, $fieldErrors);
				if (!empty($fieldErrors))
				{
					$errors = array_merge($errors, $fieldErrors);
				}
			}
			else
			{
				$value = $documentService->getFieldInputValue(
					$documentType,
					$fieldProperties,
					$fieldReference,
					$request,
					$errors,
				);
			}

			// Apply the field's declared Default when the unified form omitted the value
			// (null / empty string), mirroring PropertiesDialog::setCurrentValues(). A node-action
			// need not render every configuration field it declares (hidden technical fields such as
			// charset or delivery mode); without this, those fields internalise as null and either
			// fail the node-action's own ValidateProperties or drop the robot's declared defaults at
			// runtime. Keyed only off the field's own Default metadata — no per-activity branching.
			if (
				($value === null || $value === '')
				&& array_key_exists('Default', $fieldProperties)
			)
			{
				$value = $fieldProperties['Default'];
			}

			// Declarative Setter — the symmetric counterpart of a map field's 'Getter'. A field may
			// need to split its single form value across several legacy properties (e.g. a recipients
			// field whose value Execute() reads from a companion *Array property plus a literal string).
			// When declared, the Setter owns the write and returns [legacyPropertyName => value, ...];
			// otherwise the value is stored under the field's own key. Keyed only off the field's own
			// metadata — no per-activity branching. Robot dialogs never reach this bridge, so declaring
			// a Setter cannot affect the robot save path.
			$setter = $fieldProperties['Setter'] ?? null;
			if ($setter instanceof \Closure)
			{
				$split = $setter($value);
				if (is_array($split))
				{
					foreach ($split as $legacyKey => $legacyValue)
					{
						$properties[$legacyKey] = $legacyValue;
					}
				}
			}
			else
			{
				$properties[$propertyKey] = $value;
			}
		}

		$isActivated = ($rawActivityData['activated'] ?? 'Y') === 'Y';

		// Enforce the node-action's own required-field validation on the internalised values, matching
		// the save-time validation getActivitySettings() runs for activated activities.
		if (empty($errors) && $isActivated)
		{
			$validationErrors = \CBPActivity::callStaticMethod(
				$activityType,
				'ValidateProperties',
				[$properties, new \CBPWorkflowTemplateUser(\CBPWorkflowTemplateUser::CurrentUser)],
			);
			if (is_array($validationErrors))
			{
				$errors = array_merge($errors, $validationErrors);
			}
		}

		$result = new Result();
		if (!empty($errors))
		{
			foreach ($errors as $error)
			{
				$message = is_array($error) ? (string)($error['message'] ?? '') : (string)$error;
				$code = is_array($error) ? (string)($error['code'] ?? '') : '';
				$result->addError(new Error($message, $code));
			}

			return $result;
		}

		$result->setData([
			'properties' => $properties,
			'activated' => $isActivated,
			'title' => trim((string)($request['title'] ?? '')),
		]);

		return $result;
	}

	private function processConditionExpressions(PortRuleDto $resultPortRule): Result
	{
		$conditionDocumentType = $this->resolveHostNodeConditionDocumentType($resultPortRule);

		foreach ($this->extractConditionExpressionList($resultPortRule) as $conditionExpression)
		{
			$field = $conditionExpression->field;
			if ($field === null)
			{
				continue;
			}

			$conditionExpression->value = $this->resolveConditionValue(
				$field,
				$conditionExpression->value,
				$this->documentType,
				$conditionDocumentType,
			);
		}

		return new Result();
	}

	/**
	 * Document type the `Document` object of the conditions of this rule addresses: for a trigger the document
	 * of its event, and null for every other node, whose condition belongs to the template
	 * ({@see ConditionValueResolver::resolveConditionDocumentType()}).
	 *
	 * The node is read off the base settings of the same rules container - the construction the unified panel
	 * carries the node itself in - and off them as this run leaves them, base settings being normalised before
	 * the conditions are. A container arriving without them names no node, and the document type of the template
	 * keeps answering, exactly as it did before.
	 */
	private function resolveHostNodeConditionDocumentType(PortRuleDto $portRule): ?array
	{
		Loader::requireModule('bizproc');

		foreach ($this->extractBaseSettingsExpressionList($portRule) as $expression)
		{
			// A node-action proxy construction describes a child activity of its own, not the host node.
			if ($expression->actionId !== null)
			{
				continue;
			}

			$activityData = $expression->activityData ?? [];
			$properties = $activityData['Properties'] ?? [];
			$conditionDocumentType = $this->resolveConditionDocumentType(
				(string)($activityData['Type'] ?? ''),
				is_array($properties) ? $properties : [],
			);
			if ($conditionDocumentType !== null)
			{
				return $conditionDocumentType;
			}
		}

		return null;
	}

	/**
	 * @param PortRuleDto $portRuleDto
	 * @return list<ConditionExpressionDto>
	 */
	private function extractConditionExpressionList(PortRuleDto $portRuleDto): array
	{
		$list = [];

		foreach ($portRuleDto->rules as $rule)
		{
			foreach ($rule->constructions as $construction)
			{
				if (
					$construction->constructionType->isCondition()
					&& $construction->expression instanceof ConditionExpressionDto
				)
				{
					$list[] = $construction->expression;
				}
			}
		}

		return $list;
	}

	/**
	 * Past extractDocumentType() the nested key must no longer carry the client value: decodeActivitySettings()
	 * keeps it among the properties that go on to unConvertProperties(), createConfigurator() and
	 * getPropertiesDialogValues(). Nothing reads it there today, so the checked type replaces the raw value
	 * instead of the key being dropped - a reader appearing later gets a usable type, not a missing key. A
	 * payload that carried no such key does not get one.
	 */
	private function replaceNestedDocumentType(array $rawActivityData, array $documentType): array
	{
		if (array_key_exists('documentType', $rawActivityData))
		{
			$rawActivityData['documentType'] = $documentType;
		}

		return $rawActivityData;
	}

	private function modifyRawActivityDataTemplate(array $rawActivityData): array
	{
		$activityName = (string)($rawActivityData['id'] ?? '');
		$workflowTemplate = Json::decode($rawActivityData['arWorkflowTemplate'] ?? '[]');

		$workflowTemplate[$activityName] = [
			'Name' => $activityName,
			'Properties' => [],
			'Activated' => 'Y',
			'Type' => $rawActivityData['activityType'] ?? '',
		];

		$rawActivityData['arWorkflowTemplate'] = Json::encode($workflowTemplate);

		return $rawActivityData;
	}
}

<?php

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Validator;

use Bitrix\Bizproc\Public\Activity\Interface\NodeFilterMetadataProvider;
use Bitrix\BizprocDesigner\Internal\Entity\ComplexBlockDetail;
use Bitrix\BizprocDesigner\Internal\Entity\ComplexNodeAction;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentComplexConstruction;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentComplexRule;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentComplexRules;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Enum\GraphErrorCode;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Error\GraphError;
use Bitrix\Main\Result;
use CBPRuntime;

/**
 * Validates the nested rules projection (DTO-01) of a complex node against the block's own complex
 * detail (DTO-02) before the graph is materialised:
 *
 * - `action.activityCode` must be in the sub-action dictionary (reused from
 *   {@see ComplexBlockDetail::$nodeActions}, itself derived from the domain
 *   {@see \Bitrix\Bizproc\Internal\Service\Activity\ComplexActivityService}); switchnode carries an
 *   empty dictionary -> any `action` is rejected, only `condition`/`output` remain valid;
 * - condition types come from the closed allowlist {@see AgentComplexConstruction::TYPES};
 *   `codecondition` is rejected with an explicit error as a second contour (defense in depth);
 * - sub-actions require a fixed-document complex node so they stay within `fixedDocumentType`;
 * - a `filter` construction is accepted only when the environment supports it
 *   ({@see ComplexBlockDetail::$filterSupported}) AND the backing activity implements
 *   {@see NodeFilterMetadataProvider} - otherwise an explicit error, not a silent degradation.
 *
 * On success {@see self::getValidRules()} returns the {@see AgentComplexRules} projection.
 */
final class AgentComplexRulesValidator
{
	/** Condition kinds that must never be expressible; rejected explicitly (defense in depth). */
	private const CODE_CONDITION_TYPES = ['codecondition', 'code', 'php', 'condition:code'];

	private const INPUT_PORT_PATTERN = '/^i\d+$/';
	private const OUTPUT_PORT_PATTERN = '/^o\d+$/';

	private ?AgentComplexRules $validRules = null;

	public function validate(mixed $rules, ComplexBlockDetail $complexDetail, string $path = ''): Result
	{
		$this->validRules = null;

		$result = new Result();

		if (!is_array($rules) || $rules === [])
		{
			return $result->addError(GraphError::at($path, "{$path} should be a non-empty map of input ports to rules"));
		}

		$allowedActivityCodes = $this->collectAllowedActivityCodes($complexDetail);

		$portRules = [];
		foreach ($rules as $portId => $portRuleList)
		{
			$portPath = "{$path}.{$portId}";

			if (!is_string($portId) || preg_match(self::INPUT_PORT_PATTERN, $portId) !== 1)
			{
				$result->addError(GraphError::at(
					$portPath,
					"{$portPath} is not a valid input port id (expected 'iN')",
					GraphErrorCode::PortInvalid,
				));

				continue;
			}

			if (!is_array($portRuleList) || $portRuleList === [])
			{
				$result->addError(GraphError::at($portPath, "{$portPath} should be a non-empty list of rules"));

				continue;
			}

			$rulesForPort = [];
			foreach (array_values($portRuleList) as $ruleKey => $rawRule)
			{
				$rule = $this->validateRule(
					$rawRule,
					"{$portPath}.{$ruleKey}",
					$complexDetail,
					$allowedActivityCodes,
					$result,
				);
				if ($rule !== null)
				{
					$rulesForPort[] = $rule;
				}
			}

			if ($rulesForPort !== [])
			{
				$portRules[$portId] = $rulesForPort;
			}
		}

		if ($result->isSuccess())
		{
			$this->validRules = new AgentComplexRules($portRules);
		}

		return $result;
	}

	public function getValidRules(): ?AgentComplexRules
	{
		return $this->validRules;
	}

	private function validateRule(
		mixed $rawRule,
		string $path,
		ComplexBlockDetail $complexDetail,
		array $allowedActivityCodes,
		Result $result,
	): ?AgentComplexRule
	{
		if (!is_array($rawRule))
		{
			$result->addError(GraphError::at($path, "{$path} should be object"));

			return null;
		}

		$id = $rawRule['id'] ?? null;
		if (!is_string($id) || $id === '')
		{
			$idPath = "{$path}.id";
			$result->addError(GraphError::at($idPath, "{$idPath} should be not empty string"));
		}

		$constructions = $rawRule['constructions'] ?? null;
		if (!is_array($constructions) || $constructions === [])
		{
			$constructionsPath = "{$path}.constructions";
			$result->addError(GraphError::at($constructionsPath, "{$constructionsPath} should be a non-empty list"));

			return null;
		}

		$validConstructions = [];
		foreach (array_values($constructions) as $key => $rawConstruction)
		{
			$construction = $this->validateConstruction(
				$rawConstruction,
				"{$path}.constructions.{$key}",
				$complexDetail,
				$allowedActivityCodes,
				$result,
			);
			if ($construction !== null)
			{
				$validConstructions[] = $construction;
			}
		}

		if (!is_string($id) || $id === '' || $validConstructions === [])
		{
			return null;
		}

		return new AgentComplexRule($id, $validConstructions);
	}

	private function validateConstruction(
		mixed $rawConstruction,
		string $path,
		ComplexBlockDetail $complexDetail,
		array $allowedActivityCodes,
		Result $result,
	): ?AgentComplexConstruction
	{
		if (!is_array($rawConstruction))
		{
			$result->addError(GraphError::at($path, "{$path} should be object"));

			return null;
		}

		$typePath = "{$path}.type";

		$type = $rawConstruction['type'] ?? null;
		if (!is_string($type) || $type === '')
		{
			$result->addError(GraphError::at($typePath, "{$typePath} should be not empty string"));

			return null;
		}

		if (in_array(mb_strtolower($type), self::CODE_CONDITION_TYPES, true))
		{
			$result->addError(GraphError::at(
				$typePath,
				"{$typePath} '{$type}': code conditions are not allowed in complex node rules",
			));

			return null;
		}

		if (!in_array($type, AgentComplexConstruction::TYPES, true))
		{
			$result->addError(GraphError::at(
				$typePath,
				"{$typePath} '{$type}' is not one of: " . implode(', ', AgentComplexConstruction::TYPES),
			));

			return null;
		}

		$expressionPath = "{$path}.{$type}";

		$expression = $rawConstruction[$type] ?? null;
		if (!is_array($expression))
		{
			$result->addError(GraphError::at($expressionPath, "{$expressionPath} should be object"));

			return null;
		}

		$expressionResult = match ($type)
		{
			AgentComplexConstruction::TYPE_CONDITION => $this->validateCondition($expression, $expressionPath),
			AgentComplexConstruction::TYPE_ACTION =>
				$this->validateAction($expression, $expressionPath, $complexDetail, $allowedActivityCodes),
			AgentComplexConstruction::TYPE_FILTER => $this->validateFilter($expression, $expressionPath, $complexDetail),
			AgentComplexConstruction::TYPE_OUTPUT => $this->validateOutput($expression, $expressionPath),
		};

		$result->addErrors($expressionResult->getErrors());
		if (!$expressionResult->isSuccess())
		{
			return null;
		}

		return new AgentComplexConstruction($type, $expression);
	}

	/**
	 * Condition contract (DTO-03): a field-based comparison projected from `mixedcondition`. Field codes
	 * are stable (object + fieldId), not localized labels; `value` is carried verbatim (bizproc
	 * expressions `{=...}`/`{{=...}}` included), typed-value resolution is left to the converter.
	 */
	private function validateCondition(array $condition, string $path): Result
	{
		$result = new Result();

		$fieldPath = "{$path}.field";

		$field = $condition['field'] ?? null;
		if (!is_array($field))
		{
			$result->addError(GraphError::at($fieldPath, "{$fieldPath} should be object"));
		}
		else
		{
			$objectPath = "{$fieldPath}.object";
			$object = $field['object'] ?? null;
			if (!is_string($object) || $object === '')
			{
				$result->addError(GraphError::at($objectPath, "{$objectPath} should be not empty string"));
			}

			$fieldIdPath = "{$fieldPath}.fieldId";
			$fieldId = $field['fieldId'] ?? null;
			if (!is_string($fieldId) || $fieldId === '')
			{
				$result->addError(GraphError::at($fieldIdPath, "{$fieldIdPath} should be not empty string"));
			}
		}

		$operatorPath = "{$path}.operator";
		$operator = $condition['operator'] ?? null;
		if (!is_string($operator) || $operator === '')
		{
			$result->addError(GraphError::at($operatorPath, "{$operatorPath} should be not empty string"));
		}

		$joinerPath = "{$path}.joiner";
		$joiner = $condition['joiner'] ?? null;
		if ($joiner !== null && !in_array($joiner, ['AND', 'OR'], true))
		{
			$result->addError(GraphError::at($joinerPath, "{$joinerPath} should be 'AND' or 'OR'"));
		}

		return $result;
	}

	private function validateAction(
		array $action,
		string $path,
		ComplexBlockDetail $complexDetail,
		array $allowedActivityCodes,
	): Result
	{
		$result = new Result();

		$activityCodePath = "{$path}.activityCode";

		$activityCode = $action['activityCode'] ?? null;
		if (!is_string($activityCode) || $activityCode === '')
		{
			return $result->addError(GraphError::at($activityCodePath, "{$activityCodePath} should be not empty string"));
		}

		if ($allowedActivityCodes === [])
		{
			// Router nodes (switchnode) expose an empty dictionary -> sub-actions are not allowed.
			return $result->addError(GraphError::at(
				$path,
				"{$path}: this complex node does not allow sub-actions (only condition/output)",
			));
		}

		if (!in_array(mb_strtolower($activityCode), $allowedActivityCodes, true))
		{
			return $result->addError(GraphError::at(
				$activityCodePath,
				"{$activityCodePath} '{$activityCode}' is not in the allowed sub-action dictionary",
			));
		}

		if ($complexDetail->fixedDocumentType === null)
		{
			// Sub-actions operate on the node's fixed document; without one they cannot stay in scope.
			return $result->addError(GraphError::at(
				$path,
				"{$path}: sub-actions require a fixed-document complex node",
			));
		}

		$result->addErrors($this->validateNestedSettingsShape($action, $path)->getErrors());

		$nodeAction = $this->findNodeActionByCode($complexDetail, $activityCode);
		if ($nodeAction !== null)
		{
			$result->addErrors(
				$this->validateNestedSettingNames($action, $path, $nodeAction->settingsSchema)->getErrors(),
			);
		}

		$result->addErrors($this->validateActionDocument($action, $path, $nodeAction)->getErrors());

		return $result;
	}

	/**
	 * `action.document` binds the sub-action to a document via a bizproc expression `{=<sourceBlockId>:<propertyId>}`
	 * (e.g. `{=<triggerId>:ReturnDocument}` for a WORKFLOW trigger) - never a document-type triplet array nor a plain
	 * preset like `"CONTACT"`. Enforced fail-closed, symmetric to the materialisation contract:
	 *  - format: when a document is supplied it must be a non-empty expression string; the same
	 *    {@see \CBPActivity::parseExpression} that {@see \Bitrix\BizprocDesigner\Internal\Command\Activity\Complex\ConvertRuleCommand::resolveTargetFilterIdForAction}
	 *    uses decides - an array or a non-expression string is rejected here rather than being silently lost or
	 *    `(string)`-cast to `"Array"` by the converter;
	 *  - requiredness: a document-handling sub-action ({@see ComplexNodeAction::$handlesDocument}, the same flag
	 *    {@see \Bitrix\BizprocDesigner\Internal\Command\Activity\Complex\ValidateSingleRuleCommand} enforces late) must
	 *    bind one - an empty/absent document is an error, not a silent `valid:true`.
	 *
	 * Reachability of the referenced source from this point of the graph is deliberately NOT checked - that stays with
	 * materialisation ({@see \Bitrix\BizprocDesigner\Internal\Command\Activity\Complex\ConvertRuleCommand}), the agent
	 * validator has no graph topology.
	 */
	private function validateActionDocument(array $action, string $path, ?ComplexNodeAction $nodeAction): Result
	{
		$result = new Result();

		$documentPath = "{$path}.document";
		$document = $action['document'] ?? null;

		// An empty string or an absent key means "not bound" (equivalent to null), not a format error.
		$documentIsBound = $document !== null && $document !== '';

		if ($documentIsBound)
		{
			if (!is_string($document) || \CBPActivity::parseExpression($document) === null)
			{
				$result->addError(GraphError::at(
					$documentPath,
					"{$documentPath} must be a bizproc expression '{=<sourceBlockId>:<propertyId>}' referencing an"
					. " ancestor output (e.g. '{=<triggerId>:ReturnDocument}'), not a plain value or a document-type array",
				));
			}

			return $result;
		}

		if ($nodeAction !== null && $nodeAction->handlesDocument)
		{
			$result->addError(GraphError::at(
				$documentPath,
				"{$documentPath} is required for this sub-action (it handles the document) and must be a bizproc"
				. " expression '{=<sourceBlockId>:<propertyId>}'",
			));
		}

		return $result;
	}

	private function validateFilter(array $filter, string $path, ComplexBlockDetail $complexDetail): Result
	{
		$result = new Result();

		if (!$complexDetail->filterSupported)
		{
			return $result->addError(GraphError::at($path, "{$path}: node filter is not supported in this environment"));
		}

		$activityCodePath = "{$path}.activityCode";

		$activityCode = $filter['activityCode'] ?? null;
		if (!is_string($activityCode) || $activityCode === '')
		{
			return $result->addError(GraphError::at($activityCodePath, "{$activityCodePath} should be not empty string"));
		}

		if (!self::isNodeFilterBackingActivity($activityCode))
		{
			return $result->addError(GraphError::at(
				$activityCodePath,
				"{$activityCodePath} '{$activityCode}' is not a node-filter backing activity",
			));
		}

		$result->addErrors($this->validateNestedSettingsShape($filter, $path)->getErrors());

		return $result;
	}

	/**
	 * A sub-action's / filter's `settings` is the child activity's `Properties`, so it must be a settings map
	 * ({name: value}), never a list. The reverse converter only ever emits a map or an empty `[]` (see
	 * {@see \Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\AiAssistantWorkflowTemplateConverterService::mapActionExpressionToProjection}),
	 * so a non-empty list can only come from an agent copying the top-level `settings` shape (a `[{name, value}]`
	 * list, which is correct there but not here). Left unchecked it would be saved under the numeric key `0` and
	 * leave the activity property empty - a silent loss. The empty `[]` stays valid to keep the round-trip intact.
	 */
	private function validateNestedSettingsShape(array $expression, string $path): Result
	{
		$result = new Result();

		$settings = $expression['settings'] ?? null;
		if (is_array($settings) && $settings !== [] && array_is_list($settings))
		{
			$settingsPath = "{$path}.settings";
			$result->addError(GraphError::at(
				$settingsPath,
				"{$settingsPath} should be a settings map (an object keyed by setting name), not a list",
			));
		}

		return $result;
	}

	/**
	 * B1+ name cross-check: when the sub-action carries a non-empty settings schema (DTO-04, the same
	 * {@see \Bitrix\Bizproc\Internal\Entity\Activity\Setting::toArray()} entries used to describe ordinary
	 * nodes), every key in a map-shaped `settings` must be a known setting name - mirrors how ordinary blocks
	 * cross-check a setting name in {@see AgentBlockSettingValidator}. An empty schema (an HTML-only sub-action
	 * or a schema unavailable in the environment) disables the check, otherwise every setting would be a false
	 * "unknown". List-shaped and empty settings are left to {@see self::validateNestedSettingsShape()}.
	 */
	private function validateNestedSettingNames(array $action, string $path, array $settingsSchema): Result
	{
		$result = new Result();

		if ($settingsSchema === [])
		{
			return $result;
		}

		$settings = $action['settings'] ?? null;
		if (!is_array($settings) || $settings === [] || array_is_list($settings))
		{
			return $result;
		}

		$allowedNames = [];
		foreach ($settingsSchema as $setting)
		{
			$name = is_array($setting) ? ($setting['name'] ?? null) : null;
			if (is_string($name) && $name !== '')
			{
				$allowedNames[] = $name;
			}
		}

		foreach (array_keys($settings) as $settingName)
		{
			if (!in_array($settingName, $allowedNames, true))
			{
				$settingPath = "{$path}.settings.{$settingName}";
				$result->addError(GraphError::at(
					$settingPath,
					"{$settingPath} is not a known setting of this sub-action",
				));
			}
		}

		return $result;
	}

	private function findNodeActionByCode(ComplexBlockDetail $complexDetail, string $activityCode): ?ComplexNodeAction
	{
		$needle = mb_strtolower($activityCode);
		foreach ($complexDetail->nodeActions as $nodeAction)
		{
			/** @var ComplexNodeAction $nodeAction */
			if (mb_strtolower($nodeAction->activityCode) === $needle)
			{
				return $nodeAction;
			}
		}

		return null;
	}

	private function validateOutput(array $output, string $path): Result
	{
		$result = new Result();

		$portIdPath = "{$path}.portId";
		$portId = $output['portId'] ?? null;
		if (!is_string($portId) || preg_match(self::OUTPUT_PORT_PATTERN, $portId) !== 1)
		{
			$result->addError(GraphError::at(
				$portIdPath,
				"{$portIdPath} is not a valid output port id (expected 'oN')",
				GraphErrorCode::PortInvalid,
			));
		}

		$titlePath = "{$path}.title";
		$title = $output['title'] ?? null;
		if (!is_string($title) || $title === '')
		{
			$result->addError(GraphError::at($titlePath, "{$titlePath} should be not empty string"));
		}

		return $result;
	}

	/**
	 * @return list<string> lowercased allowed sub-action activity codes taken from the block's complex
	 *         detail (DTO-02) - the same dictionary the manual editor and the domain use, not a parallel
	 *         AI-side list. Empty for switchnode / router nodes.
	 */
	private function collectAllowedActivityCodes(ComplexBlockDetail $complexDetail): array
	{
		$codes = [];
		foreach ($complexDetail->nodeActions as $nodeAction)
		{
			/** @var ComplexNodeAction $nodeAction */
			$codes[] = mb_strtolower($nodeAction->activityCode);
		}

		return $codes;
	}

	/**
	 * Mirrors {@see \Bitrix\BizprocDesigner\Internal\Command\Activity\Complex\ConvertRuleCommand}: the
	 * single source of truth for "is a filter backing activity" is the {@see NodeFilterMetadataProvider}
	 * interface. This is the runtime capability check, not the sub-action dictionary.
	 */
	private static function isNodeFilterBackingActivity(string $activityType): bool
	{
		if ($activityType === '')
		{
			return false;
		}

		if (!CBPRuntime::getRuntime()->includeActivityFile($activityType))
		{
			return false;
		}

		return is_subclass_of('CBP' . $activityType, NodeFilterMetadataProvider::class, true);
	}
}

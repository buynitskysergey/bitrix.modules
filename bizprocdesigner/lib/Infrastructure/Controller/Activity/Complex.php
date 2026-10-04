<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Controller\Activity;

use Bitrix\Bizproc\Activity\ActivityDescription;
use Bitrix\Bizproc\Api\Enum\ErrorMessage;
use Bitrix\Bizproc\Internal\Service\Activity\ComplexActivityService;
use Bitrix\Bizproc\Internal\Service\Container;
use Bitrix\Bizproc\Public\Activity\Configurator;
use Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\AvailableBlocksDto;
use Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\CapabilityCatalogResponseDto;
use Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\SaveSettingsRequestDto;
use Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\Rule\ActionExpressionDto;
use Bitrix\BizprocDesigner\Infrastructure\Enum\ConstructionType;
use Bitrix\BizprocDesigner\Internal\Exception\CommandValidateException;
use Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\ActionDictionaryEntryDto;
use Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\LoadSettingsResponseDto;
use Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\PortRuleDto;
use Bitrix\BizprocDesigner\Internal\Command\Activity\Complex\ConvertRuleCommand;
use Bitrix\BizprocDesigner\Internal\Command\Activity\Complex\ConvertRuleCommandResult;
use Bitrix\BizprocDesigner\Internal\Command\Activity\Complex\SaveSingleRuleCommand;
use Bitrix\BizprocDesigner\Internal\Command\Activity\Complex\SaveSingleRuleCommandResult;
use Bitrix\BizprocDesigner\Internal\Command\Activity\Complex\ValidateSingleRuleCommand;
use Bitrix\BizprocDesigner\Internal\Config\Feature;
use Bitrix\BizprocDesigner\Internal\Entity\ActivityData;
use Bitrix\BizprocDesigner\Internal\Service\Container as DesignerContainer;
use Bitrix\BizprocDesigner\Internal\Service\RelationAutofillMatcher;
use Bitrix\Main\Engine\AutoWire\ExactParameter;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\Main\Web\Json;
use CBPCanUserOperateOperation;
use CBPDocument;
use Exception;

class Complex extends \Bitrix\Main\Engine\JsonController
{
	protected function init()
	{
		parent::init();
		Loader::requireModule('bizproc');
	}

	public function getAutoWiredParameters()
	{
		return [
			new ExactParameter(
				PortRuleDto::class,
				'portRule',
				static fn(string $className, array $portRule) => PortRuleDto::fromArray($portRule),
			),
			new ExactParameter(
				ActivityData::class,
				'activity',
				static fn(string $className, array $activity) => ActivityData::createFromArray($activity),
			),
			new ExactParameter(
				SaveSettingsRequestDto::class,
				'saveSettingsRequest',
				static fn(string $className, array $saveSettingsRequest) => SaveSettingsRequestDto::fromArray($saveSettingsRequest),
			),
		];
	}

	/**
	 * @param ActivityData $activity Target complex node whose settings are loaded.
	 * @param array $sources Relation ancestors of the target node (canonical source shape, owned here):
	 *   list of {
	 *     blockId: string,
	 *     documentType: array[moduleId, entity, documentType],
	 *     outputs?: string[],        // normalized ReturnProperties codes of the ancestor block
	 *     documentOutput?: string    // code of the ancestor document output that carries the linked entity id
	 *   }.
	 *   `outputs` are the ancestor's real normalized ReturnProperties, held by the frontend on the canvas
	 *   (same data ValueSelector uses to build {=block.id:property.Id}). `documentOutput` is the accessor
	 *   seam through which the linked entity id is reached (`{=blockId:documentOutput.propertyId}`); both
	 *   only ever come from the frontend, so a source missing either builds no autofill. Empty $sources
	 *   keeps the response backward compatible (autofillMap stays empty).
	 * @param array $documentType Edited template document type ([moduleId, entity, documentType]). Used as the
	 *   node's effective document type when it has no fixed one, feeding node-action resolution and the
	 *   available-blocks lookup. Legacy loads omit it and stay unaffected.
	 */
	public function loadSettingsAction(
		ActivityData $activity,
		array $sources = [],
		array $documentType = [],
	): ?LoadSettingsResponseDto
	{
		if (empty($activity->type))
		{
			return null;
		}

		// The template document type is authorized first, the same gate the catalog and save actions hold:
		// without it a node publishing that very type would slip past the resolved-type check below as
		// "the one and same". A legacy load carries no document type and keeps its pre-gate behaviour.
		if ($documentType !== [])
		{
			$result = $this->validateCurrentUserOperateDocument(
				operation: CBPCanUserOperateOperation::CreateWorkflow,
				documentType: $documentType,
			);
			if (!$result->isSuccess())
			{
				$this->addErrors($result->getErrors());

				return null;
			}
		}

		$complexActivityService = Container::instance()->getComplexActivityService();
		$complexActivityName = strtolower($activity->type);

		// Resolve description once; reuse for the panel gate below, for nodeSettings and for
		// getCorrespondingNodeActionActivityByName, to avoid a duplicate getActivityDescriptionByCode lookup.
		$nodeDescription = $complexActivityService->getActivityDescriptionByCode($complexActivityName);

		$configurator = \CBPActivity::createConfigurator($activity->type, $activity->properties);
		if (!$this->isNodeSettingsSurfaceAvailable($complexActivityName, $configurator, $nodeDescription))
		{
			$this->addActivityNotFoundError($activity->type);
			return null;
		}

		// Two answers, and they are not interchangeable: the class-declared type gates the surfaces with a
		// runtime counterpart (the filter block, the relation autofill), while the published one is what the
		// panel works in - it also carries the document type of the event of a trigger.
		$fixedDocumentType = $complexActivityService->getFixedDocumentTypeForNodeAction($activity->type);
		$publishedDocumentType = $complexActivityService->getPublishedDocumentTypeForNode(
			$activity->type,
			$activity->properties,
		);

		// Before the action catalog of that document type is built, and therefore before any of it is disclosed.
		$accessResult = $this->validateResolvedDocumentTypeAccess($publishedDocumentType, $documentType);
		if (!$accessResult->isSuccess())
		{
			$this->addErrors($accessResult->getErrors());

			return null;
		}

		$filterAvailability = DesignerContainer::getNodeFilterAvailability();
		$effectiveDocumentType = $filterAvailability->resolveEffectiveDocumentType($publishedDocumentType, $documentType);

		$nodeSettings = $nodeDescription?->getComplexActivitySettings();

		$nodeActionCollection = $complexActivityService
			->getCorrespondingNodeActionActivityByName($complexActivityName, $effectiveDocumentType)
		;

		$portRuleDtoDictionary = $this->extractRulePropertyValue($configurator, $activity);
		if (!$portRuleDtoDictionary)
		{
			$this->addError(ErrorMessage::UNKNOWN_ERROR->getError());
			return null;
		}

		$searcher = Container::instance()->getActivitySearcherService();
		$actionCatalogMap = Container::instance()->getActionCatalogMapService();

		// isRelationCreate feeds the autofill consumer only, which is itself gated behind the
		// relation-connections feature. With the feature off the flag stays false and the map is skipped.
		$relationsAvailable = Feature::instance()->areComplexNodeConnectionsAvailable();

		$actionDictionary = [];
		$hasRelationCreateAction = false;
		foreach ($nodeActionCollection as $nodeAction)
		{
			$properties = $nodeAction->get('PROPERTIES');
			$normalizedCode = $searcher->normalizeActivityCode($nodeAction->getClass());
			$group = $nodeSettings?->actionDictionary->get($normalizedCode)?->group?->value;
			// Actions coming from the global catalog have no dictionary entry — group comes from
			// their own classification, the same fallback the capability catalog applies.
			$group ??= $actionCatalogMap->getAvailableClassification($normalizedCode)?->group?->value;
			// A relation "Create" sub-action is flagged by CREATES_DOCUMENT in its node-action settings
			// (design-time metadata), so its class is never loaded just to detect it and any create-kind
			// activity qualifies regardless of its base class (e.g. SPA create extends BaseActivity).
			$isRelationCreate = $relationsAvailable
				&& ($nodeAction->getNodeActionSettings()['CREATES_DOCUMENT'] ?? false);
			$hasRelationCreateAction = $hasRelationCreateAction || $isRelationCreate;
			$actionDictionary[$nodeAction->getClass()] = new ActionDictionaryEntryDto(
				id: $nodeAction->getClass(),
				title: $nodeAction->getName(),
				handlesDocument: $nodeAction->getNodeActionSettingsDto()?->handlesDocument ?? false,
				group: $group,
				properties: is_array($properties) ? $properties : null,
				isRelationCreate: $isRelationCreate,
			);
		}

		$relationActionDto = null;
		$relationActionDescription = $complexActivityService->getRelationActionActivity($complexActivityName);
		if ($relationActionDescription)
		{
			$properties = $relationActionDescription->get('PROPERTIES');
			$relationActionDto = new ActionDictionaryEntryDto(
				id: $relationActionDescription->getClass(),
				title: $relationActionDescription->getName(),
				handlesDocument: $relationActionDescription->getNodeActionSettings()['HANDLES_DOCUMENT'] ?? false,
				properties: is_array($properties) ? $properties : null,
			);
		}

		// The saved relation dictionary is served only while the node actually offers the relations
		// surface - the same availability the capability catalog reports to the frontend. Otherwise
		// relation data saved before the availability gates existed would round-trip through the
		// settings form of a node whose UI can neither show nor edit it.
		$relationPortRuleDtoDictionary = [];
		if ($this->isRelationsSurfaceAvailable($nodeDescription, $relationsAvailable))
		{
			$relationsRaw = $activity->properties['Relations'] ?? [];
			if (is_array($relationsRaw))
			{
				foreach ($relationsRaw as $portId => $ruleCollection)
				{
					if (is_array($ruleCollection))
					{
						$relationPortRuleDtoDictionary[$portId] = PortRuleDto::fromArray($ruleCollection);
					}
				}
			}
		}

		$filterRuntimeAvailable = $filterAvailability->isRuntimeAvailable($fixedDocumentType, $documentType);

		// getAvailableBlocksForNode() avoids a second getCorrespondingNodeActionActivityByName() traversal.
		$availableBlocks = Container::instance()->getCapabilityCatalogService()->getAvailableBlocksForNode(
			activityType: $activity->type,
			filterAvailable: $filterRuntimeAvailable,
			relationsAvailable: $relationsAvailable,
			documentType: $effectiveDocumentType,
			filterModuleSupported: $filterAvailability->supportsModule($fixedDocumentType, $documentType),
		);

		$availableBlocksDto = $availableBlocks !== null
			? AvailableBlocksDto::fromBlockAvailability($availableBlocks)
			: null
		;

		// filterSupported is a mirror of availableBlocks.filter.available, as its DTO promises: the two
		// cannot disagree, so the client never shows a filter the save path would reject and never hides
		// one it would accept.
		$filterSupported = $filterAvailability->isAvailableForNode($availableBlocks, $filterRuntimeAvailable);

		// Autofill applies only to a "Create" sub-action fed by relation ancestors. It is gated behind the
		// relation-connections feature (with the feature off the map stays empty) and requires CreateWorkflow
		// on the server-resolved created document type ($fixedDocumentType) - the exact type whose relation
		// metadata the map discloses. Empty $sources keeps the response backward compatible (map stays empty).
		$autofillMap = [];
		if (
			$relationsAvailable
			&& $hasRelationCreateAction
			&& $sources
			&& is_array($fixedDocumentType)
		)
		{
			// Fail-open: autofill is a best-effort enrichment, so a user without CreateWorkflow (or a type the
			// check rejects) yields an empty map instead of failing the whole load. Authorization is checked on
			// the same server-resolved type the map is built for - the client cannot substitute a permissive
			// type - and BEFORE any relation/field metadata is resolved, so a denied request never leaks it.
			try
			{
				$accessGranted = $this->validateCurrentUserOperateDocument(
					operation: CBPCanUserOperateOperation::CreateWorkflow,
					documentType: $fixedDocumentType,
				)->isSuccess();
			}
			catch (\Throwable)
			{
				// A document type the CreateWorkflow provider cannot handle may throw; treat it as denied.
				$accessGranted = false;
			}

			if ($accessGranted)
			{
				$autofillMap =
					(new RelationAutofillMatcher(Container::instance()->getRelationFieldResolverRegistry()))
						->buildAutofillMap($this->buildSourceCandidates($sources), $fixedDocumentType)
				;
			}
		}

		return new LoadSettingsResponseDto(
			title: $activity->properties['Title'] ?? '',
			description: $activity->properties['EditorComment'] ?? '',
			portRuleDtoDictionary: $portRuleDtoDictionary,
			actionEntryDtoDictionary: $actionDictionary,
			fixedDocumentType: $publishedDocumentType,
			filterSupported: $filterSupported,
			availableBlocks: $availableBlocksDto,
			autofillMap: $autofillMap,
			relationAction: $relationActionDto,
			relationPortRuleDtoDictionary: $relationPortRuleDtoDictionary,
		);
	}

	/**
	 * @param array $sources Raw relation ancestors from the request.
	 * @return list<array{
	 *   blockId: string,
	 *   documentType: array{string, string, string},
	 *   outputs: list<string>,
	 *   documentOutput: string
	 * }>
	 */
	private function buildSourceCandidates(array $sources): array
	{
		$candidates = [];
		foreach ($sources as $source)
		{
			if (!is_array($source))
			{
				continue;
			}

			$blockId = $source['blockId'] ?? null;
			$documentType = $source['documentType'] ?? null;
			// An absent document output is not malformed: such a source simply builds no accessor.
			$documentOutput = $source['documentOutput'] ?? '';
			if (
				!$this->isExpressionIdentifier($blockId)
				|| !$this->isCanonicalDocumentType($documentType)
				|| ($documentOutput !== '' && !$this->isExpressionIdentifier($documentOutput))
			)
			{
				continue;
			}

			// The real normalized ReturnProperties the frontend already holds for the block on the canvas.
			// Without them (and the documentOutput) the candidate cannot build an autofill accessor.
			$outputs = is_array($source['outputs'] ?? null)
				? $this->normalizeOutputs($source['outputs'])
				: []
			;

			$candidates[] = [
				'blockId' => $blockId,
				'documentType' => $documentType,
				'outputs' => $outputs,
				'documentOutput' => $documentOutput,
			];
		}

		return $candidates;
	}

	/**
	 * The canonical bizproc document type: the [moduleId, entity, documentType] triplet of non-empty
	 * strings. Any other shape names no ancestor a resolver could relate to the target, and its elements
	 * would still be read as strings down the path, so its source is skipped rather than refused.
	 */
	private function isCanonicalDocumentType(mixed $documentType): bool
	{
		if (!is_array($documentType) || count($documentType) !== 3)
		{
			return false;
		}

		foreach ([0, 1, 2] as $key)
		{
			$element = $documentType[$key] ?? null;
			if (!is_string($element) || $element === '')
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * Both client-owned parts of the autofill accessor {=<blockId>:<documentOutput>.<propertyId>} - block
	 * id and document output - may only be latin letters, digits and underscores, the object position of
	 * {@see \CBPActivity::ValuePattern}; anything else is stringified or punctuated into an accessor the
	 * source does not describe. The dot is excluded not by the grammar (the field position allows it) but
	 * because it separates the document output from the property id. Cost: a block id outside the set
	 * (AI-generated MartaGeneratedId-*, blocks renamed via the activity_id control) gets no autofill, and
	 * composed no parsable expression before the check either.
	 */
	private function isExpressionIdentifier(mixed $value): bool
	{
		return is_string($value) && preg_match('#\A[A-Za-z0-9_]+\z#', $value) === 1;
	}

	/**
	 * @param array $outputs Raw output codes from the request.
	 * @return list<string> Non-empty string output codes.
	 */
	private function normalizeOutputs(array $outputs): array
	{
		$normalized = [];
		foreach ($outputs as $output)
		{
			if (is_string($output) || is_int($output))
			{
				$code = (string)$output;
				if ($code !== '')
				{
					$normalized[] = $code;
				}
			}
		}

		return $normalized;
	}

	public function getCapabilityCatalogAction(
		ActivityData $activity,
		array $documentType,
	): ?CapabilityCatalogResponseDto
	{
		$result = $this->validateCurrentUserOperateDocument(
			operation: CBPCanUserOperateOperation::CreateWorkflow,
			documentType: $documentType,
		);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());
			return null;
		}

		if (empty($activity->type))
		{
			$this->addError(ErrorMessage::ACTIVITY_NOT_FOUND->getError());
			return null;
		}

		$complexActivityService = Container::instance()->getComplexActivityService();
		// Same split as loadSettingsAction: the filter gate stays on the class-declared type, the action
		// catalog is built in the document context the node actually works in.
		$fixedDocumentType = $complexActivityService->getFixedDocumentTypeForNodeAction($activity->type);
		$publishedDocumentType = $complexActivityService->getPublishedDocumentTypeForNode(
			$activity->type,
			$activity->properties,
		);

		// The published type is client-resolved, so being authorized for $documentType is not enough to be
		// handed the catalog this node is served in.
		$accessResult = $this->validateResolvedDocumentTypeAccess($publishedDocumentType, $documentType);
		if (!$accessResult->isSuccess())
		{
			$this->addErrors($accessResult->getErrors());

			return null;
		}

		$filterAvailability = DesignerContainer::getNodeFilterAvailability();
		$relationsAvailable = Feature::instance()->areComplexNodeConnectionsAvailable();

		$catalog = Container::instance()->getCapabilityCatalogService()->getCatalogForNode(
			activityType: $activity->type,
			filterAvailable: $filterAvailability->isRuntimeAvailable($fixedDocumentType, $documentType),
			relationsAvailable: $relationsAvailable,
			documentType: $filterAvailability->resolveEffectiveDocumentType($publishedDocumentType, $documentType),
			filterModuleSupported: $filterAvailability->supportsModule($fixedDocumentType, $documentType),
		);

		if ($catalog === null)
		{
			$this->addError(ErrorMessage::ACTIVITY_NOT_FOUND->getError());
			return null;
		}

		return CapabilityCatalogResponseDto::fromNodeCapabilityCatalog($catalog);
	}

	public function saveSettingsAction(
		SaveSettingsRequestDto $saveSettingsRequest,
		ActivityData $activity,
		array $documentType,
	): ?array
	{
		$result = $this->validateCurrentUserOperateDocument(
			operation: CBPCanUserOperateOperation::CreateWorkflow,
			documentType: $documentType,
		);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());
			return null;
		}

		$complexActivityService = Container::instance()->getComplexActivityService();
		$complexActivityName = strtolower($activity->type);
		$nodeDescription = $complexActivityService->getActivityDescriptionByCode($complexActivityName);

		$configurator = \CBPActivity::createConfigurator($activity->type, $activity->properties);
		if (!$this->isNodeSettingsSurfaceAvailable($complexActivityName, $configurator, $nodeDescription))
		{
			$this->addActivityNotFoundError($activity->type);
			return null;
		}

		// The node is saved in the document context it publishes - that is the context its children are
		// validated against - so the authorization for $documentType alone does not carry over to it.
		$accessResult = $this->validateResolvedDocumentTypeAccess(
			$complexActivityService->getPublishedDocumentTypeForNode($activity->type, $activity->properties),
			$documentType,
		);
		if (!$accessResult->isSuccess())
		{
			$this->addErrors($accessResult->getErrors());

			return null;
		}

		if (empty($saveSettingsRequest->portRuleCollectionDictionary))
		{
			$this->addError(ErrorMessage::UNKNOWN_ERROR->getError());
			return null;
		}

		// Runtime gate of the relations surface, the same one loadSettingsAction() and the capability
		// catalog apply: with the feature off - or the node declaring no relation action - the node has
		// no relations block, so a payload carrying relations is ignored instead of being normalised and
		// written. Like load, this is not an error - the request is answered without the surface the node
		// does not have; relation data saved before these gates existed is dropped on the first
		// successful save instead of locking the node out of saving at all.
		$relationsSurfaceAvailable = $this->isRelationsSurfaceAvailable(
			$nodeDescription,
			Feature::instance()->areComplexNodeConnectionsAvailable(),
		);
		$relationPortRuleCollectionDictionary = $relationsSurfaceAvailable
			? $saveSettingsRequest->relationPortRuleCollectionDictionary
			: []
		;

		$activity = $this->applySettingsToActivity($configurator, $activity, $saveSettingsRequest, $relationsSurfaceAvailable);
		if (!$activity)
		{
			$this->addError(ErrorMessage::UNKNOWN_ERROR->getError());
			return null;
		}

		$portRuleDtoDictionary = $this->extractRulePropertyValue($configurator, $activity);
		if (!$portRuleDtoDictionary)
		{
			$this->addError(ErrorMessage::UNKNOWN_ERROR->getError());
			return null;
		}

		$relationPortRuleDtoDictionary = [];
		if (!empty($relationPortRuleCollectionDictionary))
		{
			$expectedRelationActionClass = $complexActivityService
				->getRelationActionActivity($complexActivityName)
				?->getClass()
			;

			// The surface is available, yet the relation activity cannot be resolved (a declared code
			// with no backing activity, or a legacy descriptor no gate can decide): an unresolvable
			// configuration, not a permission problem. The message names the action the cards ask for,
			// then the one the node declares, and only falls back to the host node type when neither
			// identifier exists.
			if ($expectedRelationActionClass === null)
			{
				$missingActivity = $this->findSubmittedRelationActionId($relationPortRuleCollectionDictionary)
					?? $this->findDeclaredRelationActionCode($complexActivityService, $complexActivityName)
					?? $activity->type
				;

				$this->addError(ErrorMessage::ACTIVITY_NOT_FOUND->getError(['#ACTIVITY#' => $missingActivity]));
				return null;
			}

			$hasUnfilledRelationCard = false;
			foreach ($relationPortRuleCollectionDictionary as $portId => $ruleCollection)
			{
				try
				{
					$relationPortRuleDto = PortRuleDto::fromArray($ruleCollection);

					if (!$this->isRelationActionAllowed($relationPortRuleDto, $expectedRelationActionClass))
					{
						$this->addError(ErrorMessage::ACCESS_DENIED->getError());
						return null;
					}

					// A card the user started but left without an action is unfinished, not forbidden,
					// so it takes the same NotFilled path as any rule that fails validation. That path is
					// chosen after the loop, so every remaining port still gets its action checked.
					if ($this->hasRuleMissingRelationAction($relationPortRuleDto))
					{
						$hasUnfilledRelationCard = true;
					}

					// Normalising the rest is pointless once the node goes the NotFilled path.
					if ($hasUnfilledRelationCard)
					{
						continue;
					}

					$saveRuleResult = (new SaveSingleRuleCommand($relationPortRuleDto, $documentType))->run();
				}
				catch (Exception)
				{
					$this->addError(ErrorMessage::UNKNOWN_ERROR->getError());
					return null;
				}

				if (!$saveRuleResult instanceof SaveSingleRuleCommandResult)
				{
					$this->addErrors($saveRuleResult->getErrors());
					return null;
				}

				$relationPortRuleDtoDictionary[$portId] = $saveRuleResult->portRuleDto;
			}

			if ($hasUnfilledRelationCard)
			{
				return [
					'activity' => $this->markActivityFilled($activity, isFilled: false),
				];
			}

			$activity = $this->applyRelationsToActivity($activity, $relationPortRuleDtoDictionary);
		}

		try
		{
			$result = (new ConvertRuleCommand(
				activity: $activity,
				portRuleDtoDictionary: $portRuleDtoDictionary,
				documentType: $documentType,
				relationPortRuleDtoDictionary: $relationPortRuleDtoDictionary,
			))->run();
		}
		catch (CommandValidateException $e)
		{
			return [
				'activity' => $this->markActivityFilled($activity, isFilled: false),
			];
		}
		catch (Exception)
		{
			$this->addError(ErrorMessage::UNKNOWN_ERROR->getError());
			return null;
		}

		if (!$result instanceof ConvertRuleCommandResult)
		{
			$this->addErrors($result->getErrors());
			return null;
		}

		// Once more on the merged properties: a base-settings construction is host-merged into the node by
		// ConvertRuleCommand, and for a trigger those very properties are what its published document type is
		// read off - so the type checked before the conversion is not necessarily the one the node ends up in.
		$mergedAccessResult = $this->validateResolvedDocumentTypeAccess(
			$complexActivityService->getPublishedDocumentTypeForNode(
				$result->activityData->type,
				$result->activityData->properties,
			),
			$documentType,
		);
		if (!$mergedAccessResult->isSuccess())
		{
			$this->addErrors($mergedAccessResult->getErrors());

			return null;
		}

		return [
			'activity' => $this->markActivityFilled($result->activityData),
		];
	}

	public function saveRuleAction(
		PortRuleDto $portRule,
		array $documentType,
	): ?PortRuleDto {
		$result = $this->validateCurrentUserOperateDocument(
			operation: CBPCanUserOperateOperation::CreateWorkflow,
			documentType: $documentType,
		);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());
			return null;
		}

		try
		{
			$result = (new SaveSingleRuleCommand($portRule, $documentType))->run();
		}
		catch (Exception)
		{
			$this->addError(ErrorMessage::UNKNOWN_ERROR->getError());
			return null;
		}

		if (!$result instanceof SaveSingleRuleCommandResult)
		{
			$this->addErrors($result->getErrors());
			return null;
		}

		return $result->portRuleDto;
	}

	/**
	 * @param ActivityData $activity
	 * @param array<string, PortRuleDto> $relationPortRuleDtoDictionary
	 * @return ActivityData
	 */
	private function applyRelationsToActivity(ActivityData $activity, array $relationPortRuleDtoDictionary): ActivityData
	{
		$activityArray = $activity->toArray();
		// Recursively expand via JsonSerializable (jsonSerialize() alone only expands one level
		// and would leave nested RuleDto/ConstructionDto objects instead of plain arrays).
		$activityArray['Properties']['Relations'] = array_map(
			static fn(PortRuleDto $dto) => Json::decode(Json::encode($dto)),
			$relationPortRuleDtoDictionary,
		);

		return ActivityData::createFromArray($activityArray);
	}

	/**
	 * No rule card may carry an action other than the expected relation action, nor a construction
	 * type outside the relation-port allowlist. The type check runs here, before any NotFilled
	 * return, because those returns persist the activity while ConvertRuleCommand (the allowlist's
	 * other enforcement point) never runs on them — a base-settings/filter smuggled next to an
	 * unfinished card would otherwise reach Properties.Relations.
	 */
	private function isRelationActionAllowed(PortRuleDto $portRuleDto, string $expectedActionClass): bool
	{
		foreach ($portRuleDto->rules as $rule)
		{
			foreach ($rule->constructions as $construction)
			{
				if (
					!in_array(
						$construction->constructionType,
						ValidateSingleRuleCommand::RELATION_PORT_CONSTRUCTION_TYPES,
						true,
					)
				)
				{
					return false;
				}

				if ($construction->constructionType !== ConstructionType::ACTION)
				{
					continue;
				}

				$expression = $construction->expression;
				if (!$expression instanceof ActionExpressionDto || $expression->actionId !== $expectedActionClass)
				{
					return false;
				}

				// actionId alone isn't what ends up in Children: ConvertRuleCommand builds the child
				// activity from activityData/rawActivityData, so that actual type must match too,
				// otherwise a client could pass a matching actionId but a different node-action type.
				$actualActionType = $expression->rawActivityData['activityType']
					?? $expression->activityData['Type']
					?? null
				;
				if ($actualActionType !== null && $actualActionType !== $expectedActionClass)
				{
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * A relation card without an action is an unfinished card, whatever else it carries: the port only
	 * makes sense once its relation action is chosen. An empty port and an empty card are the extreme
	 * cases of the same state — ValidateSingleRuleCommand treats an empty rule list as filled, so they
	 * would otherwise save without the NotFilled marker.
	 */
	private function hasRuleMissingRelationAction(PortRuleDto $portRuleDto): bool
	{
		if ($portRuleDto->rules === [])
		{
			return true;
		}

		foreach ($portRuleDto->rules as $rule)
		{
			if (empty($rule->constructions))
			{
				return true;
			}

			foreach ($rule->constructions as $construction)
			{
				if ($construction->constructionType === ConstructionType::ACTION)
				{
					continue 2;
				}
			}

			return true;
		}

		return false;
	}

	/**
	 * The relation action a client asks for lives in the submitted cards, so an unresolvable relation
	 * declaration can be reported by that identifier instead of by the host node type.
	 *
	 * @param array<string, mixed> $relationPortRuleCollectionDictionary
	 */
	private function findSubmittedRelationActionId(array $relationPortRuleCollectionDictionary): ?string
	{
		foreach ($relationPortRuleCollectionDictionary as $ruleCollection)
		{
			foreach ((array)($ruleCollection['ruleCards'] ?? []) as $ruleCard)
			{
				foreach ((array)($ruleCard['constructions'] ?? []) as $construction)
				{
					if (($construction['type'] ?? null) !== ConstructionType::ACTION->value)
					{
						continue;
					}

					$expression = (array)($construction['expression'] ?? []);
					$actionId = $expression['actionId']
						?? $expression['rawActivityData']['activityType']
						?? $expression['activityData']['Type']
						?? null
					;
					if (is_string($actionId) && $actionId !== '')
					{
						return $actionId;
					}
				}
			}
		}

		return null;
	}

	/**
	 * The node descriptor names its relation action by activity code, so a declaration whose activity
	 * cannot be resolved is reported by that code instead of by the host node type. Null means the node
	 * declares no relation action at all: then no action identifier exists to name.
	 */
	private function findDeclaredRelationActionCode(
		ComplexActivityService $complexActivityService,
		string $complexActivityName,
	): ?string
	{
		$declaredCode = $complexActivityService
			->getActivityDescriptionByCode($complexActivityName)
			?->getComplexActivitySettings()
			?->relationAction
			?->activityCode
		;

		return ($declaredCode === null || $declaredCode === '') ? null : $declaredCode;
	}

	/**
	 * The relations surface exists for a node only when the runtime feature flag allows it and the
	 * node's descriptor declares a relation action - the same gate the capability catalog reports to
	 * the frontend via availableBlocks. Undecided descriptors (no description, no complex settings,
	 * no declared availableBlocks) keep the plain feature-flag fallback, mirroring the catalog.
	 */
	private function isRelationsSurfaceAvailable(
		?ActivityDescription $nodeDescription,
		bool $relationsAvailable,
	): bool
	{
		if (!$relationsAvailable)
		{
			return false;
		}

		if ($nodeDescription === null)
		{
			return true;
		}

		return Container::instance()
			->getCapabilityCatalogService()
			->getRelationsAvailabilityForNode($nodeDescription, relationsAvailable: true) ?? true
		;
	}

	/**
	 * The refused activity is named in the message: leaving the placeholder unreplaced is what turned every
	 * refusal into the same "#ACTIVITY#", telling neither the user nor the log which node was meant. Only an
	 * activity code is substituted - the same character set {@see \CBPActivity::createInstance()} accepts -
	 * so a type the client made up is reported without its own text reaching the message.
	 */
	private function addActivityNotFoundError(string $activityType): void
	{
		$replace = preg_match('#\A[a-zA-Z0-9_]+\z#', $activityType) === 1
			? ['#ACTIVITY#' => $activityType]
			: []
		;

		$this->addError(ErrorMessage::ACTIVITY_NOT_FOUND->getError($replace));
	}

	/**
	 * Whether the node settings can be built for the activity at all. The configurator answers for a class
	 * rendering its own configurable form; a class implementing no `IBPConfigurableActivity` is answered with
	 * an empty one, and then the descriptor decides: a node served by the unified panel keeps its settings in
	 * the service properties of the panel, not in the properties map of its class, and refusing it here is
	 * what left every translated legacy node unopenable while the catalog kept routing it into the panel.
	 * A non-empty properties map is deliberately not synthesised for such a node - see
	 * {@see ComplexActivityService::resolveRuleProperty()}.
	 *
	 * A node with a descriptor is answered by the rollout gate of the panel alone and never by its configurator:
	 * a translated node with the feature rolled back has to be refused even though its class renders a form of
	 * its own, otherwise the very settings the panel is not offering could still be sent in
	 * ({@see self::isUnifiedPanelSurfaceAvailable()}).
	 *
	 * @param ?ActivityDescription $nodeDescription Description of the node, already gated on owning a
	 *     complex-settings descriptor by {@see ComplexActivityService::getActivityDescriptionByCode()}, so a
	 *     non-null one means "served by the unified panel".
	 */
	private function isNodeSettingsSurfaceAvailable(
		string $activityCode,
		Configurator $configurator,
		?ActivityDescription $nodeDescription,
	): bool
	{
		if ($nodeDescription !== null)
		{
			return $this->isUnifiedPanelSurfaceAvailable($activityCode, $nodeDescription);
		}

		return !empty($configurator->getActivityType());
	}

	/**
	 * Rollout gate of the panel, applied to every surface of it alike: a node whose complex-settings descriptor
	 * was completed for it - a trigger, a base node - is served by the panel only while the feature is rolled
	 * out, so with the flag off the editor stays on the legacy form of that node and the server refuses the
	 * unified settings a client would send for it. A complex node, declaring its descriptor itself, is not what
	 * the flag decides about, and settings the panel saved earlier keep running and keep publishing either way
	 * ({@see \Bitrix\Bizproc\Internal\Service\Activity\UnifiedPanelDescriptorProvider::isSurfaceAvailableForNode()}).
	 */
	private function isUnifiedPanelSurfaceAvailable(
		string $activityCode,
		ActivityDescription $nodeDescription,
	): bool
	{
		return Container::instance()->getUnifiedPanelDescriptorProvider()->isSurfaceAvailableForNode(
			$activityCode,
			$nodeDescription,
			Feature::instance()->areComplexNodeConnectionsAvailable(),
		);
	}

	private function applySettingsToActivity(
		Configurator $configurator,
		ActivityData $activity,
		SaveSettingsRequestDto $saveSettingsRequestDto,
		bool $relationsSurfaceAvailable,
	): ?ActivityData
	{
		$rulePropertyName = $this->resolveRuleProperty($configurator, $activity->type)['FieldName'] ?? null;
		if (!$rulePropertyName)
		{
			return null;
		}

		$newActivity = $activity->toArray();

		$newActivity['Properties'] = [
			...($newActivity['Properties'] ?? []),
			$rulePropertyName => $saveSettingsRequestDto->portRuleCollectionDictionary,
			'Relations' => $saveSettingsRequestDto->relationPortRuleCollectionDictionary,
			'Title' => $saveSettingsRequestDto->title,
			'EditorComment' => $saveSettingsRequestDto->description,
		];

		// With the relations surface unavailable nothing relation-shaped is converted into children
		// either, so the property is dropped rather than left describing links the node does not have;
		// this is also what cleans up relation data saved before the availability gates existed.
		if (!$relationsSurfaceAvailable)
		{
			unset($newActivity['Properties']['Relations']);
		}

		return ActivityData::createFromArray($newActivity);
	}

	/**
	 * The rules property of a node, addressed by name and not by the order of the properties map - the
	 * single lookup of {@see ComplexActivityService::resolveRuleProperty()}, shared with the AI converters.
	 *
	 * @param string $activityType Type of the node: it names the rules of a node whose class renders no
	 *     configurable form, and whose configurator therefore carries no properties map at all.
	 */
	private function resolveRuleProperty(Configurator $configurator, string $activityType = ''): ?array
	{
		return Container::instance()
			->getComplexActivityService()
			->resolveRuleProperty($configurator, $activityType)
		;
	}

	/**
	 * Rules of a node are a collection of containers keyed by input port id, or the single reserved
	 * `ComplexActivityService::PORTLESS_RULES_KEY` for a node without input ports. Neither kind is resolved
	 * against the ports the node actually has - the key only names the container.
	 *
	 * @return array<string, PortRuleDto>|null Null when the node carries no rules property at all, which is
	 *     what tells the caller the node is not served by the unified settings panel.
	 */
	private function extractRulePropertyValue(Configurator $configurator, ActivityData $activity): ?array
	{
		$ruleProperty = $this->resolveRuleProperty($configurator, $activity->type);
		$rulePropertyName = $ruleProperty['FieldName'] ?? null;
		if (!$rulePropertyName)
		{
			return null;
		}

		$rulePropertyValue = $activity->properties[$rulePropertyName] ?? $ruleProperty['Default'] ?? [];
		if (!is_array($rulePropertyValue) || empty($rulePropertyValue))
		{
			return null;
		}

		$portRuleDtoDictionary = [];
		foreach ($rulePropertyValue as $containerKey => $ruleCollection)
		{
			if (!is_array($ruleCollection))
			{
				continue;
			}

			// A container and its payload carry the same name, so a payload that omits it is still
			// addressed by the key it arrived under instead of failing the whole settings load.
			$ruleCollection['portId'] ??= (string)$containerKey;

			$portRuleDtoDictionary[$containerKey] = PortRuleDto::fromArray($ruleCollection);
		}

		return $portRuleDtoDictionary === [] ? null : $portRuleDtoDictionary;
	}

	/**
	 * The document type a node publishes is a second document context the request works in, and it is resolved
	 * from properties the client fully owns - a trigger names the document type of its event in them. Being
	 * authorized for the document type of the edited template says nothing about that one, so a direct request
	 * could name any type, get the capability catalog built in it and save the child actions it allows: every
	 * check downstream only asks whether an action belongs to that catalog, never whether the user may author
	 * workflows on its document.
	 *
	 * A node publishing no type of its own works in the document type of the template, which the caller was
	 * already authorized for; the same holds when the two types are the one and same.
	 *
	 * @param array|null $publishedDocumentType Resolved by
	 *     {@see ComplexActivityService::getPublishedDocumentTypeForNode()} from the properties the request
	 *     carries - and, on the save path, resolved once more from the merged ones.
	 */
	private function validateResolvedDocumentTypeAccess(
		?array $publishedDocumentType,
		array $documentType,
	): Result
	{
		if ($publishedDocumentType === null || $this->isSameDocumentType($publishedDocumentType, $documentType))
		{
			return new Result();
		}

		try
		{
			return $this->validateCurrentUserOperateDocument(
				operation: CBPCanUserOperateOperation::CreateWorkflow,
				documentType: $publishedDocumentType,
			);
		}
		catch (\Throwable)
		{
			// This is an authorization gate, so a document type the CreateWorkflow provider cannot answer
			// for is refused and not waved through - the opposite of the fail-open autofill lookup.
			$result = new Result();
			$result->addError(ErrorMessage::ACCESS_DENIED->getError());

			return $result;
		}
	}

	private function isSameDocumentType(array $documentType, array $otherDocumentType): bool
	{
		return array_map(strval(...), array_values($documentType))
			=== array_map(strval(...), array_values($otherDocumentType))
		;
	}

	/**
	 * @param \CBPCanUserOperateOperation::* $operation
	 * @param array $documentType
	 * @return Result
	 */
	private function validateCurrentUserOperateDocument(int $operation, array $documentType): Result
	{
		$result = new Result();

		// Null while no current user was set on the controller: an unauthenticated request is refused by the
		// permission check itself instead of failing on the call.
		$userId = (int)($this->getCurrentUser()?->getId() ?? 0);
		$canOperate = CBPDocument::CanUserOperateDocumentType(
			$operation,
			$userId,
			$documentType,
		);

		if (!$canOperate)
		{
			$result->addError(ErrorMessage::ACCESS_DENIED->getError());
		}

		return $result;
	}

	private function markActivityFilled(ActivityData $activityData, bool $isFilled = true): ActivityData
	{
		$activityData = $activityData->toArray();
		if ($isFilled)
		{
			unset($activityData['Properties']['NotFilled']);
		}
		else
		{
			$activityData['Properties']['NotFilled'] = 'Y';
		}

		return ActivityData::createFromArray($activityData);
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Activity;

use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Internal\Entity\Workflow\ExecutionPayload;
use Bitrix\Bizproc\Public\Activity\Mixins\NodeFilterResultProperties;
use Bitrix\Bizproc\Public\Activity\Structure\FlowDirectedActivity;
use Bitrix\Bizproc\Internal\Entity\Activity\Interface\FlowCompositeActivity;
use Bitrix\Bizproc\Internal\Service\Activity\ChildValidationContext;
use Bitrix\Bizproc\Internal\Service\Container;
use Bitrix\Main\Localization\Loc;
use CBPActivity;
use CBPWorkflowTemplateUser;
use IBPConfigurableActivity;

/**
 * @property-read $Rules
 * @property-read $InputNames
 * @property-read $OutputNames
 */
abstract class BaseComplexActivity extends FlowDirectedActivity implements
	IBPConfigurableActivity,
	FlowCompositeActivity
{
	use NodeFilterResultProperties;

	protected const RULES_PARAM = 'Rules';
	protected const RELATIONS_PARAM = 'Relations';
	protected const INPUT_ACTIVITY_NAMES = 'InputNames';
	protected const OUTPUT_ACTIVITY_NAMES = 'OutputNames';
	protected const NOT_FILLED_MARK = 'NotFilled';

	protected array $queuePortIds = [];

	protected function __construct($name)
	{
		parent::__construct($name);

		$this->arProperties = array_merge(
			$this->arProperties,
			[
				static::RULES_PARAM => [],
				static::RELATIONS_PARAM => [],
				static::INPUT_ACTIVITY_NAMES => [],
				static::OUTPUT_ACTIVITY_NAMES => [],
			],
			static::getFilterPropertyDefaults(),
		);

		$this->setPropertiesTypes([
			static::RULES_PARAM => [
				'Type' => FieldType::RULES,
			],
			...static::getFilterPropertyTypes(),
		]);
	}

	public function executeWithPayload(ExecutionPayload $payload): int
	{
		$this->queuePortIds[] = $payload->getInputPort();

		return $this->execute();
	}

	public function execute(): int
	{
		$this->initializeFilterResultProperties();

		return parent::execute();
	}

	public static function validateChild($childActivity, $bFirstChild = false)
	{
		return [
			...static::validateUnifiedPanelChild(static::getActivityName(), $childActivity),
			...parent::validateChild($childActivity, $bFirstChild),
		];
	}

	/**
	 * The verdict on one child of a node served by the unified settings panel. Both surfaces of the panel reach
	 * it from here - a complex node above and an ordinary node the panel serves
	 * ({@see \CBPActivity::validateChild()}) - so a second verdict cannot appear.
	 *
	 * Lives here and not next to the ordinary node because the phrase of the verdict does: `Loc::getMessage()`
	 * loads the language file of the file it is called from.
	 *
	 * The white list itself belongs to the service that owns both the action catalog and the relation action,
	 * and is memoized there per node
	 * ({@see \Bitrix\Bizproc\Internal\Service\Activity\ComplexActivityService::getUnifiedPanelChildCodes()}):
	 * answered by the descriptor of the node alone - the class of a child is never loaded - so a child costs one
	 * array read and never a traversal, on every entry into a template alike: the designer, an import, REST and
	 * the AI converter.
	 *
	 * @param string $activityCode Code of the node the child is being given to.
	 * @param mixed $childActivity Type of the child, in the shape a template carries it.
	 * @param array|null $documentType Document context the node works in: the catalog the white list is built
	 *     of excludes an action locked in that context, so the verdict here is the one the settings panel of
	 *     the very same node gave. Omitted, the context published by the running validation pass answers
	 *     ({@see ChildValidationContext}); no pass running and no context given keeps the context-free
	 *     catalog - the widest of the answers, and the one every caller got before the context existed.
	 * @return list<array{code: string, message: string}>
	 */
	public static function validateUnifiedPanelChild(
		string $activityCode,
		$childActivity,
		?array $documentType = null,
	): array
	{
		$container = Container::instance();
		$allowedCodes = $container->getComplexActivityService()->getUnifiedPanelChildCodes(
			$activityCode,
			$documentType ?? ChildValidationContext::getDocumentType(),
		);
		$childCode = $container->getActivitySearcherService()->normalizeActivityCode((string)$childActivity);

		if (isset($allowedCodes[$childCode]))
		{
			return [];
		}

		return [
			[
				'code' => 'WrongChildType',
				'message' => Loc::getMessage('BIZPROC_PUBLIC_ACTIVITY_BCA_INVALID_CHILD'),
			],
		];
	}

	protected static function getActivityName(): string
	{
		return static::getActivityCode();
	}

	/**
	 * @return list<string>
	 */
	protected function getStartActivityNames(): array
	{
		$matchedInputNames = array_intersect_key(
			$this->getRawProperty(static::INPUT_ACTIVITY_NAMES),
			array_flip($this->queuePortIds)
		);

		// A single input port may carry several rules: its InputNames entry is then a list of input
		// activity names (all rules start when the port fires). A single-rule port keeps a scalar entry,
		// so (array) normalises both shapes without changing single-rule behaviour.
		$startNames = [];
		foreach ($matchedInputNames as $entry)
		{
			foreach ((array)$entry as $name)
			{
				$startNames[] = $name;
			}
		}

		return $startNames;
	}

	protected function onDeadEndReached(CBPActivity $lastActivity): array
	{
		$outputNames = $this->getRawProperty(static::OUTPUT_ACTIVITY_NAMES);

		$portId = 0;
		$nameWithPort = static::createOutputName($lastActivity->getName(), $lastActivity->getOutputPortId());
		if (isset($outputNames[$nameWithPort]))
		{
			$portId = (int)$outputNames[$nameWithPort];
		}

		$this->outputPortId = $portId;

		return [];
	}

	protected function close(): void
	{
		$this->queuePortIds = [];

		parent::close();
	}

	public static function validateProperties($arTestProperties = [], ?CBPWorkflowTemplateUser $user = null): array
	{
		$arErrors = [];
		if (empty($arTestProperties[self::RULES_PARAM]))
		{
			$arErrors[] = [
				'code' => 'NotExist',
				'parameter' => self::RULES_PARAM,
				'message' => Loc::getMessage('BIZPROC_PUBLIC_ACTIVITY_BCA_EMPTY_RULES'),
			];
		}

		if (($arTestProperties[static::NOT_FILLED_MARK] ?? 'N') === 'Y')
		{
			$arErrors[] = [
				'code' => 'NotExist',
				'message' => Loc::getMessage('BIZPROC_PUBLIC_ACTIVITY_BCA_NOT_FILLED'),
			];
		}

		return array_merge($arErrors, parent::validateProperties($arTestProperties, $user));
	}

	protected static function getPropertiesMap(array $documentType, array $context = []): array
	{
		$complexActivityService = Container::instance()->getComplexActivityService();

		return [
			...$complexActivityService->configureRuleProperty(),
		];
	}

	public static function getPropertiesDialogValues(
		$documentType,
		$activityName,
		&$workflowTemplate,
		&$workflowParameters,
		&$workflowVariables,
		$currentValues,
		&$errors,
	): bool
	{
		// todo: realize base logic
		$errors = [];
		$properties = [];

		$documentService = \CBPRuntime::getRuntime()->getDocumentService();
		$map = static::getPropertiesMap($documentType, is_array($currentValues) ? $currentValues : []);

		foreach ($map as $id => $property)
		{
			$value = $documentService->getFieldInputValue(
				$documentType,
				$property,
				$property['FieldName'],
				$currentValues,
				$errors,
			);

			if (!empty($errors))
			{
				return false;
			}

			$properties[$id] = $value;
		}

		$currentActivity = &\CBPWorkflowTemplateLoader::findActivityByName($workflowTemplate, $activityName);
		$currentActivity['Properties'] = $properties;

		return true;
	}
}

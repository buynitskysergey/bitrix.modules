<?php

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service;

use Bitrix\Bizproc\Activity\Dto\NodeSettings;
use Bitrix\Bizproc\Activity\Enum\ActivityNodeType;
use Bitrix\Bizproc\Activity\Enum\ActivityType;
use Bitrix\Bizproc\Internal\Entity\Activity\Result\ActivityAiDescriptionResult;
use Bitrix\BizprocDesigner\Infrastructure\Dto\Catalog\NodeCatalogItemDtoFactory;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\ActivityVisibilityFilter;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Result\BlockDescriptionResult;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Result\BlockSettingsResult;
use Bitrix\BizprocDesigner\Internal\Entity\BlockType;
use Bitrix\BizprocDesigner\Internal\Entity\BlockTypeCollection;
use Bitrix\BizprocDesigner\Internal\Entity\BlockTypeDetail;
use Bitrix\BizprocDesigner\Internal\Entity\DocumentDescription;
use Bitrix\BizprocDesigner\Internal\Entity\ReturnField;
use Bitrix\BizprocDesigner\Internal\Entity\ReturnFieldCollection;
use Bitrix\Main\Diag\LoggerFactory;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class BlockDescriptionService
{
	private const ACTIVITY_AI_DESCRIPTION = 'AI_DESCRIPTION';
	private const ACTIVITY_DESCRIPTION = 'DESCRIPTION';
	private const ACTIVITY_NAME = 'NAME';
	private const LOGGER_ID = 'bizprocdesigner.aiassistant.block_catalog';

	private ?LoggerInterface $logger = null;

	/**
	 * The activity catalogs this service has read, by the pair (kind of search, document type).
	 *
	 * {@see \CBPRuntime::searchActivitiesByType()} builds its answer anew on every call, while describing a
	 * graph asks for the catalog once per distinct block type. The document type belongs in the key because
	 * the catalog is computed for it: a shared entry would answer a lead template with the blocks of a deal.
	 *
	 * Held on the instance, not in a static: a static would outlive the request in a cli process and go on
	 * answering with the catalog as it used to be.
	 *
	 * @var array<string, mixed>
	 */
	private array $catalogsBySearch = [];

	/**
	 * @throws LoaderException
	 */
	public function getBlocksWithDescription(DocumentDescription $documentType): Result|BlockDescriptionResult
	{
		if (!Loader::includeModule('bizproc'))
		{
			return (new Result())->addError(new Error(Loc::getMessage('BIZPROCDESIGNER_BLOCK_DESCRIPTION_ERR_MODULE_NOT_INSTALLED')));
		}

		$activities = $this->searchActivities('activity', $documentType);
		if (!is_array($activities))
		{
			return (new Result())->addError(new Error(Loc::getMessage('BIZPROCDESIGNER_BLOCK_DESCRIPTION_ERR_SEARCH_ACTIVITIES')));
		}

		$triggers = $this->searchActivities('trigger', $documentType);
		if (!is_array($triggers))
		{
			return (new Result())->addError(new Error(Loc::getMessage('BIZPROCDESIGNER_BLOCK_DESCRIPTION_ERR_SEARCH_ACTIVITIES')));
		}

		$activities += $triggers;

		// node-typed activities are the only channel that lists complex nodes (they are ActivityType::NODE,
		// not ActivityType::ACTIVITY). Union by code (`+=`): OPERATORS are dually typed activity+node, so the
		// already-present activity entry wins and is not duplicated. The per-block isNodeCompatibleFromRaw
		// gate below applies the shared visibility guards to every entry the union pulls in.
		$nodes = $this->searchActivities('node', $documentType);
		if (!is_array($nodes))
		{
			return (new Result())->addError(new Error(Loc::getMessage('BIZPROCDESIGNER_BLOCK_DESCRIPTION_ERR_SEARCH_ACTIVITIES')));
		}

		$activities += $nodes;

		$blocks = new BlockTypeCollection();
		foreach ($activities as $type => $activity)
		{
			if (isset($activity['EXCLUDED']) && $activity['EXCLUDED'] === true)
			{
				continue;
			}

			// Apply the same placement guards as the REST catalog (AgentBlockCatalogService):
			// deprecated activities and composite/incompatible-topology containers must not
			// appear in either catalog, regardless of whether they have an AI description.
			if (ActivityVisibilityFilter::isDeprecatedFromRaw($activity))
			{
				continue;
			}

			if (!ActivityVisibilityFilter::isNodeCompatibleFromRaw($activity))
			{
				continue;
			}

			$description = $this->getBlockDescription($activity);
			if (!$type || !$description)
			{
				continue;
			}

			$settingsResult = $this->getActivityAiDescription((string)$type, $documentType);
			if (!$settingsResult instanceof ActivityAiDescriptionResult)
			{
				$this->logSkippedBlock((string)$type, $settingsResult);

				continue;
			}

			$blocks->add(
				new BlockType(
					type: $type,
					description: $description,
				)
			);
		}

		return new BlockDescriptionResult($blocks);
	}

	/**
	 * @throws LoaderException
	 */
	public function getBlockSettings(DocumentDescription $documentType, string $blockType): Result|BlockSettingsResult
	{
		if (!Loader::includeModule('bizproc'))
		{
			return (new Result())->addError(new Error(Loc::getMessage('BIZPROCDESIGNER_BLOCK_DESCRIPTION_ERR_MODULE_NOT_INSTALLED')));
		}

		$typeToSearch = str_ends_with(mb_strtolower($blockType), 'trigger') ? 'trigger' : 'activity';
		$activities = $this->searchActivities($typeToSearch, $documentType);

		$activity = is_array($activities) ? ($activities[$blockType] ?? null) : null;
		if (!is_array($activity))
		{
			// node-only blocks (e.g. storage nodes) are not part of the activity/trigger universe
			$nodes = $this->searchActivities('node', $documentType);
			$activity = is_array($nodes) ? ($nodes[$blockType] ?? null) : null;
		}

		if (!is_array($activity) || ($activity['EXCLUDED'] ?? false) === true)
		{
			return (new Result())->addError(new Error(Loc::getMessage('BIZPROCDESIGNER_BLOCK_DESCRIPTION_ERR_BLOCK_NOT_FOUND')));
		}

		// The block detail must never bypass the listing's visibility predicate: an incompatible-topology
		// block (e.g. a SERVICE, TOOL, or FRAME node) is rejected here exactly as it is hidden from the listing.
		// Compatible node-only blocks (storage nodes, OPERATORS) stay reachable through the node fallback above.
		if (!ActivityVisibilityFilter::isNodeCompatibleFromRaw($activity))
		{
			return (new Result())->addError(new Error(Loc::getMessage('BIZPROCDESIGNER_BLOCK_DESCRIPTION_ERR_BLOCK_NOT_FOUND')));
		}

		$result = $this->getActivityAiDescription($blockType, $documentType);
		if (!$result instanceof ActivityAiDescriptionResult)
		{
			$this->logSkippedBlock($blockType, $result);

			return (new Result())
				->addError(new Error(Loc::getMessage('BIZPROCDESIGNER_BLOCK_DESCRIPTION_ERR_SETTINGS_NOT_DESCRIBED')))
				->addErrors($result->getErrors())
			;
		}

		return new BlockSettingsResult(new BlockTypeDetail(
			block: new BlockType(
				type: $blockType,
				description: $this->getBlockDescription($activity),
			),
			settings: $result->settings,
			returnFields: $this->getReturnFields($activity),
			describedTypes: $result->settings->getDescribedSettingTypesMap(),
			skippedSettings: $result->skippedSettings,
			nodeSettings: $this->resolveNodeSettings($activity),
			complexDetail: (new ComplexBlockDetailFactory())->build($blockType, $this->resolveNodeType($activity), $documentType->toBizprocComplexType()),
		));
	}

	/**
	 * The raw activity catalog of a kind of search for a document type, read once per service.
	 *
	 * Same answer {@see \CBPRuntime::searchActivitiesByType()} gives, including the `false` it answers a
	 * search it cannot run with - the callers judge that themselves and must keep judging it.
	 */
	private function searchActivities(string $type, DocumentDescription $documentType): mixed
	{
		$complexType = $documentType->toBizprocComplexType();
		$key = $type . '|' . implode('|', $complexType);

		return $this->catalogsBySearch[$key] ??= $this->readActivityCatalog($type, $complexType);
	}

	/**
	 * The single read of the catalog from the runtime of bizproc, kept apart from the memory of it above so
	 * that the reads can be counted: they are unobservable from the answers of the service.
	 */
	protected function readActivityCatalog(string $type, array $documentType): mixed
	{
		return \CBPRuntime::getRuntime()->searchActivitiesByType($type, $documentType);
	}

	/**
	 * Resolves the node topology (width/height/ports) for a raw catalog activity using the same source as
	 * the node editor: the explicit NODE_SETTINGS array, falling back to the shared per-type defaults.
	 * Raw-array counterpart of {@see AgentBlockCatalogService::resolveNodeSettings()}; never introduces a
	 * parallel AI-side port description.
	 */
	private function resolveNodeSettings(array $activity): NodeSettings
	{
		$nodeSettings = $activity['NODE_SETTINGS'] ?? null;
		if (is_array($nodeSettings))
		{
			return NodeSettings::fromArray($nodeSettings);
		}

		return NodeCatalogItemDtoFactory::makeDefaultSettingsByType($this->resolveNodeType($activity));
	}

	private function resolveNodeType(array $activity): ?string
	{
		$nodeType = $activity['NODE_TYPE'] ?? null;
		if (is_string($nodeType) && $nodeType !== '')
		{
			return $nodeType;
		}

		if (in_array(ActivityType::TRIGGER->value, (array)($activity['TYPE'] ?? []), true))
		{
			return ActivityNodeType::TRIGGER->value;
		}

		return null;
	}

	private function getBlockDescription(array $activity): string
	{
		$fieldPriority = [
			self::ACTIVITY_AI_DESCRIPTION,
			self::ACTIVITY_DESCRIPTION,
			self::ACTIVITY_NAME,
		];

		foreach ($fieldPriority as $fieldName)
		{
			$value = $activity[$fieldName] ?? '';
			if (is_string($value) && $value !== '')
			{
				return $value;
			}
		}

		return '';
	}

	private function getReturnFields(array $activity): ReturnFieldCollection
	{
		$activityReturn = (array)($activity['RETURN'] ?? []);
		$returnFields = new ReturnFieldCollection();

		foreach ($activityReturn as $name => $field)
		{
			$description = (string)($field['NAME'] ?? '');
			if ($description === '')
			{
				continue;
			}

			$type = (string)($field['TYPE'] ?? '');
			if ($type === '')
			{
				continue;
			}

			$returnFields->add(
				new ReturnField(
					name: $name,
					description: $description,
					type: $type,
				)
			);
		}

		return $returnFields;
	}

	private function getActivityAiDescription(
		string $blockType,
		DocumentDescription $documentType
	): Result|ActivityAiDescriptionResult
	{
		return \CBPRuntime::getRuntime()
			->getAiDescriptionService()
			->getActivityDescription($blockType, $documentType->toBizprocComplexType())
		;
	}

	private function logSkippedBlock(string $blockType, Result $result): void
	{
		$reasons = [];
		foreach ($result->getErrors() as $error)
		{
			$code = (string)$error->getCode();
			$reasons[] = ($code !== '' && $code !== '0' ? $code . ': ' : '') . $error->getMessage();
		}

		$this->getLogger()->info(
			'AI block catalog: activity is excluded from the catalog',
			[
				'activity' => $blockType,
				'reasons' => $reasons,
			],
		);
	}

	private function getLogger(): LoggerInterface
	{
		if ($this->logger === null)
		{
			$this->logger = (new LoggerFactory())->createById(self::LOGGER_ID) ?? new NullLogger();
		}

		return $this->logger;
	}
}
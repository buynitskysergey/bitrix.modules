<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service;

use Bitrix\Bizproc\Activity\ActivityDescription;
use Bitrix\Bizproc\Activity\Dto\NodeSettings;
use Bitrix\Bizproc\Activity\Enum\ActivityNodeType;
use Bitrix\Bizproc\Activity\Enum\ActivityType;
use Bitrix\Bizproc\Internal\Entity\Activity\Result\ActivityAiDescriptionResult;
use Bitrix\Bizproc\Internal\Service\Container as BizprocContainer;
use Bitrix\Bizproc\Runtime\ActivitySearcher\Activities;
use Bitrix\BizprocDesigner\Infrastructure\Dto\Catalog\NodeCatalogItemDtoFactory;
use Bitrix\BizprocDesigner\Internal\Entity\BlockType;
use Bitrix\BizprocDesigner\Internal\Entity\BlockTypeCollection;
use Bitrix\BizprocDesigner\Internal\Entity\BlockTypeDetail;
use Bitrix\BizprocDesigner\Internal\Entity\DocumentDescription;
use Bitrix\BizprocDesigner\Internal\Entity\ReturnField;
use Bitrix\BizprocDesigner\Internal\Entity\ReturnFieldCollection;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\ActivityVisibilityFilter;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Result\BlockDescriptionResult;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Result\BlockSettingsResult;
use Bitrix\Main\Diag\LoggerFactory;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Catalog of workflow activities for the external agent REST API.
 * Uses Searcher + Activities directly to work with typed ActivityDescription objects
 * and apply document-type filters before returning results.
 */
final class AgentBlockCatalogService
{
	private const LOGGER_ID = 'bizprocdesigner.aiassistant.block_catalog';

	private ?LoggerInterface $logger = null;

	/**
	 * @throws LoaderException
	 */
	public function getBlocksWithDescription(DocumentDescription $documentType): Result|BlockDescriptionResult
	{
		if (!Loader::includeModule('bizproc'))
		{
			return (new Result())->addError(new Error(Loc::getMessage('BIZPROCDESIGNER_BLOCK_CATALOG_ERR_MODULE_NOT_INSTALLED')));
		}

		$documentBizprocType = $documentType->toBizprocComplexType();

		/** @var Activities<ActivityDescription> $activities */
		$activities = BizprocContainer::instance()
			->getActivitySearcherService()
			->searchByType(
				[
					ActivityType::NODE->value,
					ActivityType::TRIGGER->value,
				],
				$documentBizprocType,
			)
			->computeDescriptionFilter($documentBizprocType)
			->sort()
		;

		$blocks = new BlockTypeCollection();
		foreach ($activities as $type => $activityDescription)
		{
			if ($activityDescription->getExcluded())
			{
				continue;
			}

			if ($activityDescription->getDeprecated())
			{
				continue;
			}

			if (!$this->isNodeCompatible($activityDescription))
			{
				continue;
			}

			$presets = $activityDescription->getPresets();
			if ($presets !== null && count($presets) > 0)
			{
				foreach ($presets as $preset)
				{
					$presetApplied = $activityDescription->applyPreset($preset);
					$description = $this->resolveDescription($presetApplied);
					if (!$description)
					{
						continue;
					}

					$settingsResult = $this->getActivityAiDescription(
						(string)$type,
						$documentBizprocType,
					);

					if (!$settingsResult instanceof ActivityAiDescriptionResult)
					{
						$this->logSkippedBlock((string)$type . '#' . $preset['ID'], $settingsResult);

						continue;
					}

					$blocks->add(
						new BlockType(
							type: (string)$type,
							description: $description,
							presetId: (string)$preset['ID'],
						),
					);
				}

				continue;
			}

			$description = $this->resolveDescription($activityDescription);
			if (!$type || !$description)
			{
				continue;
			}

			$settingsResult = $this->getActivityAiDescription(
				(string)$type,
				$documentBizprocType,
			);

			if (!$settingsResult instanceof ActivityAiDescriptionResult)
			{
				$this->logSkippedBlock((string)$type, $settingsResult);

				continue;
			}

			$blocks->add(
				new BlockType(
					type: $type,
					description: $description,
				),
			);
		}

		return new BlockDescriptionResult($blocks);
	}

	/**
	 * @throws LoaderException
	 */
	public function getBlockSettings(DocumentDescription $documentType, string $blockType, string $presetId = ''): Result|BlockSettingsResult
	{
		if (!Loader::includeModule('bizproc'))
		{
			return (new Result())->addError(new Error(
				Loc::getMessage('BIZPROCDESIGNER_BLOCK_CATALOG_ERR_MODULE_NOT_INSTALLED')),
			);
		}

		$documentBizprocType = $documentType->toBizprocComplexType();
		$searcher = BizprocContainer::instance()->getActivitySearcherService();

		$activityDescription = $searcher->searchByCode($blockType);
		if ($activityDescription === null)
		{
			return (new Result())->addError(new Error(Loc::getMessage('BIZPROCDESIGNER_BLOCK_CATALOG_ERR_BLOCK_NOT_FOUND')));
		}

		// computeDescriptionFilter mutates $activityDescription's excluded flag in-place
		(new Activities([$blockType => $activityDescription]))->computeDescriptionFilter($documentBizprocType);
		if ($activityDescription->getExcluded())
		{
			return (new Result())->addError(new Error(
				Loc::getMessage('BIZPROCDESIGNER_BLOCK_CATALOG_ERR_BLOCK_NOT_FOUND')),
			);
		}

		// The block detail must never bypass the listing's visibility predicate: an incompatible-topology
		// block (e.g. a SERVICE or TOOL node) is rejected here exactly as it is hidden from the listing,
		// so visibility and buildability change together. FRAME (the frame overlay) passes both, and its
		// empty property dialog yields an empty settings schema without being skipped.
		if (!$this->isNodeCompatible($activityDescription))
		{
			return (new Result())->addError(new Error(
				Loc::getMessage('BIZPROCDESIGNER_BLOCK_CATALOG_ERR_BLOCK_NOT_FOUND')),
			);
		}

		$defaultValues = null;
		if ($presetId !== '')
		{
			$preset = $activityDescription->getPresetById($presetId);
			if ($preset === null)
			{
				return (new Result())->addError(new Error(Loc::getMessage('BIZPROCDESIGNER_BLOCK_CATALOG_ERR_BLOCK_NOT_FOUND')));
			}

			$activityDescription = $activityDescription->applyPreset($preset);

			$rawProperties = $preset['PROPERTIES'] ?? [];
			$filteredProperties = [];
			foreach ($rawProperties as $key => $value)
			{
				if (is_string($value) && (str_starts_with($value, '{=') || str_starts_with($value, '{{=')))
				{
					continue;
				}

				$filteredProperties[$key] = $value;
			}

			$defaultValues = $filteredProperties;
		}

		$result = $this->getActivityAiDescription($blockType, $documentBizprocType);
		if (!$result instanceof ActivityAiDescriptionResult)
		{
			$this->logSkippedBlock($blockType, $result);

			return (new Result())
				->addError(new Error(Loc::getMessage('BIZPROCDESIGNER_BLOCK_CATALOG_ERR_SETTINGS_NOT_DESCRIBED')))
				->addErrors($result->getErrors())
			;
		}

		return new BlockSettingsResult(new BlockTypeDetail(
			block: new BlockType(
				type: $blockType,
				description: $this->resolveDescription($activityDescription),
				presetId: $presetId !== '' ? $presetId : null,
			),
			settings: $result->settings,
			returnFields: $this->buildReturnFields($activityDescription->getReturn()),
			describedTypes: $result->settings->getDescribedSettingTypesMap(),
			skippedSettings: $result->skippedSettings,
			defaultValues: $defaultValues,
			nodeSettings: $this->resolveNodeSettings($activityDescription),
			complexDetail: (new ComplexBlockDetailFactory())->build($blockType, $activityDescription->getNodeType(), $documentBizprocType),
		));
	}

	/**
	 * Returns false for node topologies that the agent draft converter cannot build.
	 *
	 * Delegates to the shared ActivityVisibilityFilter predicate so that both the REST
	 * catalog and the Marta catalog apply identical placement guards.
	 *
	 * @see ActivityVisibilityFilter::isNodeCompatible()
	 * @see ActivityVisibilityFilter::LEGACY_COMPOSITE_CLASS_BLACKLIST
	 */
	private function isNodeCompatible(ActivityDescription $d): bool
	{
		return ActivityVisibilityFilter::isNodeCompatible($d);
	}

	private function resolveDescription(ActivityDescription $activity): string
	{
		foreach ([
			$activity->get('AI_DESCRIPTION'),
			$activity->getDescription(),
			$activity->getName(),
		] as $value)
		{
			if (is_string($value) && $value !== '')
			{
				return $value;
			}
		}

		return '';
	}

	/**
	 * Resolves the node topology (width/height/ports) for a block using the same source as the
	 * node editor: explicit ActivityDescription::getNodeSettings(), falling back to the shared
	 * per-type defaults when the activity declares none. Never introduces a parallel AI-side port
	 * description.
	 */
	private function resolveNodeSettings(ActivityDescription $activityDescription): NodeSettings
	{
		$nodeSettings = $activityDescription->getNodeSettings();
		if ($nodeSettings !== null)
		{
			return $nodeSettings;
		}

		return NodeCatalogItemDtoFactory::makeDefaultSettingsByType(
			$this->resolveNodeType($activityDescription),
		);
	}

	private function resolveNodeType(ActivityDescription $activityDescription): ?string
	{
		$nodeType = $activityDescription->getNodeType();
		if ($nodeType !== null)
		{
			return $nodeType;
		}

		if (in_array(ActivityType::TRIGGER->value, $activityDescription->getType(), true))
		{
			return ActivityNodeType::TRIGGER->value;
		}

		return null;
	}

	private function buildReturnFields(array $activityReturn): ReturnFieldCollection
	{
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
				),
			);
		}

		return $returnFields;
	}

	private function getActivityAiDescription(
		string $blockType,
		array $documentBizprocType
	): Result|ActivityAiDescriptionResult
	{
		return \CBPRuntime::getRuntime()
			->getAiDescriptionService()
			->getActivityDescription($blockType, $documentBizprocType)
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

<?php

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Validator;

use Bitrix\BizprocDesigner\Internal\Entity\BlockTypeDetail;
use Bitrix\BizprocDesigner\Internal\Entity\DocumentDescription;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Cache\BlockDescriptionCache;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentBlock;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentSettingCollection;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Enum\GraphErrorCode;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Enum\RequestSource;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Error\GraphError;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Result\BlockSettingsResult;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Sanitizer\FrameStyleSanitizer;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\AgentBlockMetadataResolver;
use Bitrix\Main\DI\Exception\CircularDependencyException;
use Bitrix\Main\DI\Exception\ServiceNotFoundException;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Main\Result;

final class AgentBlockValidator
{
	// Sanitary length caps, kept in sync with the tool input schema (SaveWorkflowTool::getInputSchema).
	private const TITLE_MAX_LENGTH = 255;
	private const DESCRIPTION_MAX_LENGTH = 1000;
	private const PRESET_ID_MAX_LENGTH = 255;

	/** Frame background/border colours the editor offers (FRAME_COLOR_NAMES in the chart extension). */
	private const ALLOWED_FRAME_COLOR_NAMES = ['grey', 'orange', 'green', 'blue', 'purple', 'pink'];

	/** Fields only a frame overlay carries, in the order the block resource declares them {@see validateFrame}. */
	private const FRAME_ONLY_FIELDS = ['memberBlockIds', 'frameColorName', 'frameContent'];

	private readonly BlockDescriptionCache $blockDescriptionCache;
	private readonly AgentBlockSettingsValidator $settingsValidator;
	private readonly AgentBlockMetadataResolver $metadataResolver;
	private readonly AgentComplexRulesValidator $complexRulesValidator;
	private readonly RequestSource $source;
	private ?AgentBlock $validBlock = null;
	private ?BlockTypeDetail $validBlockDetail = null;
	private ?string $id = null;

	public function __construct(
		BlockDescriptionCache $blockDescriptionCache,
		?AgentBlockSettingsValidator $settingsValidator = null,
		?AgentBlockMetadataResolver $metadataResolver = null,
		?AgentComplexRulesValidator $complexRulesValidator = null,
		RequestSource $source = RequestSource::Rest,
	) {
		$this->blockDescriptionCache = $blockDescriptionCache;
		$this->settingsValidator = $settingsValidator ?? new AgentBlockSettingsValidator();
		$this->metadataResolver = $metadataResolver ?? new AgentBlockMetadataResolver();
		$this->complexRulesValidator = $complexRulesValidator ?? new AgentComplexRulesValidator();
		$this->source = $source;
	}

	public function validate(
		mixed $block,
		DocumentDescription $documentType,
		array $blackListIds = [],
		string $path = '',
	): Result
	{
		if (!is_array($block))
		{
			return (new Result())->addError(GraphError::at($path, "$path should be object"));
		}
		$this->validBlock = null;
		$this->validBlockDetail = null;
		$this->id = null;

		$id = $block['id'] ?? null;
		$title = $block['title'] ?? null;
		$description = $block['description'] ?? null;
		$presetId = $block['presetId'] ?? null;
		$type = $block['type'] ?? null;
		$settings = $block['settings'] ?? null;

		// The frame overlay is not an activity with ports/dialog, so it bypasses the catalog lookup entirely
		// and validates its own shape instead. Only the REST agent may build frames; for Marta this branch is
		// skipped and the frame falls through to validateType(), where it is rejected as an incorrect type.
		if ($this->source === RequestSource::Rest && FrameBlockMatcher::matches($type, $presetId))
		{
			$frameResult = $this->validateFrame($block, $id, $title, $type, $presetId, $path, $blackListIds);
			$this->id = $id;

			return $frameResult;
		}

		$result = new Result();
		$result->addErrors($this->validateId($id, $path, $blackListIds)->getErrors());
		$result->addErrors($this->validateTitle($title, $path)->getErrors());
		$result->addErrors($this->validateDescription($description, $path)->getErrors());
		$result->addErrors($this->validatePresetId($presetId, $path)->getErrors());
		$typeValidationResult = $this->validateType($type, $path, $documentType);
		$blockDetail = $typeValidationResult instanceof BlockSettingsResult ? $typeValidationResult->blockDetail : null;
		$result->addErrors($typeValidationResult->getErrors());
		if ($blockDetail !== null)
		{
			try
			{
				$result->addErrors($this->validateSystemName($type, $presetId, $path)->getErrors());
				if (is_string($type) && is_string($presetId) && $presetId !== '' && !$this->metadataResolver->hasPreset($type, $presetId))
				{
					$result->addError(GraphError::at(
						"{$path}.presetId",
						"{$path}.presetId is not a valid preset for this block type",
					));
				}
			}
			catch (ServiceNotFoundException | CircularDependencyException | ObjectNotFoundException)
			{
				$result->addError(GraphError::at(
					"{$path}.type",
					"{$path}.type block system name cannot be resolved (catalog unavailable)",
				));
			}
		}
		$settingValidationResult = $this->settingsValidator->validate($settings, "$path.settings", $blockDetail);
		$result->addErrors($settingValidationResult->getErrors());

		$validRules = null;
		if ($blockDetail !== null)
		{
			if ($blockDetail->complexDetail !== null)
			{
				$rulesValidationResult = $this->complexRulesValidator->validate(
					$block['rules'] ?? null,
					$blockDetail->complexDetail,
					"$path.rules",
				);
				$result->addErrors($rulesValidationResult->getErrors());
				$validRules = $this->complexRulesValidator->getValidRules();
			}
			elseif (array_key_exists('rules', $block) && $block['rules'] !== null)
			{
				$result->addError(GraphError::at("$path.rules", "$path.rules is only allowed for complex nodes"));
			}
		}

		// Same refusal as rules on a plain block, for the same reason: the overlay fields belong to a frame,
		// and dropping them without a word leaves the caller believing it grouped and annotated blocks that
		// stayed plain.
		foreach (self::FRAME_ONLY_FIELDS as $frameField)
		{
			if (array_key_exists($frameField, $block) && $block[$frameField] !== null)
			{
				$result->addError(GraphError::at(
					"$path.$frameField",
					"$path.$frameField is only allowed for frame blocks",
				));
			}
		}

		if ($result->isSuccess() && $this->settingsValidator->getValidSettings())
		{
			$this->validBlock = new AgentBlock(
				$type,
				$title ?? '',
				$id,
				$this->settingsValidator->getValidSettings(),
				$description,
				is_string($presetId) && $presetId !== '' ? $presetId : null,
				$validRules,
			);
			$this->validBlockDetail = $blockDetail;
		}

		$this->id = $id;

		return $result;
	}

	/**
	 * Validates a frame overlay's own shape (id + styling allowlist), sanitises its free-text styling and
	 * builds the valid frame block. Membership (memberBlockIds) and member connectivity are delegated to
	 * {@see AgentFrameMembershipValidator}, which runs once over the whole graph in the workflow validator.
	 */
	private function validateFrame(
		array $block,
		mixed $id,
		mixed $title,
		mixed $type,
		mixed $presetId,
		string $path,
		array $blackListIds,
	): Result
	{
		$result = new Result();
		$result->addErrors($this->validateId($id, $path, $blackListIds)->getErrors());
		$result->addErrors($this->validateTitle($title, $path)->getErrors());

		$frameColorName = $block['frameColorName'] ?? null;
		$result->addErrors($this->validateFrameColorName($frameColorName, $path)->getErrors());

		$frameContent = $block['frameContent'] ?? null;
		$result->addErrors(
			$this->validateOptionalText($frameContent, "{$path}.frameContent", FrameStyleSanitizer::MAX_CONTENT_LENGTH)->getErrors(),
		);

		if ($result->isSuccess())
		{
			$this->validBlock = new AgentBlock(
				type: (string)$type,
				// Stored raw like every other agent title (validated for type/length above); the frontend
				// escapes it once at render time, so a server-side encode would double-escape and break
				// round-trip idempotency.
				title: is_string($title) ? $title : '',
				id: (string)$id,
				settings: new AgentSettingCollection(),
				description: null,
				presetId: (string)$presetId,
				rules: null,
				returnProperties: [],
				memberBlockIds: $this->parseMemberBlockIds($block),
				frameColorName: is_string($frameColorName) ? $frameColorName : null,
				frameContent: is_string($frameContent) ? FrameStyleSanitizer::sanitizeContent($frameContent) : null,
			);
		}

		return $result;
	}

	private function validateFrameColorName(mixed $frameColorName, string $path): Result
	{
		if ($frameColorName === null)
		{
			return new Result();
		}

		$colorPath = "{$path}.frameColorName";

		if (!is_string($frameColorName))
		{
			return (new Result())->addError(GraphError::at($colorPath, "{$colorPath} should be string"));
		}

		if (!in_array($frameColorName, self::ALLOWED_FRAME_COLOR_NAMES, true))
		{
			// The allowed names are nowhere else in the contract - the frame carries an empty settings schema,
			// so the catalog has no field to publish them through - and the refusal is the only place a caller
			// can learn them.
			return (new Result())->addError(GraphError::at(
				$colorPath,
				"{$colorPath} is not an allowed frame colour. Allowed values: "
					. implode(', ', self::ALLOWED_FRAME_COLOR_NAMES),
			));
		}

		return new Result();
	}

	/**
	 * @return list<string>
	 */
	private function parseMemberBlockIds(array $block): array
	{
		$memberBlockIds = [];
		foreach ((array)($block['memberBlockIds'] ?? []) as $memberId)
		{
			if (is_string($memberId) || is_int($memberId))
			{
				$memberBlockIds[] = (string)$memberId;
			}
		}

		return $memberBlockIds;
	}

	private function validateId(mixed $id, string $path, array $blackListIds): Result
	{
		$idPath = "{$path}.id";

		if (!is_string($id) || $id === '')
		{
			return (new Result())->addError(GraphError::at($idPath, "{$idPath} should be not empty string"));
		}

		if (in_array($id, $blackListIds, true))
		{
			return (new Result())->addError(GraphError::at($idPath, "{$idPath} should be unique value"));
		}

		return new Result();
	}

	private function validateTitle(mixed $title, string $path): Result
	{
		return $this->validateOptionalText($title, "{$path}.title", self::TITLE_MAX_LENGTH);
	}

	private function validateDescription(mixed $description, string $path): Result
	{
		return $this->validateOptionalText($description, "{$path}.description", self::DESCRIPTION_MAX_LENGTH);
	}

	private function validatePresetId(mixed $presetId, string $path): Result
	{
		return $this->validateOptionalText($presetId, "{$path}.presetId", self::PRESET_ID_MAX_LENGTH);
	}

	private function validateOptionalText(mixed $value, string $path, int $maxLength): Result
	{
		if ($value === null)
		{
			return new Result();
		}

		if (!is_string($value))
		{
			return (new Result())->addError(GraphError::at($path, "{$path} should be string"));
		}

		if (mb_strlen($value) > $maxLength)
		{
			return (new Result())->addError(
				GraphError::at($path, "{$path} should be at most {$maxLength} characters"),
			);
		}

		return new Result();
	}

	private function validateType(mixed $type, string $path, DocumentDescription $documentType): Result|BlockSettingsResult
	{
		$typePath = "{$path}.type";

		if (!is_string($type) || $type === '')
		{
			return (new Result())->addError(GraphError::at($typePath, "{$typePath} should be not empty string"));
		}

		$blockDetail = $this->blockDescriptionCache->get($type, $documentType);

		if ($blockDetail === null)
		{
			return (new Result())->addError(
				GraphError::at($typePath, "{$typePath} is incorrect type", GraphErrorCode::BlockTypeUnknown),
			);
		}

		return new BlockSettingsResult($blockDetail);
	}

	/**
	 * Fail-closed guard: a block whose type validates must also have a resolvable, non-empty system name.
	 * That name becomes node.title in the draft converter (same source — ActivityDescription::getName()),
	 * so a nameless-but-otherwise-valid type must be rejected here instead of yielding an empty node title.
	 *
	 * Multi-preset activities carry their name on the preset, so the guard resolves with the supplied
	 * presetId: a preset block with a valid presetId passes, while the same type without a presetId (empty
	 * base name) is still rejected — the error hints that a presetId is required for such blocks.
	 */
	private function validateSystemName(mixed $type, mixed $presetId, string $path): Result
	{
		$normalizedPresetId = is_string($presetId) && $presetId !== '' ? $presetId : null;
		$systemName = is_string($type)
			? $this->metadataResolver->resolveSystemName($type, $normalizedPresetId)
			: '';
		if (trim($systemName) === '')
		{
			$typePath = "{$path}.type";
			$message = "{$typePath} block system name is not defined";
			if ($normalizedPresetId === null)
			{
				// The presetId hint only helps when none was supplied; with a bad presetId the
				// "not a valid preset" error already explains the failure, so the hint would mislead.
				$message .= ' (specify presetId for multi-preset blocks)';
			}

			return (new Result())->addError(GraphError::at($typePath, $message));
		}

		return new Result();
	}

	public function getValidBlock(): ?AgentBlock
	{
		return $this->validBlock;
	}

	public function getValidBlockDetail(): ?BlockTypeDetail
	{
		return $this->validBlockDetail;
	}

	public function getId(): mixed
	{
		return $this->id;
	}
}
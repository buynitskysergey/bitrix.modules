<?php

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Validator;

use Bitrix\BizprocDesigner\Internal\Entity\DocumentDescription;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Cache\BlockDescriptionCache;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentBlockCollection;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Enum\GraphErrorCode;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Enum\RequestSource;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Error\GraphError;
use Bitrix\Main\Result;

final class AgentBlocksValidator
{
	/**
	 * Server-side DoS guard, not a business limit: layout is polynomial in the block count (see
	 * TopologyLayoutEngine). Single source of truth for the bound the Marta tool schema advertises via maxItems
	 * ({@see \Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Tool\SaveWorkflowTool::getInputSchema()}),
	 * enforced here so the REST path is guarded too, not only the schema-validated Marta one.
	 */
	public const MAX_BLOCKS = 256;

	private readonly AgentBlockValidator $blockValidator;

	private AgentBlockCollection $validBlocks;

	/**
	 * @var list<string>
	 */
	private array $blockIds = [];

	/**
	 * @var list<string> ids of the valid frame overlays (REST only). Frames carry no ports and are excluded
	 *      from the ordinary connections/connectivity check {@see AgentConnectionsValidator}.
	 */
	private array $frameBlockIds = [];

	/**
	 * @var array<string, array{input: list<string>, output: list<string>, dynamic?: bool}>
	 *      Known ports per block id. Complex nodes carry `dynamic: true` - their port set is projected
	 *      exhaustively from their own rules, so an unknown port is an error even when a direction is
	 *      empty (no "unknown ports -> skip check" bypass in the connections validator).
	 */
	private array $blockPortsMap = [];

	/**
	 * @var array<string, list<string>> loop-back input port ids per block id (loop nodes only)
	 */
	private array $blockLoopbackInputPortsMap = [];

	public function __construct(
		?AgentBlockValidator $blockValidator = null,
		RequestSource $source = RequestSource::Rest,
	)
	{
		$this->validBlocks = new AgentBlockCollection();
		$this->blockValidator = $blockValidator ?? new AgentBlockValidator(new BlockDescriptionCache(), source: $source);
	}

	/**
	 * Error texts are deliberately kept verbatim - the Marta model reads them as hints - so a message names
	 * `blocks` literally even though the address it is reported at comes from $path.
	 */
	public function validate(mixed $blocks, DocumentDescription $documentType, string $path = ''): Result
	{
		$this->blockIds = [];
		$this->frameBlockIds = [];
		$this->blockPortsMap = [];
		$this->blockLoopbackInputPortsMap = [];
		$this->validBlocks = new AgentBlockCollection();

		if (!is_array($blocks))
		{
			return (new Result())->addError(GraphError::at($path, 'blocks should be array'));
		}

		if (empty($blocks))
		{
			return (new Result())->addError(GraphError::at($path, 'blocks should be not empty array'));
		}

		if (count($blocks) > self::MAX_BLOCKS)
		{
			return (new Result())->addError(
				GraphError::at(
					$path,
					'blocks should contain at most ' . self::MAX_BLOCKS . ' items',
					GraphErrorCode::BlockLimitExceeded,
				),
			);
		}

		$result = new Result();
		foreach ($blocks as $key => $block)
		{
			$blockValidateResult = $this->blockValidator->validate(
				block: $block,
				documentType: $documentType,
				blackListIds: $this->blockIds,
				path: "{$path}.{$key}",
			);
			$result->addErrors($blockValidateResult->getErrors());
			$validBlock = $blockValidateResult->isSuccess() ? $this->blockValidator->getValidBlock() : null;
			if ($validBlock !== null)
			{
				$this->validBlocks->add($validBlock);

				if (FrameBlockMatcher::matches($validBlock->type, $validBlock->presetId))
				{
					$this->frameBlockIds[] = $validBlock->id;
				}

				$blockDetail = $this->blockValidator->getValidBlockDetail();
				if ($blockDetail !== null)
				{
					if ($validBlock->rules !== null)
					{
						// Complex node: ports are projected from the block's own rules (dynamic), not from
						// the static catalog topology. Marked `dynamic` so the connections validator does not
						// fall back to its "unknown ports -> skip check" bypass and let the agent reference a
						// non-existent dynamic port. Complex nodes have no loop-back inputs.
						$this->blockPortsMap[$validBlock->id] = [
							'input' => $validBlock->rules->getInputPortIds(),
							'output' => $validBlock->rules->getOutputPortIds(),
							'dynamic' => true,
						];
					}
					else
					{
						$this->blockPortsMap[$validBlock->id] = $blockDetail->getPortIds();

						$loopbackInputPorts = $blockDetail->getLoopbackInputPortIds();
						if (!empty($loopbackInputPorts))
						{
							$this->blockLoopbackInputPortsMap[$validBlock->id] = $loopbackInputPorts;
						}
					}
				}
			}
			if ($this->blockValidator->getId() !== null)
			{
				$this->blockIds[] = $this->blockValidator->getId();
			}
		}

		return $result;
	}

	public function getValidBlocks(): AgentBlockCollection
	{
		return $this->validBlocks;
	}

	/**
	 * @return list<string>
	 */
	public function getBlockIds(): array
	{
		return $this->blockIds;
	}

	/**
	 * @return list<string> ids of the valid frame overlays (empty unless a REST agent sent frames)
	 */
	public function getFrameBlockIds(): array
	{
		return $this->frameBlockIds;
	}

	/**
	 * @return array<string, array{input: list<string>, output: list<string>, dynamic?: bool}>
	 */
	public function getBlockPortsMap(): array
	{
		return $this->blockPortsMap;
	}

	/**
	 * @return array<string, list<string>> loop-back input port ids per block id (loop nodes only)
	 */
	public function getBlockLoopbackInputPortsMap(): array
	{
		return $this->blockLoopbackInputPortsMap;
	}
}
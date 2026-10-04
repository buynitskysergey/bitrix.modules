<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Validator;

use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Enum\GraphErrorCode;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Error\GraphError;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Graph\UndirectedConnectivity;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Validates the logical membership contract of frame overlays sent by the external REST agent.
 *
 * A frame does not own ports; it declares which existing graph blocks it groups (memberBlockIds) and how
 * they are laid out is computed by the server later. This validator enforces the membership contract:
 *   - non-empty membership;
 *   - every member is an existing graph block;
 *   - no self-reference and no reference to another frame (frames are not nestable);
 *   - a block belongs to at most one frame (frames do not overlap);
 *   - members form a connected sub-graph over the UNDIRECTED induced edges (branching/loops included).
 *
 * Member edges reaching outside the frame are allowed; only the membership itself must be connected.
 * Every error is anchored at blocks.<frameIndex> so the REST response can recover the frame's blockId.
 */
final class AgentFrameMembershipValidator
{
	public function validate(mixed $blocks, mixed $connections, string $path = 'blocks'): Result
	{
		$result = new Result();
		if (!is_array($blocks))
		{
			return $result;
		}

		$allBlockIds = [];
		$frameBlockIds = [];
		$frames = [];
		foreach ($blocks as $index => $block)
		{
			if (!is_array($block))
			{
				continue;
			}

			$id = $block['id'] ?? null;
			if (is_string($id) && $id !== '')
			{
				$allBlockIds[$id] = true;
			}

			if (FrameBlockMatcher::matches($block['type'] ?? null, $block['presetId'] ?? null))
			{
				$frameId = is_string($id) ? $id : '';
				if ($frameId !== '')
				{
					$frameBlockIds[$frameId] = true;
				}
				$frames[] = ['index' => $index, 'id' => $frameId, 'members' => $block['memberBlockIds'] ?? null];
			}
		}

		if ($frames === [])
		{
			return $result;
		}

		$adjacency = UndirectedConnectivity::buildAdjacency($connections);
		$memberOwner = [];

		foreach ($frames as $frame)
		{
			$result->addErrors(
				$this->validateFrame($frame, $allBlockIds, $frameBlockIds, $adjacency, $memberOwner, $path)->getErrors(),
			);
		}

		return $result;
	}

	/**
	 * @param array{index: int|string, id: string, members: mixed} $frame
	 * @param array<string, true> $allBlockIds
	 * @param array<string, true> $frameBlockIds
	 * @param array<string, list<string>> $adjacency
	 * @param array<string, int|string> $memberOwner mutated: member id => owning frame index
	 */
	private function validateFrame(
		array $frame,
		array $allBlockIds,
		array $frameBlockIds,
		array $adjacency,
		array &$memberOwner,
		string $path,
	): Result
	{
		$result = new Result();
		$prefix = "{$path}.{$frame['index']}";
		$members = $frame['members'];

		if (!is_array($members) || $members === [])
		{
			return $result->addError($this->membershipError($prefix, 'memberBlockIds is empty'));
		}

		$validMembers = [];
		$claimed = [];
		$hasShapeError = false;
		foreach ($members as $member)
		{
			if (!is_string($member) || $member === '')
			{
				$result->addError($this->membershipError($prefix, 'memberBlockIds contains a non-string id'));
				$hasShapeError = true;
				continue;
			}

			if ($member === $frame['id'])
			{
				$result->addError($this->membershipError($prefix, 'frame cannot reference itself'));
				$hasShapeError = true;
				continue;
			}

			if (isset($frameBlockIds[$member]))
			{
				$result->addError($this->membershipError($prefix, "member '{$member}' is another frame"));
				$hasShapeError = true;
				continue;
			}

			if (!isset($allBlockIds[$member]))
			{
				$result->addError($this->membershipError($prefix, "member '{$member}' does not exist"));
				$hasShapeError = true;
				continue;
			}

			if (isset($memberOwner[$member]) || isset($claimed[$member]))
			{
				$result->addError($this->membershipError($prefix, "member '{$member}' belongs to another frame"));
				$hasShapeError = true;
				continue;
			}

			$claimed[$member] = true;
			$validMembers[] = $member;
		}

		if ($hasShapeError)
		{
			return $result;
		}

		// Commit ownership only after every shape check passed, so a frame that later turns out invalid never
		// leaves its members "taken" - a following frame that legitimately groups one of them must not be told
		// it belongs to another frame.
		foreach ($validMembers as $member)
		{
			$memberOwner[$member] = $frame['index'];
		}

		if (!UndirectedConnectivity::isConnected($validMembers, $adjacency))
		{
			$result->addError($this->membershipError($prefix, 'members are not connected'));
		}

		return $result;
	}

	/**
	 * Every membership failure answers the same way: addressed at the frame's position in the submitted
	 * graph, classed as a broken membership, and carrying the address inside the text for the Marta path.
	 */
	private function membershipError(string $framePath, string $reason): Error
	{
		return GraphError::at($framePath, "{$framePath}: {$reason}", GraphErrorCode::FrameMembershipViolated);
	}
}

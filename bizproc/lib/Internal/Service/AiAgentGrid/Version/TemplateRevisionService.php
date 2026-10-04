<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\AiAgentGrid\Version;

use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable;
use Bitrix\Main\Web\Json;

/**
 * Computes a stable revision (hash) of an AI-agent template's logic.
 *
 * The revision is a deterministic fingerprint of the activity tree stored in
 * the `TEMPLATE` field. It is the single source of truth both for "which version
 * of the reference logic is installed on a copy" and for detecting customization
 * (copy logic diverged from its installed version).
 *
 * Invariants:
 *  - Only the logic (`TEMPLATE`) participates in the hash. `CONSTANTS` are user
 *    settings, not logic, and never affect the revision.
 *  - Non-semantic serialization differences do not change the revision: keys of
 *    associative arrays are sorted recursively before hashing, so the ordering
 *    of properties is irrelevant.
 *  - Ordering of sequential lists (activity children) IS semantic and is
 *    preserved as-is.
 *  - Normalization is stable between cloud and box and between restarts.
 */
class TemplateRevisionService
{
	/**
	 * Revision of the logic stored on the given template row, or null when the
	 * template does not exist or has no logic to fingerprint.
	 */
	public function getTemplateRevision(int $templateId): ?string
	{
		if ($templateId <= 0)
		{
			return null;
		}

		$row = WorkflowTemplateTable::query()
			->setSelect(['TEMPLATE'])
			->where('ID', $templateId)
			->setLimit(1)
			->fetch()
		;

		if ($row === false)
		{
			return null;
		}

		$template = $row['TEMPLATE'] ?? null;
		if (!is_array($template) || empty($template))
		{
			return null;
		}

		return $this->calculateRevision($template);
	}

	/**
	 * Deterministic revision of a template logic tree (the `TEMPLATE` field value).
	 *
	 * @param array $template Decoded `TEMPLATE` activity tree.
	 */
	public function calculateRevision(array $template): string
	{
		$normalized = $this->normalize($template);

		return hash('sha256', Json::encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
	}

	/**
	 * Recursively normalizes a value so that semantically equal logic always
	 * produces the same serialized form:
	 *  - associative arrays: keys are sorted;
	 *  - sequential lists: order preserved;
	 *  - scalars: returned unchanged.
	 */
	private function normalize(mixed $value): mixed
	{
		if (!is_array($value))
		{
			return $value;
		}

		if (array_is_list($value))
		{
			return array_map(fn($item) => $this->normalize($item), $value);
		}

		$normalized = [];
		foreach ($value as $key => $item)
		{
			$normalized[$key] = $this->normalize($item);
		}
		ksort($normalized);

		return $normalized;
	}
}

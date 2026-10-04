<?php

namespace Bitrix\BizprocDesigner\Internal\Entity;

use Bitrix\Main\Type\Contract\Arrayable;

/**
 * A single allowed sub-action of a complex node, projected for the AI block catalog (DTO-02).
 *
 * The dictionary element mirrors what the manual editor collects for a complex node, but keeps only
 * the fields the agent needs. Data comes from the same source as the manual editor
 * ({@see \Bitrix\Bizproc\Internal\Service\Activity\ComplexActivityService}) - never a parallel AI description.
 *
 * @param list<array> $settingsSchema machine-readable settings schema of the sub-action (DTO-04): a list of
 *     {@see \Bitrix\Bizproc\Internal\Entity\Activity\Setting::toArray()} entries, built by the same domain
 *     service that describes ordinary nodes ({@see \Bitrix\Bizproc\Service\AiDescription::getActivityDescription}).
 *     An empty list is valid - an HTML-only sub-action or a schema that is unavailable in the environment.
 * @param array|null $documentSchema machine-readable contract of the sub-action's `document` construction
 *     field: only the FORMAT of the value (a bizproc expression `{=<sourceBlockId>:<propertyId>}`), never the
 *     concrete value set - that is a function of the graph topology and thus uncomputable in the catalog.
 *     `null` when the sub-action does not handle the document (`handlesDocument:false`) or the complex node has
 *     no fixed document type; in that case the key is not emitted at all (additive, symmetric to an empty
 *     settings schema - not a regression).
 */
class ComplexNodeAction implements Arrayable
{
	public function __construct(
		public readonly string $activityCode,
		public readonly string $title,
		public readonly bool $handlesDocument,
		public readonly int $sort = 0,
		public readonly ?string $presetId = null,
		public readonly array $settingsSchema = [],
		public readonly ?array $documentSchema = null,
	) {}

	public function toArray(): array
	{
		$result = [
			'activityCode' => $this->activityCode,
			'title' => $this->title,
			'handlesDocument' => $this->handlesDocument,
			'sort' => $this->sort,
			'presetId' => $this->presetId,
			'settingsSchema' => $this->settingsSchema,
		];

		if ($this->documentSchema !== null)
		{
			$result['documentSchema'] = $this->documentSchema;
		}

		return $result;
	}
}

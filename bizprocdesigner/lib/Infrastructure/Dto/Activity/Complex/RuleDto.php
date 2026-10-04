<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex;

use Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\Rule\ConstructionDto;
use JsonSerializable;

class RuleDto implements JsonSerializable
{
	/**
	 * @param string $id
	 * @param list<ConstructionDto> $constructions
	 * @param string|null $groupTitle Optional human-readable group name. Does not affect execution semantics.
	 */
	public function __construct(
		public string $id,
		public array $constructions,
		public ?string $groupTitle = null,
	)
	{
	}

	public function jsonSerialize(): array
	{
		$data = [
			'id' => $this->id,
			'constructions' => $this->constructions,
		];

		// Only include groupTitle when it is set — additive, preserves wire compatibility.
		if ($this->groupTitle !== null)
		{
			$data['groupTitle'] = $this->groupTitle;
		}

		return $data;
	}

	public static function fromArray(array $data): self
	{
		$constructionList = [];

		foreach ($data['constructions'] as $construction)
		{
			$constructionList[] = ConstructionDto::fromArray($construction);
		}

		// groupTitle is optional — absence means null (backward compatibility with existing templates).
		$groupTitle = isset($data['groupTitle']) && is_string($data['groupTitle'])
			? $data['groupTitle']
			: null;

		return new self(
			$data['id'],
			$constructionList,
			$groupTitle,
		);
	}
}

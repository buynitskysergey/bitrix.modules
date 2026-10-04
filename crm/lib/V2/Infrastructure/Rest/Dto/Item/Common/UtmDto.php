<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common;

use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Rule\Length;
use Bitrix\Crm\V2\Public\Entity\Item\Utm;
use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Dto\Dto;

class UtmDto extends Dto
{
	#[Editable]
	#[Length(max: 255)]
	#[Filterable]
	public ?string $source;

	#[Editable]
	#[Length(max: 255)]
	#[Filterable]
	public ?string $medium;

	#[Editable]
	#[Length(max: 255)]
	#[Filterable]
	public ?string $campaign;

	#[Editable]
	#[Length(max: 255)]
	#[Filterable]
	public ?string $content;

	#[Editable]
	#[Length(max: 255)]
	#[Filterable]
	public ?string $term;

	public static function __set_state(array $array): static
	{
		$dto = new static();
		if (isset($array['source']))
		{
			$dto->source = $array['source'];
		}
		if (isset($array['medium']))
		{
			$dto->medium = $array['medium'];
		}
		if (isset($array['campaign']))
		{
			$dto->campaign = $array['campaign'];
		}
		if (isset($array['content']))
		{
			$dto->content = $array['content'];
		}
		if (isset($array['term']))
		{
			$dto->term = $array['term'];
		}

		return $dto;
	}

	public static function fromEntity(?Utm $utm, array $select = []): ?self
	{
		if (!$utm)
		{
			return null;
		}

		$dto = new self();
		if (empty($select) || in_array('source', $select, true))
		{
			$dto->source = $utm->getSource();
		}
		if (empty($select) || in_array('medium', $select, true))
		{
			$dto->medium = $utm->getMedium();
		}
		if (empty($select) || in_array('campaign', $select, true))
		{
			$dto->campaign = $utm->getCampaign();
		}
		if (empty($select) || in_array('content', $select, true))
		{
			$dto->content = $utm->getContent();
		}
		if (empty($select) || in_array('term', $select, true))
		{
			$dto->term = $utm->getTerm();
		}

		return $dto;
	}
}

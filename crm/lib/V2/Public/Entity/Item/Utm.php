<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Item;

class Utm
{
	private array $changedFields = [];

	private ?string $source = null;

	private ?string $medium = null;

	private ?string $campaign = null;

	private ?string $content = null;

	private ?string $term = null;

	public function getSource(): ?string
	{
		return $this->source;
	}

	public function setSource(?string $source): static
	{
		$this->source = $source;
		$this->markChanged('source');

		return $this;
	}

	public function getMedium(): ?string
	{
		return $this->medium;
	}

	public function setMedium(?string $medium): static
	{
		$this->medium = $medium;
		$this->markChanged('medium');

		return $this;
	}

	public function getCampaign(): ?string
	{
		return $this->campaign;
	}

	public function setCampaign(?string $campaign): static
	{
		$this->campaign = $campaign;
		$this->markChanged('campaign');

		return $this;
	}

	public function getContent(): ?string
	{
		return $this->content;
	}

	public function setContent(?string $content): static
	{
		$this->content = $content;
		$this->markChanged('content');

		return $this;
	}

	public function getTerm(): ?string
	{
		return $this->term;
	}

	public function setTerm(?string $term): static
	{
		$this->term = $term;
		$this->markChanged('term');

		return $this;
	}

	// --- Changed-tracking ---

	/**
	 * Returns whether a specific field changed, or — when $fieldName is null — whether any field changed.
	 *
	 * @internal
	 */
	public function isChanged(?string $fieldName = null): bool
	{
		if ($fieldName === null)
		{
			return !empty($this->changedFields);
		}

		return isset($this->changedFields[$fieldName]);
	}

	/**
	 * @internal
	 */
	public function resetChanged(): void
	{
		$this->changedFields = [];
	}

	protected function markChanged(string $fieldName): void
	{
		$this->changedFields[$fieldName] = true;
	}
}

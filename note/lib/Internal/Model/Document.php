<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Model;

use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Web\Json;

class Document extends EO_Document
{
	/**
	 * Raw stored MARKDOWN column value, without the CONTENT_FORMAT interpretation getMarkdown()
	 * applies. Used to compare against a client-supplied markdown string (e.g. the compact no-op
	 * guard), where the raw snapshot — not a decoded tree — is the right baseline.
	 */
	public function getMarkdownRaw(): string
	{
		return (string)$this->sysGetValue('MARKDOWN');
	}

	public function getMarkdown(): array|string
	{
		$value = $this->sysGetValue('MARKDOWN');
		if (is_array($value))
		{
			return $value;
		}
		if ($value === null || $value === '')
		{
			return [];
		}

		if ($this->getContentFormat() === 'md')
		{
			return (string)$value;
		}

		try
		{
			$decoded = Json::decode($value);
		}
		catch (\Bitrix\Main\ArgumentException)
		{
			return [];
		}

		return is_array($decoded) ? $decoded : [];
	}

	public function setMarkdown($value): self
	{
		if (is_array($value))
		{
			$value = Json::encode($value);
		}

		$this->sysSetValue('MARKDOWN', $value);

		return $this;
	}

	public function getContentUpdatedAt(): ?DateTime
	{
		$value = $this->sysGetValue('CONTENT_UPDATED_AT');

		return $value instanceof DateTime ? $value : null;
	}

	public function setContentUpdatedAt(?DateTime $value): self
	{
		$this->sysSetValue('CONTENT_UPDATED_AT', $value);

		return $this;
	}

	public function getMaterializedUptoId(): ?int
	{
		$value = $this->sysGetValue('MATERIALIZED_UPTO_ID');

		return $value === null ? null : (int)$value;
	}

	public function setMaterializedUptoId(?int $value): self
	{
		$this->sysSetValue('MATERIALIZED_UPTO_ID', $value);

		return $this;
	}

	public function isDerivedStale(): bool
	{
		return (bool)$this->getIsDerivedStale();
	}

	public function setDerivedStale(bool $value): self
	{
		$this->setIsDerivedStale($value);

		return $this;
	}
}

<?php
declare(strict_types=1);

namespace Bitrix\Disk\FilePicker;

use Bitrix\Main\Result;

final class SignedConfigBuilder
{
	private array $payload = [
		'version' => SignedConfig::VERSION,
		'selectionMode' => SignedConfig::SELECTION_MODE_SINGLE,
		'maxItems' => null,
		'allowedFileTypes' => [],
		'initialStage' => null,
		'expiresAt' => null,
		'userId' => null,
		'siteId' => null,
	];

	public static function create(): self
	{
		return new self();
	}

	public function setSelectionMode(string $selectionMode): self
	{
		$this->payload['selectionMode'] = $selectionMode;

		return $this;
	}

	public function setMaxItems(?int $maxItems): self
	{
		$this->payload['maxItems'] = $maxItems;

		return $this;
	}

	public function setAllowedFileTypes(array $fileTypes): self
	{
		$this->payload['allowedFileTypes'] = $fileTypes;

		return $this;
	}

	public function setInitialStage(?array $initialStage): self
	{
		$this->payload['initialStage'] = $initialStage;

		return $this;
	}

	public function setExpiresAt(?int $expiresAt): self
	{
		$this->payload['expiresAt'] = $expiresAt;

		return $this;
	}

	public function bindUser(?int $userId): self
	{
		$this->payload['userId'] = $userId;

		return $this;
	}

	public function bindSite(?string $siteId): self
	{
		$this->payload['siteId'] = $siteId;

		return $this;
	}

	public function bindCurrentSite(): self
	{
		$this->payload['siteId'] = defined('SITE_ID') && SITE_ID !== '' ? (string)SITE_ID : null;

		return $this;
	}

	public function build(): Result
	{
		return SignedConfig::signPayload($this->payload);
	}
}

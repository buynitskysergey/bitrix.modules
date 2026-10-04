<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Item\Trait;

use Bitrix\Crm\V2\Public\Entity\Item\EmailCollection;
use Bitrix\Crm\V2\Public\Entity\Item\ImCollection;
use Bitrix\Crm\V2\Public\Entity\Item\PhoneCollection;
use Bitrix\Crm\V2\Public\Entity\Item\WebCollection;

trait HasMultifieldsTrait
{
	private ?PhoneCollection $phones = null;
	private ?EmailCollection $emails = null;
	private ?WebCollection $webs = null;
	private ?ImCollection $ims = null;
	private ?bool $hasPhone = null;
	private ?bool $hasEmail = null;
	private ?bool $hasImol = null;

	public function getPhones(): ?PhoneCollection
	{
		return $this->phones;
	}

	public function setPhones(PhoneCollection $phones): static
	{
		$this->phones = $phones;
		$this->markChanged(self::phones);

		return $this;
	}

	public function getEmails(): ?EmailCollection
	{
		return $this->emails;
	}

	public function setEmails(EmailCollection $emails): static
	{
		$this->emails = $emails;
		$this->markChanged(self::emails);

		return $this;
	}

	public function getWebs(): ?WebCollection
	{
		return $this->webs;
	}

	public function setWebs(WebCollection $webs): static
	{
		$this->webs = $webs;
		$this->markChanged(self::webs);

		return $this;
	}

	public function getIms(): ?ImCollection
	{
		return $this->ims;
	}

	public function setIms(ImCollection $ims): static
	{
		$this->ims = $ims;
		$this->markChanged(self::ims);

		return $this;
	}

	// Readonly flags computed by legacy when multifields are saved
	public function getHasPhone(): ?bool
	{
		return $this->hasPhone;
	}

	public function getHasEmail(): ?bool
	{
		return $this->hasEmail;
	}

	public function getHasImol(): ?bool
	{
		return $this->hasImol;
	}

	protected function internalSetMultifieldField(string $fieldName, mixed $value): bool
	{
		$success = true;
		match ($fieldName)
		{
			self::phones => $this->phones = $value,
			self::emails => $this->emails = $value,
			self::webs => $this->webs = $value,
			self::ims => $this->ims = $value,
			self::hasPhone => $this->hasPhone = $value,
			self::hasEmail => $this->hasEmail = $value,
			self::hasImol => $this->hasImol = $value,
			default => $success = false,
		};

		return $success;
	}
}

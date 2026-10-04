<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Item\Trait;

use Bitrix\Crm\V2\Public\Entity\Item\Contact;
use Bitrix\Crm\V2\Public\Entity\Item\ContactBindingCollection;

/**
 * Contact binding field.
 */
trait HasContactBindingsTrait
{
	private ?ContactBindingCollection $contactBindings = null;
	private ?Contact $contact = null;
	/** @var Contact[]|null */
	private ?array $contacts = null;

	public function getContactBindings(): ?ContactBindingCollection
	{
		return $this->contactBindings;
	}

	public function setContactBindings(ContactBindingCollection $contactBindings): static
	{
		$this->contactBindings = $contactBindings;
		$this->markChanged(self::contactBindings);

		return $this;
	}

	public function getContact(): ?Contact
	{
		return $this->contact;
	}

	/** @return Contact[]|null */
	public function getContacts(): ?array
	{
		return $this->contacts;
	}

	protected function internalSetContactBindingField(string $fieldName, mixed $value): bool
	{
		if ($fieldName === self::contactBindings)
		{
			$this->contactBindings = $value;

			return true;
		}
		if ($fieldName === 'contact')
		{
			$this->contact = $value;

			return true;
		}
		if ($fieldName === 'contacts')
		{
			$this->contacts = $value;

			return true;
		}

		return false;
	}
}

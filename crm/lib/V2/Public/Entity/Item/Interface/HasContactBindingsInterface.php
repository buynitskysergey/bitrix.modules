<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Item\Interface;

use Bitrix\Crm\V2\Public\Entity\Item\ContactBindingCollection;
use Bitrix\Crm\V2\Public\Entity\Item\Contact;

interface HasContactBindingsInterface
{
	public function getContactBindings(): ?ContactBindingCollection;

	public function setContactBindings(ContactBindingCollection $contactBindings): static;

	public function getContact(): ?Contact;

	/** @return Contact[]|null */
	public function getContacts(): ?array;
}

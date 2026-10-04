<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Command\Item\Contact;

use Bitrix\Crm\V2\Public\Command\Item\AddItemCommand;
use Bitrix\Crm\V2\Public\Entity\Item\Contact;

final class AddCommand extends AddItemCommand
{
	public function __construct(
		Contact $contact,
		int $userId,
	)
	{
		parent::__construct($contact, $userId);
	}

	public function getItem(): Contact
	{
		return $this->item;
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Command\Item\SmartB2eDocument;

use Bitrix\Crm\V2\Public\Command\Item\AddItemCommand;
use Bitrix\Crm\V2\Public\Entity\Item\SmartB2eDocument;

final class AddCommand extends AddItemCommand
{
	public function __construct(
		SmartB2eDocument $smartB2eDocument,
		int $userId,
	)
	{
		parent::__construct($smartB2eDocument, $userId);
	}

	public function getItem(): SmartB2eDocument
	{
		return $this->item;
	}
}

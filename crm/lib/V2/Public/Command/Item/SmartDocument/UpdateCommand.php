<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Command\Item\SmartDocument;

use Bitrix\Crm\V2\Public\Command\Item\UpdateItemCommand;
use Bitrix\Crm\V2\Public\Entity\Item\SmartDocument;

final class UpdateCommand extends UpdateItemCommand
{
	public function __construct(
		SmartDocument $smartDocument,
		int $userId,
	)
	{
		parent::__construct($smartDocument, $userId);
	}

	public function getItem(): SmartDocument
	{
		return $this->item;
	}
}

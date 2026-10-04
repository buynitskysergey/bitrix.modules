<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\Attachment;

use Bitrix\Mail\Helper\Message\Loader\QueryBuilder;
use Bitrix\Mail\MailMessageTable;
use Bitrix\Mail\MailMessageUidTable;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;

final class ActiveMessageLocator
{
	public function find(int $messageId): ?array
	{
		$row = MailMessageTable::getList([
			'runtime' => [
				new Reference(
					'MESSAGE_UID',
					MailMessageUidTable::class,
					Join::on('this.MAILBOX_ID', 'ref.MAILBOX_ID')
						->whereColumn('this.ID', 'ref.MESSAGE_ID'),
					['join_type' => 'INNER'],
				),
			],
			'select' => ['ID', 'MAILBOX_ID'],
			'filter' => array_merge(
				[
					'=ID' => $messageId,
					'=MAILBOX.ACTIVE' => 'Y',
				],
				QueryBuilder::generationScopeFilterOfMessages([$messageId], 'MESSAGE_UID.'),
			),
			'limit' => 1,
		])->fetch();

		return is_array($row) ? $row : null;
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\SourceGeneration\Matching;

use Bitrix\Mail\Internal\Entity\SourceGeneration\Context;
use Bitrix\Mail\Internal\Service\SourceGeneration\GenerationScope;
use Bitrix\Mail\MailboxTable;
use Bitrix\Mail\MailMessageUidTable;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Bitrix\Main\Text\Emoji;

/**
 * The letters a coordinate of a managed transfer service leads to: the placement the
 * retained generation of the mailbox keeps under that coordinate names the local message,
 * and one indexed lookup answers it instead of a walk over the history.
 *
 * What comes back is a candidate and not a verdict. Nothing authenticates the header the
 * coordinate arrives in - it is a letter inside the mailbox of the client, so whoever can
 * write there can write one - and the caller confirms the candidate by the envelope the
 * way it confirms a candidate of any other route.
 */
final class MigratorReferenceLocator
{
	/**
	 * Placements one coordinate may be answered by. A coordinate is unique inside a
	 * generation, so the scope of the retained source is what makes more than one possible
	 * at all; several of them are read to be able to tell that apart from exactly one.
	 */
	private const MAX_PLACEMENTS = 5;

	/** @var array<int, string[]> Mailbox => the addresses a coordinate of it may name */
	private array $addresses = [];

	/**
	 * @param string $value The header value, as {@see \Bitrix\Mail\Helper\Mailbox\Imap} read it.
	 * @return int[] The local messages the coordinate leads to, empty when it leads nowhere.
	 * @throws ArgumentException|ObjectPropertyException|SystemException
	 */
	public function locate(Context $context, string $value): array
	{
		$reference = MigratorReference::parse($value);

		if ($reference === null || !$this->namesTheMailbox($context->mailboxId, $reference->mailbox))
		{
			return [];
		}

		return $this->findPlacedMessages($context, $reference);
	}

	/**
	 * Whether the coordinate was taken in the mailbox being synchronized.
	 *
	 * A check and never a key of the lookup: the portal allows several mailboxes on one
	 * address, and every query of a transfer is bounded by the mailbox it runs in anyway.
	 * Both spellings of the address the mailbox knows are accepted - a transfer service
	 * addresses a mailbox by what it connects to it with.
	 *
	 * @throws ArgumentException|ObjectPropertyException|SystemException
	 */
	private function namesTheMailbox(int $mailboxId, string $address): bool
	{
		$address = mb_strtolower(trim($address));

		if ($address === '')
		{
			return false;
		}

		if (!isset($this->addresses[$mailboxId]))
		{
			$row = MailboxTable::getRow([
				'select' => ['EMAIL', 'LOGIN'],
				'filter' => ['=ID' => $mailboxId],
			]) ?? [];

			$this->addresses[$mailboxId] = array_values(array_filter(array_map(
				static fn (string $field): string => mb_strtolower(trim((string)($row[$field] ?? ''))),
				['EMAIL', 'LOGIN'],
			)));
		}

		return in_array($address, $this->addresses[$mailboxId], true);
	}

	/**
	 * @return int[]
	 * @throws ArgumentException|ObjectPropertyException|SystemException
	 */
	private function findPlacedMessages(Context $context, MigratorReference $reference): array
	{
		/*
			The retained generation is the one still serving the mailbox: the letters are copied
			off the source it reads, so the coordinate names a placement of its scope. The shape
			of the pointer and not a strict equality, because rows with the implicit generation 0
			are written by code that knows nothing of generations and belong to that source too.
		*/
		$retained = GenerationScope::forMailbox($context->mailboxId);

		$filter = $retained->apply([
			'=MAILBOX_ID' => $context->mailboxId,
			'@DIR_MD5' => self::folderHashes($reference->folderPath),
			'=DIR_UIDV' => $reference->uidValidity,
			'=MSG_UID' => $reference->uid,
			// A placement of no logical message carries no identity to hand over
			'>MESSAGE_ID' => 0,
		]);

		if ($retained->getGenerationIds() === null)
		{
			// A mailbox whose pointer is still 0 states no condition of its own, and the
			// placements of the import itself are never what a coordinate of the old source means
			$filter['!=GENERATION_ID'] = $context->generationId;
		}

		$rows = MailMessageUidTable::getList([
			'select' => ['MESSAGE_ID'],
			'filter' => $filter,
			'limit' => self::MAX_PLACEMENTS,
		])->fetchAll();

		$messageIds = [];
		foreach ($rows as $row)
		{
			$messageId = (int)$row['MESSAGE_ID'];
			$messageIds[$messageId] = $messageId;
		}

		return array_values($messageIds);
	}

	/**
	 * The paths of a folder as the portal may key it. Both the folder table and placements
	 * keep the server spelling, while IMAP defines the root INBOX as case-insensitive. Its
	 * 32 ASCII spellings therefore name one coordinate; every other path remains exact.
	 * Emoji are replaced by the same markers the folder storage uses
	 * ({@see \Bitrix\Mail\Internals\Entity\MailboxDirectory::getPath()}).
	 *
	 * @return string[]
	 */
	private static function folderHashes(string $path): array
	{
		if (strcasecmp($path, 'INBOX') !== 0)
		{
			return [md5(Emoji::encode($path))];
		}

		$spellings = [''];
		foreach (str_split('INBOX') as $letter)
		{
			foreach ($spellings as $index => $prefix)
			{
				$spellings[$index] = $prefix . strtolower($letter);
				$spellings[] = $prefix . $letter;
			}
		}

		return array_map(static fn (string $spelling): string => md5($spelling), $spellings);
	}
}

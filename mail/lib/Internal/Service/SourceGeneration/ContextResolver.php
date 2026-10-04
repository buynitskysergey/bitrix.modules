<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\SourceGeneration;

use Bitrix\Mail\Internal\Entity\SourceGeneration\Context;
use Bitrix\Mail\Internals\MailboxSourceGenerationTable;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\InvalidOperationException;
use Bitrix\Main\ObjectNotFoundException;

/**
 * Resolves the source generation context once at the entry point of a request or a job.
 * An unknown or stale generation fails here, before any IMAP call.
 */
class ContextResolver
{
	public function __construct(
		private readonly Repository $repository = new Repository(),
	)
	{
	}

	/**
	 * Resolves the ACTIVE generation for a regular sync entry.
	 *
	 * Accepts the mailbox row loaded at the entry point (needs ID; reads the
	 * ACTIVE_GENERATION_ID pointer from the row or falls back to the database).
	 * Pointer 0 is the implicit G1 of a mailbox that has not been backfilled yet.
	 *
	 * @throws ArgumentException
	 * @throws ObjectNotFoundException
	 * @throws InvalidOperationException
	 */
	public function resolveForSync(array $mailbox): Context
	{
		$mailboxId = (int)($mailbox['ID'] ?? 0);
		if ($mailboxId <= 0)
		{
			throw new ArgumentException('A mailbox row with ID is expected', 'mailbox');
		}

		$pointer = array_key_exists('ACTIVE_GENERATION_ID', $mailbox)
			? (int)$mailbox['ACTIVE_GENERATION_ID']
			: $this->repository->getActiveGenerationId($mailboxId);

		if ($pointer === 0)
		{
			return new Context(
				mailboxId: $mailboxId,
				generationId: 0,
				status: MailboxSourceGenerationTable::STATUS_ACTIVE,
				operationId: '',
				purpose: Context::PURPOSE_NORMAL_SYNC,
			);
		}

		$generation = $this->repository->getById($pointer);
		if ($generation === null || $generation->mailboxId !== $mailboxId)
		{
			throw new ObjectNotFoundException(sprintf(
				'The active source generation %u of the mailbox %u is unknown',
				$pointer,
				$mailboxId,
			));
		}

		if (!$generation->isActive())
		{
			throw new InvalidOperationException(sprintf(
				'The source generation %u of the mailbox %u is not active anymore (%s)',
				$generation->id,
				$mailboxId,
				$generation->status,
			));
		}

		return new Context(
			mailboxId: $mailboxId,
			generationId: $generation->id,
			status: $generation->status,
			operationId: $generation->operationId,
			purpose: Context::PURPOSE_NORMAL_SYNC,
			migratorService: $generation->migratorService,
		);
	}

	/**
	 * Resolves the concrete PREPARING generation for the trusted migration import entry.
	 *
	 * @throws ObjectNotFoundException
	 * @throws InvalidOperationException
	 */
	public function resolveForMigrationImport(int $mailboxId, int $generationId): Context
	{
		$generation = $this->repository->getById($generationId);
		if ($generation === null || $generation->mailboxId !== $mailboxId)
		{
			throw new ObjectNotFoundException(sprintf(
				'The source generation %u of the mailbox %u is unknown',
				$generationId,
				$mailboxId,
			));
		}

		if (!$generation->isPreparing())
		{
			throw new InvalidOperationException(sprintf(
				'The source generation %u of the mailbox %u is not being prepared (%s)',
				$generation->id,
				$mailboxId,
				$generation->status,
			));
		}

		return new Context(
			mailboxId: $mailboxId,
			generationId: $generation->id,
			status: $generation->status,
			operationId: $generation->operationId,
			purpose: Context::PURPOSE_MIGRATION_IMPORT,
			migratorService: $generation->migratorService,
		);
	}
}

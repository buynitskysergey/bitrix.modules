<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Rest\V3\Exceptions;

use Bitrix\Note\Public\Command\RestoreCollectionFromRecycleBinCommand;
use Bitrix\Rest\V3\Exception\RestException;
use Bitrix\Rest\V3\Exception\SkipWriteToLogException;

/**
 * The three ways a base refuses to leave the recycle bin, told apart by code rather than by wording:
 * one of them passes on a retry a moment later and two never will, and a client reading the text
 * cannot tell which. The code is the command's own - the domain already names these three - and the
 * status says whether waiting helps: a conflict to sit out, gone for what is not coming back.
 */
class CollectionRestoreRefusedException extends RestException implements SkipWriteToLogException
{
	private const REASONS = [
		RestoreCollectionFromRecycleBinCommand::ERROR_RESTORE_IN_PROGRESS => [
			'409 Conflict',
			'NOTE_REST_V3_EXCEPTION_COLLECTIONRESTOREREFUSED_IN_PROGRESS_MSG',
		],
		RestoreCollectionFromRecycleBinCommand::ERROR_RETENTION_EXPIRED => [
			'410 Gone',
			'NOTE_REST_V3_EXCEPTION_COLLECTIONRESTOREREFUSED_RETENTION_EXPIRED_MSG',
		],
		RestoreCollectionFromRecycleBinCommand::ERROR_PURGE_STARTED => [
			'410 Gone',
			'NOTE_REST_V3_EXCEPTION_COLLECTIONRESTOREREFUSED_PURGE_STARTED_MSG',
		],
	];

	public function __construct(private readonly string $reason)
	{
		parent::__construct(status: self::REASONS[$reason][0]);
	}

	/** Whether this refusal speaks for the code the command answered with. */
	public static function speaksFor(string $errorCode): bool
	{
		return isset(self::REASONS[$errorCode]);
	}

	public function getRegistryCode(): string
	{
		return $this->reason;
	}

	protected function getMessagePhraseCode(): string
	{
		return self::REASONS[$this->reason][1];
	}
}

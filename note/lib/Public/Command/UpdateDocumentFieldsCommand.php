<?php

declare(strict_types=1);

namespace Bitrix\Note\Public\Command;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Note\Internal\Exceptions\DocumentArchivedException;
use Bitrix\Note\Internal\Exceptions\DocumentHasUnsavedChangesException;
use Bitrix\Note\Internal\Exceptions\DocumentInRecycleBinException;
use Bitrix\Note\Internal\Exceptions\DocumentNotFoundException;
use Bitrix\Note\Internal\Exceptions\MainDocumentOperationException;
use Bitrix\Note\Internal\Exceptions\MoveAccessEscalationException;
use Bitrix\Note\Internal\Model\Document;
use Bitrix\Note\Internal\Model\DocumentTable;
use Bitrix\Note\Internal\Repository\CollectionRepository;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Repository\DocumentUpdateRepository;
use Bitrix\Note\Internal\Service\RecycleBin\RecycleBinFilter;
use Bitrix\Note\Internal\Enum\WriteSource;
use Bitrix\Note\Public\Provider\Param\Document\DocumentLimits;

class UpdateDocumentFieldsCommand extends AbstractCommand
{
	public const ERROR_DOCUMENT_NOT_FOUND = 'DOCUMENT_NOT_FOUND';
	public const ERROR_DOCUMENT_IN_RECYCLE_BIN = 'DOCUMENT_IN_RECYCLE_BIN';
	public const ERROR_DOCUMENT_ARCHIVED = 'DOCUMENT_ARCHIVED';
	public const ERROR_MAIN_DOCUMENT = 'MAIN_DOCUMENT';
	public const ERROR_CONTENT_TOO_LARGE = 'CONTENT_TOO_LARGE';
	public const ERROR_UNSAVED_CHANGES = 'UNSAVED_CHANGES';
	public const ERROR_COLLECTION_NOT_FOUND = 'COLLECTION_NOT_FOUND';
	public const ERROR_INVALID_PARENT = 'INVALID_PARENT';
	public const ERROR_WRITE_FAILED = 'WRITE_FAILED';
	public const ERROR_PARENT_MAIN_DOCUMENT = 'PARENT_MAIN_DOCUMENT';
	public const ERROR_PARENT_IN_RECYCLE_BIN = 'PARENT_IN_RECYCLE_BIN';
	public const ERROR_PARENT_ARCHIVED = 'PARENT_ARCHIVED';
	public const ERROR_PARENT_INSIDE_SUBTREE = 'PARENT_INSIDE_SUBTREE';
	public const ERROR_MOVE_NOT_ALLOWED = 'MOVE_NOT_ALLOWED';
	public const ERROR_INVALID_ACTOR = 'INVALID_ACTOR';

	public const FIELD_TITLE = 'title';
	public const FIELD_MARKDOWN = 'markdown';
	public const FIELD_COLLECTION_ID = 'collectionId';
	public const FIELD_PARENT_ID = 'parentId';

	private readonly DocumentRepository $repository;
	private readonly CollectionRepository $collectionRepository;
	private readonly DocumentUpdateRepository $updateRepository;
	private readonly RecycleBinFilter $recycleBinFilter;

	/** @var list<string> */
	private array $appliedFields = [];

	/**
	 * The root of a knowledge base is said differently on the two sides of this class, and a caller has to
	 * know which side it stands on. Here it is `null` for `$parentId`, because a document either has a
	 * parent or has none. In the RAW form the platform delivers - the form
	 * {@see self::unusableIdentifierIn()} judges - it is zero, because that is what a person writing the
	 * field of a step leaves there. Translating the one into the other belongs to whoever read the raw
	 * form; this constructor never sees it.
	 */
	public function __construct(
		private readonly int $documentId,
		private readonly ?string $title,
		private readonly ?string $markdown,
		private readonly ?int $collectionId,
		private readonly ?int $parentId,
		private readonly int $userId,
		private readonly WriteSource $writeSource = WriteSource::User,
		private readonly bool $checkRights = true,
		?DocumentRepository $repository = null,
		?CollectionRepository $collectionRepository = null,
		?DocumentUpdateRepository $updateRepository = null,
		?RecycleBinFilter $recycleBinFilter = null,
	)
	{
		$this->repository = $repository ?? new DocumentRepository();
		$this->collectionRepository = $collectionRepository ?? new CollectionRepository();
		$this->updateRepository = $updateRepository ?? new DocumentUpdateRepository();
		$this->recycleBinFilter = $recycleBinFilter ?? new RecycleBinFilter();
	}

	protected function execute(): Result
	{
		$preflight = $this->preflight();
		if (!$preflight->isSuccess())
		{
			return $preflight;
		}

		if ($this->markdown !== null)
		{
			$fields = $this->title === null
				? [self::FIELD_MARKDOWN]
				: [self::FIELD_MARKDOWN, self::FIELD_TITLE];

			$failure = $this->runStep($fields, fn(): Result => (new OverwriteDocumentContentCommand(
				$this->documentId,
				$this->markdown,
				$this->userId,
				title: $this->title,
			))->run());
			if ($failure !== null)
			{
				return $failure;
			}
		}
		elseif ($this->title !== null)
		{
			$failure = $this->runStep(
				[self::FIELD_TITLE],
				fn(): Result => (new UpdateDocumentCommand($this->documentId, $this->title, $this->userId))->run(),
			);
			if ($failure !== null)
			{
				return $failure;
			}
		}

		if ($this->collectionId !== null)
		{
			$fields = $this->parentId === null
				? [self::FIELD_COLLECTION_ID]
				: [self::FIELD_COLLECTION_ID, self::FIELD_PARENT_ID];

			$failure = $this->runStep($fields, fn(): Result => (new MoveDocumentCommand(
				$this->documentId,
				$this->collectionId,
				$this->parentId,
				null,
				$this->userId,
				writeSource: $this->writeSource,
				checkRights: $this->checkRights,
			))->run());
			if ($failure !== null)
			{
				return $failure;
			}
		}

		return $this->success();
	}

	/** @param list<string> $fields */
	private function runStep(array $fields, callable $step): ?Result
	{
		try
		{
			$stepResult = $step();
			if ($stepResult->isSuccess())
			{
				array_push($this->appliedFields, ...$fields);

				return null;
			}

			$message = implode(', ', $stepResult->getErrorMessages());
		}
		catch (\Throwable $exception)
		{
			$cause = $this->rootCause($exception);

			return $this->failure(
				$fields[0],
				$cause->getMessage(),
				$cause,
				$this->refusalCodeFor($cause, in_array(self::FIELD_COLLECTION_ID, $fields, true)),
			);
		}

		return $this->failure($fields[0], $message);
	}

	private function preflight(): Result
	{
		if ($this->userId < 0 || ($this->userId === 0 && $this->checkRights))
		{
			return self::refusal(self::ERROR_INVALID_ACTOR, 'The move will not accept this actor.');
		}

		$document = $this->repository->getMetaById(
			$this->documentId,
			['ID', 'COLLECTION_ID', 'PARENT_ID', 'TITLE', 'IS_ARCHIVED', 'CONTENT_FORMAT'],
			false,
		);
		if ($document === null)
		{
			return self::refusal(self::ERROR_DOCUMENT_NOT_FOUND, 'Document not found.');
		}

		if ($this->recycleBinFilter->isInRecycleBin($this->documentId))
		{
			return self::refusal(self::ERROR_DOCUMENT_IN_RECYCLE_BIN, 'Document is in the recycle bin.');
		}

		if ($document->getIsArchived())
		{
			return self::refusal(self::ERROR_DOCUMENT_ARCHIVED, 'Document is archived.');
		}

		if ($this->repository->isMainDocument($this->documentId))
		{
			return self::refusal(self::ERROR_MAIN_DOCUMENT, 'The main document cannot be changed this way.');
		}

		if ($this->markdown !== null)
		{
			$refusal = $this->preflightContent($document);
			if ($refusal !== null)
			{
				return $refusal;
			}
		}

		if ($this->collectionId !== null)
		{
			$refusal = $this->preflightLocation($document);
			if ($refusal !== null)
			{
				return $refusal;
			}
		}

		return $this->success();
	}

	private function preflightContent(Document $document): ?Result
	{
		if (strlen((string)$this->markdown) > DocumentLimits::MAX_MARKDOWN_BYTES)
		{
			return self::refusal(self::ERROR_CONTENT_TOO_LARGE, 'Document content is too large.');
		}

		if ($this->isCollaborative($document) && $this->updateRepository->hasAnyByDocumentId($this->documentId))
		{
			return self::refusal(self::ERROR_UNSAVED_CHANGES, 'Document has unsaved changes.');
		}

		return null;
	}

	private function preflightLocation(Document $document): ?Result
	{
		if (!$this->collectionRepository->exists((int)$this->collectionId))
		{
			return self::refusal(self::ERROR_COLLECTION_NOT_FOUND, 'Knowledge base not found.');
		}

		if ($this->parentId !== null)
		{
			$parent = $this->repository->getMetaById(
				$this->parentId,
				['ID', 'COLLECTION_ID', 'IS_ARCHIVED'],
				false,
			);
			if ($parent === null || (int)$parent->getCollectionId() !== (int)$this->collectionId)
			{
				return self::refusal(
					self::ERROR_INVALID_PARENT,
					'Parent document does not belong to the target knowledge base.',
				);
			}

			if ($this->repository->isMainDocument($this->parentId))
			{
				return self::refusal(self::ERROR_PARENT_MAIN_DOCUMENT, 'The main document cannot be a parent.');
			}

			if ($this->recycleBinFilter->isInRecycleBin($this->parentId))
			{
				return self::refusal(
					self::ERROR_PARENT_IN_RECYCLE_BIN,
					'Parent document is in the recycle bin.',
				);
			}

			if ($parent->getIsArchived())
			{
				return self::refusal(self::ERROR_PARENT_ARCHIVED, 'Parent document is archived.');
			}

			$sourceCollectionId = (int)$document->getCollectionId();
			if ((int)$parent->getCollectionId() === $sourceCollectionId)
			{
				$subtreeIds = $this->repository->getSubtreeIds($this->documentId, $sourceCollectionId, true);
				if (in_array($this->parentId, $subtreeIds, true))
				{
					return self::refusal(
						self::ERROR_PARENT_INSIDE_SUBTREE,
						'Parent document belongs to the moved subtree.',
					);
				}
			}
		}

		return null;
	}

	/**
	 * Whether the identifiers a caller holds are ones this command accepts, answered with the code it
	 * would refuse them with. Asked of the RAW values, before they become the `int` of the constructor:
	 * this command acts on whole numbers, and a cast is exactly what turns everything else into one.
	 *
	 * Zero is accepted on purpose - it is the one form that means the root of a knowledge base in the raw
	 * form, and the constructor of this command says the same thing with `null` ({@see self::__construct()}).
	 * A negative number names nothing, and a caller reading it as "no parent" would move the document to
	 * the root, while an empty field would have left it where it is.
	 *
	 * @param array<string, mixed> $fields raw values under self::FIELD_COLLECTION_ID / self::FIELD_PARENT_ID
	 * @return string|null code of the refusal, or null when every given identifier is usable
	 */
	public static function unusableIdentifierIn(array $fields): ?string
	{
		foreach ([
			self::FIELD_COLLECTION_ID => self::ERROR_COLLECTION_NOT_FOUND,
			self::FIELD_PARENT_ID => self::ERROR_INVALID_PARENT,
		] as $field => $code)
		{
			if (!array_key_exists($field, $fields))
			{
				continue;
			}

			$number = self::wholeNumber($fields[$field]);
			if ($number === null || $number < 0)
			{
				return $code;
			}
		}

		return null;
	}

	/**
	 * A whole number and nothing that merely looks like one. Neither a cast nor `is_numeric()` may stand
	 * here, because zero is a legitimate identifier: `(int)'abc'` and `(int)'.9'` are both zero, so an
	 * unusable value would read as the root of a knowledge base and the document would move there.
	 */
	private static function wholeNumber(mixed $value): ?int
	{
		// A whole number written as a fractional type is still a whole number, and the platform delivers
		// it that way: a template variable of type Number is a double, and an expression that fills the
		// whole field keeps its own type. `7.5` is refused, `7.0` is seven.
		if (is_float($value))
		{
			return is_finite($value) && (float)(int)$value === $value ? (int)$value : null;
		}

		// A truth and an object with __toString() are the trap from the other side: the validator below
		// reads them as a number, a cast reads them as 1, and 1 is a knowledge base somebody really has.
		if (!is_int($value) && !is_string($value))
		{
			return null;
		}

		$number = filter_var($value, FILTER_VALIDATE_INT);

		return $number === false ? null : $number;
	}

	private function isCollaborative(Document $document): bool
	{
		$format = (string)$document->getContentFormat();

		return $format === DocumentTable::CONTENT_FORMAT_YJS || $format === DocumentTable::CONTENT_FORMAT_JSON;
	}

	/**
	 * Result of a call that had nothing to write: nothing applied and nothing refused.
	 */
	public static function nothingToWrite(): Result
	{
		return self::report([]);
	}

	/**
	 * A refusal named by a code of this command, with nothing applied. A caller that refuses before the
	 * command runs answers in the shape the command itself answers in - a report of a step reads one form.
	 */
	public static function refusal(string $code, string $message): Result
	{
		return self::refusalReport([], null, $code, $message);
	}

	private function success(): Result
	{
		return self::report($this->appliedFields);
	}

	private function failure(
		?string $field,
		string $message,
		?\Throwable $cause = null,
		string $code = self::ERROR_WRITE_FAILED,
	): Result
	{
		return self::refusalReport($this->appliedFields, $field, $code, $message, $cause);
	}

	/** @param list<string> $appliedFields */
	private static function report(array $appliedFields): Result
	{
		$result = new Result();
		$result->setData(['appliedFields' => $appliedFields, 'failedField' => null]);

		return $result;
	}

	/** @param list<string> $appliedFields */
	private static function refusalReport(
		array $appliedFields,
		?string $failedField,
		string $code,
		string $message,
		?\Throwable $cause = null,
	): Result
	{
		$result = new Result();
		$result->addError(new Error(
			$message,
			$code,
			$cause === null ? [] : ['cause' => $cause::class . ': ' . $cause->getMessage()],
		));
		$result->setData([
			'appliedFields' => $appliedFields,
			'failedField' => $failedField,
			'errorMessage' => $message,
		]);

		return $result;
	}

	private function rootCause(\Throwable $exception): \Throwable
	{
		$cause = $exception;
		while ($cause->getPrevious() !== null)
		{
			$cause = $cause->getPrevious();
		}

		return $cause;
	}

	private function refusalCodeFor(\Throwable $cause, bool $isMoveStep): string
	{
		if ($isMoveStep && $this->parentId !== null)
		{
			return match (true)
			{
				$cause instanceof MainDocumentOperationException => self::ERROR_PARENT_MAIN_DOCUMENT,
				$cause instanceof DocumentInRecycleBinException => self::ERROR_PARENT_IN_RECYCLE_BIN,
				$cause instanceof DocumentArchivedException => self::ERROR_PARENT_ARCHIVED,
				$cause instanceof MoveAccessEscalationException => self::ERROR_MOVE_NOT_ALLOWED,
				default => self::ERROR_WRITE_FAILED,
			};
		}

		if ($isMoveStep && $cause instanceof MoveAccessEscalationException)
		{
			return self::ERROR_MOVE_NOT_ALLOWED;
		}

		return match (true)
		{
			$cause instanceof DocumentNotFoundException => self::ERROR_DOCUMENT_NOT_FOUND,
			$cause instanceof DocumentInRecycleBinException => self::ERROR_DOCUMENT_IN_RECYCLE_BIN,
			$cause instanceof DocumentArchivedException => self::ERROR_DOCUMENT_ARCHIVED,
			$cause instanceof MainDocumentOperationException => self::ERROR_MAIN_DOCUMENT,
			$cause instanceof DocumentHasUnsavedChangesException => self::ERROR_UNSAVED_CHANGES,
			default => self::ERROR_WRITE_FAILED,
		};
	}
}

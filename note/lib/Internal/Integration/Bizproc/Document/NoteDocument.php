<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Integration\Bizproc\Document;

use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Note\Internal\Access\PortalAdmin;
use Bitrix\Note\Internal\Access\Service\DocumentAccessService;
use Bitrix\Note\Internal\Model\Document;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Service\License\LicenseService;
use Bitrix\Note\Internal\Service\User\SystemUser;
use Bitrix\Note\Internal\Enum\WriteSource;
use Bitrix\Note\Public\Command\UpdateDocumentFieldsCommand;

// The class implements an interface that lives in bizproc, so without that module its very declaration
// would be a fatal error. Returning early leaves the class undeclared, which is exactly what the engine
// checks for - the same guard the tasks document type uses.
if (!Loader::includeModule('bizproc'))
{
	return;
}

Loc::loadMessages(__FILE__);

/**
 * DOC-01 - a knowledge base document as a bizproc document type.
 *
 * The engine calls every method statically on the class name and asks `method_exists` first, so an
 * undeclared method falls back to the service's own default. All eighteen interface methods are declared
 * for one reason only: without them the class does not implement `IBPWorkflowDocument` and
 * `DocumentEntityChecker::isValid()` rejects the type outright.
 *
 * Writing goes through the single door of {@see UpdateDocumentFieldsCommand}: one call, checks before the
 * first write. Rights of a document are NOT checked here - commands own that; the two rights questions
 * this class answers are about a person reading a process log and about the knowledge base being
 * available on the portal at all.
 */
class NoteDocument implements \IBPWorkflowDocument
{
	public const MODULE_ID = 'note';
	public const ENTITY_TYPE = 'DOCUMENT';

	private const FIELD_ID = 'ID';
	private const FIELD_TITLE = 'TITLE';
	private const FIELD_MARKDOWN = 'MARKDOWN';
	private const FIELD_COLLECTION_ID = 'COLLECTION_ID';
	private const FIELD_PARENT_ID = 'PARENT_ID';
	private const FIELD_CREATED_BY = 'CREATED_BY';
	private const FIELD_UPDATED_BY = 'UPDATED_BY';
	private const FIELD_CREATED_AT = 'CREATED_AT';
	private const FIELD_UPDATED_AT = 'UPDATED_AT';
	private const FIELD_IS_ARCHIVED = 'IS_ARCHIVED';

	private const FIELD_COLUMNS = [
		self::FIELD_ID => 'ID',
		self::FIELD_TITLE => 'TITLE',
		self::FIELD_MARKDOWN => 'MARKDOWN',
		self::FIELD_COLLECTION_ID => 'COLLECTION_ID',
		self::FIELD_PARENT_ID => 'PARENT_ID',
		self::FIELD_CREATED_BY => 'CREATED_BY',
		self::FIELD_UPDATED_BY => 'UPDATED_BY',
		self::FIELD_CREATED_AT => 'CREATED_AT',
		self::FIELD_UPDATED_AT => 'UPDATED_AT',
		self::FIELD_IS_ARCHIVED => 'IS_ARCHIVED',
	];

	/** @var array<int, array<string, mixed>> request-scoped field values, keyed by document id */
	private static array $fieldValueCache = [];

	/** @var array<int, true> documents already known missing in this request */
	private static array $missingDocumentCache = [];

	/**
	 * Drops the request-lifetime field cache. Production has nothing to call this - a request reads one
	 * state of a document - but a test process is many requests' worth of documents over identifiers the
	 * rollback hands back to the next test, and a title read once would answer for all of them.
	 */
	public static function clearRequestCache(): void
	{
		self::$fieldValueCache = [];
		self::$missingDocumentCache = [];
	}

	public static function getDocumentType($documentId)
	{
		return self::ENTITY_TYPE;
	}

	/**
	 * Complex identifier of a document, as the process engine passes documents around. Built here so the
	 * shape has one spelling in the module: the identifier and the type look alike and differ only in the
	 * third element, and mixing them up drops the document without a word.
	 *
	 * @return array{0: string, 1: string, 2: int}
	 */
	public static function complexDocumentId(int $documentId): array
	{
		return [self::MODULE_ID, self::class, $documentId];
	}

	/**
	 * @return array{0: string, 1: string, 2: string}
	 */
	public static function complexDocumentType(): array
	{
		return [self::MODULE_ID, self::class, self::ENTITY_TYPE];
	}

	public static function getDocumentTypeName($documentType)
	{
		return Loc::getMessage('NOTE_BIZPROC_DOCUMENT_TYPE_NAME') ?? '';
	}

	public static function getEntityName($entity)
	{
		return Loc::getMessage('NOTE_BIZPROC_DOCUMENT_ENTITY_NAME') ?? '';
	}

	public static function getDocumentFields($documentType, $importExportMode = false)
	{
		return [
			self::FIELD_ID => [
				'Name' => Loc::getMessage('NOTE_BIZPROC_DOCUMENT_FIELD_ID') ?? '',
				'Type' => 'int',
			],
			self::FIELD_TITLE => [
				'Name' => Loc::getMessage('NOTE_BIZPROC_DOCUMENT_FIELD_TITLE') ?? '',
				'Type' => 'string',
				'Editable' => true,
			],
			self::FIELD_MARKDOWN => [
				'Name' => Loc::getMessage('NOTE_BIZPROC_DOCUMENT_FIELD_MARKDOWN') ?? '',
				'Type' => 'text',
				'Editable' => true,
			],
			self::FIELD_COLLECTION_ID => [
				'Name' => Loc::getMessage('NOTE_BIZPROC_DOCUMENT_FIELD_COLLECTION_ID') ?? '',
				'Type' => 'int',
				'Editable' => true,
			],
			self::FIELD_PARENT_ID => [
				'Name' => Loc::getMessage('NOTE_BIZPROC_DOCUMENT_FIELD_PARENT_ID') ?? '',
				'Type' => 'int',
				'Editable' => true,
			],
			self::FIELD_CREATED_BY => [
				'Name' => Loc::getMessage('NOTE_BIZPROC_DOCUMENT_FIELD_CREATED_BY') ?? '',
				'Type' => 'user',
			],
			self::FIELD_UPDATED_BY => [
				'Name' => Loc::getMessage('NOTE_BIZPROC_DOCUMENT_FIELD_UPDATED_BY') ?? '',
				'Type' => 'user',
			],
			self::FIELD_CREATED_AT => [
				'Name' => Loc::getMessage('NOTE_BIZPROC_DOCUMENT_FIELD_CREATED_AT') ?? '',
				'Type' => 'datetime',
			],
			self::FIELD_UPDATED_AT => [
				'Name' => Loc::getMessage('NOTE_BIZPROC_DOCUMENT_FIELD_UPDATED_AT') ?? '',
				'Type' => 'datetime',
			],
			self::FIELD_IS_ARCHIVED => [
				'Name' => Loc::getMessage('NOTE_BIZPROC_DOCUMENT_FIELD_IS_ARCHIVED') ?? '',
				'Type' => 'bool',
			],
		];
	}

	public static function getDocument($documentId, $documentType = null, array $select = [])
	{
		$fields = $select === []
			? array_keys(self::FIELD_COLUMNS)
			: array_values(array_intersect(array_keys(self::FIELD_COLUMNS), $select));

		if ($fields === [])
		{
			return [];
		}

		$values = self::readFields($documentId, $fields);
		if ($values === null)
		{
			return [];
		}

		if ($select === [])
		{
			return $values;
		}

		return array_intersect_key($values, array_flip($select));
	}

	public static function getFieldValue($documentId, $fieldId, $documentType = null)
	{
		$fieldId = (string)$fieldId;
		$values = self::readFields($documentId, [$fieldId]);

		return $values === null ? null : ($values[$fieldId] ?? null);
	}

	public static function getDocumentName($documentId)
	{
		$values = self::readFields($documentId, [self::FIELD_TITLE]);

		return $values === null ? '' : (string)$values[self::FIELD_TITLE];
	}

	public static function isDocumentExists($documentId)
	{
		return self::readFields($documentId, [self::FIELD_ID]) !== null;
	}

	public static function getDocumentAdminPage($documentId)
	{
		return '/note/document/' . (self::identifierIn($documentId) ?? 0) . '/';
	}

	public static function isFeatureEnabled($documentType, $feature)
	{
		return in_array($feature, [\CBPDocumentService::FEATURE_SET_MODIFIED_BY], true);
	}

	public static function updateDocument($documentId, $arFields, $modifiedById = null)
	{
		$meta = self::readFields($documentId, [
			self::FIELD_ID,
			self::FIELD_COLLECTION_ID,
			self::FIELD_PARENT_ID,
		]);
		if ($meta === null)
		{
			return self::refusal(UpdateDocumentFieldsCommand::ERROR_DOCUMENT_NOT_FOUND);
		}

		// The number the read agreed to, not the value that was handed in: everything below acts on it.
		$documentId = (int)$meta[self::FIELD_ID];

		$fields = self::filledFieldsOf($arFields);

		// The command says which identifiers it accepts, and it is asked BEFORE the first write on
		// purpose - CMD-01 asks for everything knowable up front to be checked before anything is
		// applied. Ask it later and a step with an unusable identifier would write the title first and
		// refuse afterwards, leaving the report saying that half of it went through.
		$unusable = UpdateDocumentFieldsCommand::unusableIdentifierIn(self::identifiersIn($fields));
		if ($unusable !== null)
		{
			return self::refusal($unusable);
		}

		$title = self::textOrNull($fields, self::FIELD_TITLE);
		$markdown = self::textOrNull($fields, self::FIELD_MARKDOWN);
		[$collectionId, $parentId] = self::resolveLocation($fields, (int)$meta[self::FIELD_COLLECTION_ID]);

		if ($title === null && $markdown === null && $collectionId === null)
		{
			return UpdateDocumentFieldsCommand::nothingToWrite();
		}

		$userId = (int)$modifiedById;

		try
		{
			$result = (new UpdateDocumentFieldsCommand(
				$documentId,
				$title,
				$markdown,
				$collectionId,
				$parentId,
				$userId > 0 ? $userId : SystemUser::ID,
				WriteSource::Workflow,
				// The one place in the module that writes a document without the rights of the actor, and
				// named rather than passed by position so that it is found by a search for it. A process is
				// not a person: the step runs on behalf of whoever the template names, and the template
				// itself is what a right to the document was already required to write.
				checkRights: false,
			))->run();
		}
		catch (\Throwable)
		{
			self::forgetDocument($documentId);

			return self::refusal(UpdateDocumentFieldsCommand::ERROR_WRITE_FAILED);
		}

		// CMD-01 may keep a title/markdown write after a later step refuses; the activity
		// still closes, and the same request then reads {=Document:...} from this cache.
		self::forgetDocument($documentId);

		if ($result->isSuccess())
		{
			return $result;
		}

		return self::localizeRefusal($result);
	}

	public static function canUserOperateDocument($operation, $userId, $documentId, $arParameters = [])
	{
		// The cast below would turn anything unrecognisable into zero, and zero is ViewWorkflow - a
		// question asked with no operation at all would come back as "may read the log". Every caller in
		// the platform passes a constant, so this guards against an input that should not exist; it costs
		// one comparison and removes the sharp edge.
		if (!is_numeric($operation))
		{
			return false;
		}

		$level = match ((int)$operation)
		{
			\CBPCanUserOperateOperation::ReadDocument => DocumentAccessService::LEVEL_VIEW,
			\CBPCanUserOperateOperation::WriteDocument => DocumentAccessService::LEVEL_EDIT,
			// The log of a process asks the same right as the document itself. Processes over this
			// document do exist - a trigger starts one over the real document - and their log is the only
			// place that says why a step changed nothing. The list of participants, which is the other way
			// into that log, is filled by the tasks a process gives to people, and a process of ours may
			// give none: refusing here would close the log to everybody, the author of the template
			// included. Both neighbours answer this question the same way - tasks group ViewWorkflow with
			// ReadDocument, Disk maps it to canRead().
			//
			// Know what this yes covers, because it is more than a timeline and the three surfaces cannot
			// be told apart from here - the operation is one. Whoever may read the document may also:
			// (1) read the WHOLE track of a process over it, which can mention a document they may not
			//     read, if a later step of the template works with another one;
			// (2) list the processes over it, running and finished;
			// (3) send an EXTERNAL EVENT into a running process over it - `SEND_EVENTS` of the document
			//     component is gated by this very operation - and so resume a node waiting for an event
			//     by name. The coupling belongs to the platform and both neighbours live with it.
			\CBPCanUserOperateOperation::ViewWorkflow => DocumentAccessService::LEVEL_VIEW,
			// Starting, creating and debugging stay refused: a process over this document is started by a
			// trigger and by nothing else, and the knowledge base offers no surface for the rest.
			default => null,
		};
		if ($level === null)
		{
			return false;
		}

		$meta = self::readFields($documentId, [
			self::FIELD_ID,
			self::FIELD_COLLECTION_ID,
			self::FIELD_PARENT_ID,
		]);
		if ($meta === null)
		{
			return false;
		}

		return DocumentAccessService::userHasLevel(
			(int)$meta[self::FIELD_ID],
			(int)$meta[self::FIELD_COLLECTION_ID],
			(int)$userId,
			$level,
		);
	}

	public static function canUserOperateDocumentType($operation, $userId, $documentType, $arParameters = [])
	{
		if (in_array((int)$operation, [
			\CBPCanUserOperateOperation::CreateWorkflow,
			\CBPCanUserOperateOperation::CreateAutomation,
		], true))
		{
			return PortalAdmin::isAdmin((int)$userId);
		}

		// The platform checks this operation before document-level access and exposes workflow instances
		// through its manual-start API. Note has no manual start surface: its workflows start from triggers.
		if ((int)$operation === \CBPCanUserOperateOperation::StartWorkflow)
		{
			return false;
		}

		return !(new LicenseService())->isAccessBlocked();
	}

	public static function isDocumentLocked($documentId, $workflowId)
	{
		// Load-bearing, not a stub: on "locked" the inherited field action waits for an unlock event
		// this module never publishes.
		return false;
	}

	public static function lockDocument($documentId, $workflowId)
	{
		return true;
	}

	public static function unlockDocument($documentId, $workflowId)
	{
		return true;
	}

	public static function createDocument($parentDocumentId, $arFields)
	{
		return false;
	}

	public static function deleteDocument($documentId)
	{
		return false;
	}

	public static function publishDocument($documentId)
	{
		return false;
	}

	public static function unpublishDocument($documentId)
	{
		return false;
	}

	public static function getDocumentForHistory($documentId, $historyIndex)
	{
		return null;
	}

	public static function recoverDocumentFromHistory($documentId, $arDocument)
	{
		return false;
	}

	public static function getAllowableOperations($documentType)
	{
		return [];
	}

	public static function getAllowableUserGroups($documentType, $withExtended = false)
	{
		return [];
	}

	public static function getUsersFromUserGroup($group, $documentId)
	{
		return [];
	}

	/**
	 * The name of a written field as the person sees it in the settings of the step. The command names
	 * its fields for developers - 'title', 'collectionId' - and those names appear in no form anybody
	 * has ever opened, so a report built from them would name fields that seem to come from nowhere.
	 *
	 * The two location fields share one name on purpose: the layer fills in the knowledge base when only
	 * the parent was given (DOC-01), so a report saying "knowledge base" would name a field the person
	 * never touched. What the step did there is move the document, and that is what it is called.
	 */
	public static function fieldName(string $field): string
	{
		$key = match ($field)
		{
			UpdateDocumentFieldsCommand::FIELD_TITLE => 'FIELD_TITLE',
			UpdateDocumentFieldsCommand::FIELD_MARKDOWN => 'FIELD_MARKDOWN',
			UpdateDocumentFieldsCommand::FIELD_COLLECTION_ID,
			UpdateDocumentFieldsCommand::FIELD_PARENT_ID => 'FIELD_LOCATION',
			default => null,
		};

		if ($key === null)
		{
			return $field;
		}

		return Loc::getMessage('NOTE_BIZPROC_DOCUMENT_' . $key) ?? $field;
	}

	/**
	 * Text of a refusal for the process log. The refusals of the write path are named here because this is
	 * where the write path answers from; a node reporting something of its own says it in its own phrases.
	 */
	private static function refusalMessage(?string $errorCode): string
	{
		$key = match ($errorCode)
		{
			UpdateDocumentFieldsCommand::ERROR_DOCUMENT_NOT_FOUND => 'NOT_FOUND',
			UpdateDocumentFieldsCommand::ERROR_DOCUMENT_IN_RECYCLE_BIN => 'IN_RECYCLE_BIN',
			UpdateDocumentFieldsCommand::ERROR_DOCUMENT_ARCHIVED => 'ARCHIVED',
			UpdateDocumentFieldsCommand::ERROR_MAIN_DOCUMENT => 'MAIN_DOCUMENT',
			UpdateDocumentFieldsCommand::ERROR_CONTENT_TOO_LARGE => 'CONTENT_TOO_LARGE',
			UpdateDocumentFieldsCommand::ERROR_UNSAVED_CHANGES => 'UNSAVED_CHANGES',
			UpdateDocumentFieldsCommand::ERROR_COLLECTION_NOT_FOUND => 'COLLECTION_NOT_FOUND',
			UpdateDocumentFieldsCommand::ERROR_INVALID_PARENT => 'INVALID_PARENT',
			UpdateDocumentFieldsCommand::ERROR_PARENT_MAIN_DOCUMENT => 'PARENT_MAIN_DOCUMENT',
			UpdateDocumentFieldsCommand::ERROR_PARENT_IN_RECYCLE_BIN => 'PARENT_IN_RECYCLE_BIN',
			UpdateDocumentFieldsCommand::ERROR_PARENT_ARCHIVED => 'PARENT_ARCHIVED',
			UpdateDocumentFieldsCommand::ERROR_PARENT_INSIDE_SUBTREE => 'PARENT_INSIDE_SUBTREE',
			UpdateDocumentFieldsCommand::ERROR_MOVE_NOT_ALLOWED => 'MOVE_NOT_ALLOWED',
			UpdateDocumentFieldsCommand::ERROR_INVALID_ACTOR => 'INVALID_ACTOR',
			UpdateDocumentFieldsCommand::ERROR_WRITE_FAILED => 'WRITE_FAILED',
			default => 'UNEXPECTED',
		};

		return Loc::getMessage('NOTE_BIZPROC_DOCUMENT_ERROR_' . $key) ?? '';
	}

	/**
	 * Only the filled fields, as ALG-04 says. A field of the step arrives here with an empty value in two
	 * indistinguishable cases - the person left the field blank, and the expression in it resolved to
	 * nothing (a variable of a branch that never ran, a document field that is empty) - and the inherited
	 * form of the action turns both into an empty string before this method is reached. Writing that would
	 * replace a title or the whole content of a document with emptiness, and the overwrite keeps no
	 * version of an empty write: the previous text would be gone with nothing to restore it from.
	 *
	 * A zero survives on purpose: `'0'` is a title somebody may well have chosen.
	 */
	private static function filledFieldsOf(mixed $arFields): array
	{
		if (!is_array($arFields))
		{
			return [];
		}

		// Every form of "nothing" the runtime produces at once - the empty string, null, false, the empty
		// array of a multiple field with no values - and a zero is not one of them: '0' is a title
		// somebody may well have chosen.
		return array_filter($arFields, static fn($value): bool => !\CBPHelper::isEmptyValue($value));
	}

	/**
	 * Text of a field, or null when the field says nothing usable. `stringify()`, not a cast: a value of
	 * the runtime can be a list, and a cast would write the word "Array" into the document and raise a
	 * warning on the way. And a value that is neither a scalar nor something that knows how to become a
	 * string is not text at all - turning it into one gives an empty string, which would replace the
	 * content of a document with nothing, silently, and the overwrite keeps no version of an empty write.
	 */
	private static function textOrNull(array $fields, string $field): ?string
	{
		if (!array_key_exists($field, $fields))
		{
			return null;
		}

		$value = $fields[$field];

		// A list where the field promises one value is answered the way the platform answers it - by the
		// FIRST element that says something. Through the action this never arrives as a list (the
		// inherited form flattens it earlier), but this door is the only way into the document, and it
		// must not answer a shape differently from the surface a person actually configures.
		//
		// The two identifier fields deliberately do NOT follow this rule: a list there is refused by name
		// ({@see UpdateDocumentFieldsCommand::unusableIdentifierIn()}). The platform has a rule for a list
		// in a text field and none for a list in an integer one, and guessing an identifier out of a list
		// is how a document ends up in a knowledge base nobody named.
		//
		// Walked here rather than by the helper of the platform: that one calls trim() on every element
		// and raises a TypeError on an object, and this method is asked before the try of updateDocument()
		// - a throw would turn the answer of this door from a result into an exception. So the first
		// USABLE value is taken, and a list of nothing usable says nothing.
		if (is_array($value))
		{
			// The first leaf that SAYS something, not simply the first scalar: an empty string and a false
			// are scalars too, and taking them would replace the content of a document with nothing.
			// The platform passes empty leaves over in the same way.
			$usable = null;
			array_walk_recursive($value, static function ($leaf) use (&$usable): void {
				if ($usable === null && self::saysSomething($leaf))
				{
					$usable = $leaf;
				}
			});
			$value = $usable;
		}

		if (!self::saysSomething($value))
		{
			return null;
		}

		return \CBPHelper::stringify($value);
	}

	/**
	 * Whether a value has anything to write. Asked of the TEXT the value becomes, not of the value: three
	 * rounds of review found three shapes the check of the value did not describe - an empty string, an
	 * empty list, an empty leaf - and the fourth was an object whose string is empty, which is how an
	 * unfilled value wrapper of the runtime looks. The invariant is one sentence now: this door never
	 * writes an empty string. A zero says something: '0' is a title somebody may well have chosen.
	 */
	private static function saysSomething(mixed $value): bool
	{
		if (!is_scalar($value) && !($value instanceof \Stringable))
		{
			return false;
		}

		return !\CBPHelper::isEmptyValue(\CBPHelper::stringify($value));
	}

	/**
	 * The two identifier fields under the names the command knows them by, values untouched: the command
	 * checks the value a person gave, and a cast on the way there would hide from it exactly what it
	 * looks for. A field nobody filled is not passed at all - not given and given as zero differ.
	 *
	 * @return array<string, mixed>
	 */
	private static function identifiersIn(array $fields): array
	{
		$identifiers = [];
		foreach ([
			self::FIELD_COLLECTION_ID => UpdateDocumentFieldsCommand::FIELD_COLLECTION_ID,
			self::FIELD_PARENT_ID => UpdateDocumentFieldsCommand::FIELD_PARENT_ID,
		] as $field => $name)
		{
			if (array_key_exists($field, $fields))
			{
				$identifiers[$name] = $fields[$field];
			}
		}

		return $identifiers;
	}

	/**
	 * Which location the write command receives. A substitution wider than this turns a plain rename
	 * into a move: the command reads a collection without a parent as "move to the collection root".
	 *
	 * @return array{0: int|null, 1: int|null}
	 */
	private static function resolveLocation(array $fields, int $currentCollectionId): array
	{
		$collectionGiven = array_key_exists(self::FIELD_COLLECTION_ID, $fields);
		$parentGiven = array_key_exists(self::FIELD_PARENT_ID, $fields);

		$parentId = $parentGiven ? self::toPositiveIntOrNull($fields[self::FIELD_PARENT_ID]) : null;

		if ($collectionGiven)
		{
			// A cast is enough here and only here: the command has already refused everything a cast
			// would misread, so what is left reads the same either way.
			return [(int)$fields[self::FIELD_COLLECTION_ID], $parentId];
		}

		if ($parentGiven)
		{
			return [$currentCollectionId, $parentId];
		}

		return [null, null];
	}

	/**
	 * Values of the asked fields, loaded by a SQL select of only those columns. MARKDOWN (up to 1 MB)
	 * is fetched only when the caller named it, or when the engine asked for the whole document.
	 *
	 * @param list<string> $fields
	 * @return array<string, mixed>|null
	 */
	private static function readFields(mixed $documentId, array $fields): ?array
	{
		$documentId = self::identifierIn($documentId);
		if ($documentId === null)
		{
			return null;
		}

		if (isset(self::$missingDocumentCache[$documentId]))
		{
			return null;
		}

		$wanted = [];
		foreach ($fields as $field)
		{
			if (isset(self::FIELD_COLUMNS[$field]))
			{
				$wanted[$field] = true;
			}
		}
		$wanted = array_keys($wanted);

		$cached = self::$fieldValueCache[$documentId] ?? [];
		$missing = [];
		foreach ($wanted as $field)
		{
			if (!array_key_exists($field, $cached))
			{
				$missing[] = $field;
			}
		}

		if ($missing !== [])
		{
			$loaded = self::loadFields($documentId, $missing);
			if ($loaded === null)
			{
				self::$missingDocumentCache[$documentId] = true;
				unset(self::$fieldValueCache[$documentId]);

				return null;
			}

			$cached += $loaded;
			self::$fieldValueCache[$documentId] = $cached;
		}

		$result = [];
		foreach ($wanted as $field)
		{
			$result[$field] = $cached[$field];
		}

		return $result;
	}

	/**
	 * @param list<string> $fields
	 * @return array<string, mixed>|null
	 */
	private static function loadFields(int $documentId, array $fields): ?array
	{
		$columns = ['ID'];
		foreach ($fields as $field)
		{
			$columns[] = self::FIELD_COLUMNS[$field];
		}
		$columns = array_values(array_unique($columns));

		$document = (new DocumentRepository())->getMetaById($documentId, $columns, false);
		if ($document === null)
		{
			return null;
		}

		$values = [];
		if (!in_array(self::FIELD_ID, $fields, true))
		{
			$values[self::FIELD_ID] = (int)$document->getId();
		}
		foreach ($fields as $field)
		{
			$values[$field] = self::fieldValue($document, $field);
		}

		return $values;
	}

	private static function fieldValue(Document $document, string $field): mixed
	{
		return match ($field)
		{
			self::FIELD_ID => (int)$document->getId(),
			self::FIELD_TITLE => (string)$document->getTitle(),
			// The raw column, not getMarkdown(): the field is text, and the decoded form of a legacy
			// json document is an array the engine has nowhere to put.
			self::FIELD_MARKDOWN => $document->getMarkdownRaw(),
			self::FIELD_COLLECTION_ID => (int)$document->getCollectionId(),
			self::FIELD_PARENT_ID => $document->getParentId() === null ? null : (int)$document->getParentId(),
			self::FIELD_CREATED_BY => self::toUserValue($document->getCreatedBy()),
			self::FIELD_UPDATED_BY => self::toUserValue($document->getUpdatedBy()),
			self::FIELD_CREATED_AT => $document->getCreatedAt(),
			self::FIELD_UPDATED_AT => $document->getUpdatedAt(),
			self::FIELD_IS_ARCHIVED => $document->getIsArchived() ? 'Y' : 'N',
			default => null,
		};
	}

	private static function forgetDocument(int $documentId): void
	{
		unset(self::$fieldValueCache[$documentId], self::$missingDocumentCache[$documentId]);
	}

	/**
	 * The identifier as a positive number, or null when the value holds none. A cast reads a list and a
	 * truth as 1, and document 1 is a document somebody really has, so only a value that a number can be
	 * read out of at all reaches the cast.
	 */
	private static function identifierIn(mixed $documentId): ?int
	{
		if (!is_numeric($documentId))
		{
			return null;
		}

		$documentId = (int)$documentId;

		return $documentId > 0 ? $documentId : null;
	}

	private static function toUserValue($userId): ?string
	{
		$userId = (int)$userId;

		return $userId > 0 ? 'user_' . $userId : null;
	}

	private static function toPositiveIntOrNull($value): ?int
	{
		if (!is_numeric($value))
		{
			return null;
		}

		$value = (int)$value;

		return $value > 0 ? $value : null;
	}

	/**
	 * A refusal of the write command, said in the language of the process log. The form of the result is
	 * the command's own {@see UpdateDocumentFieldsCommand::refusal()}; what this layer adds is the phrase.
	 */
	private static function refusal(string $errorCode): Result
	{
		return UpdateDocumentFieldsCommand::refusal($errorCode, self::refusalMessage($errorCode));
	}

	/**
	 * The command reports a refusal by code; the process log needs a sentence. The text the command
	 * carried is REPLACED, not extended: the depths of the module name their refusals in english, for
	 * developers, and DOC-01 puts the understandable russian phrase here. The cause itself stays in the
	 * custom data of the error, where only code looks. The report - what was applied and where it
	 * stopped - travels on untouched.
	 */
	private static function localizeRefusal(Result $result): Result
	{
		$localized = new Result();
		foreach ($result->getErrors() as $error)
		{
			$code = (string)$error->getCode();
			$customData = $error->getCustomData();
			$customData = is_array($customData) ? $customData : [];
			// A step that refused without raising carries no cause of its own, and its message is the whole
			// account of what went wrong. Replacing that text with the general phrase and keeping nothing
			// would leave a developer reading WRITE_FAILED with the code and no reason at all.
			$customData['cause'] ??= $error->getMessage();

			$localized->addError(new Error(self::refusalMessage($code), $code, $customData));
		}

		$data = $result->getData();
		$firstError = $localized->getErrors()[0] ?? null;
		$data['errorMessage'] = $firstError === null ? '' : $firstError->getMessage();
		$localized->setData($data);

		return $localized;
	}
}

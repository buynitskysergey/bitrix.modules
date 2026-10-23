<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\Activity;

use Bitrix\Crm\V2\Internal\Entity\Activity\Enum\AutocompleteRule;
use Bitrix\Crm\V2\Internal\Entity\Activity\Enum\CompletionStatus;
use Bitrix\Crm\V2\Internal\Entity\Activity\Enum\DescriptionType;
use Bitrix\Crm\V2\Internal\Entity\Activity\Enum\Direction;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Type\DateTime;

/**
 * Typed model of an activity: the fields every activity has, whatever provider it belongs to.
 * A plain data object - no ORM, no persistence, no side effects.
 *
 * WHAT IS NOT HERE. Raw serialized fields go into {@see ProviderPayload}, provider-specific
 * columns into the typed data of the provider, and the columns the storage needs but the domain
 * does not (owner, thread, notify, search content) travel through {@see StorageFieldTransit}.
 * Legacy formats - 'Y'/'N', upper-case field names, the max-date deadline sentinel - never reach
 * this model: converting them is the job of the repository and of the legacy facade.
 *
 * CHANGE TRACKING. Setters mark their field as changed, so a partial update writes only what the
 * caller touched; {@see self::internalSet()} hydrates without marking. Fields with no setter are
 * read-only by contract: the id and the audit stamps are assigned by the storage, the deadline is
 * derived from the start time by the provider, and the incoming-channel flag lives in a table of
 * its own.
 */
final class Activity
{
	public const id = 'id';
	public const providerId = 'providerId';
	public const providerTypeId = 'providerTypeId';
	public const subject = 'subject';
	public const description = 'description';
	public const descriptionType = 'descriptionType';
	public const isCompleted = 'isCompleted';
	public const completionStatus = 'completionStatus';
	public const responsibleId = 'responsibleId';
	public const authorId = 'authorId';
	public const editorId = 'editorId';
	public const isImportant = 'isImportant';
	public const direction = 'direction';
	public const createdTime = 'createdTime';
	public const updatedTime = 'updatedTime';
	public const startTime = 'startTime';
	public const endTime = 'endTime';
	public const deadline = 'deadline';
	public const parentActivityId = 'parentActivityId';
	public const autocompleteRule = 'autocompleteRule';
	public const bindings = 'bindings';
	public const communications = 'communications';
	public const files = 'files';
	public const isIncomingChannel = 'isIncomingChannel';
	public const pingOffsets = 'pingOffsets';

	private array $changedFields = [];

	// Read-only by contract - assigned by the storage or derived
	private ?int $id = null;
	private ?DateTime $createdTime = null;
	private ?DateTime $updatedTime = null;
	private ?DateTime $deadline = null;
	private ?bool $isIncomingChannel = null;

	private ?string $providerId = null;
	private ?string $providerTypeId = null;
	private ?string $subject = null;
	private ?string $description = null;
	private ?DescriptionType $descriptionType = null;
	private ?bool $isCompleted = null;
	private ?CompletionStatus $completionStatus = null;
	private ?int $responsibleId = null;
	private ?int $authorId = null;
	private ?int $editorId = null;
	private ?bool $isImportant = null;
	private ?Direction $direction = null;
	private ?DateTime $startTime = null;
	private ?DateTime $endTime = null;
	private ?int $parentActivityId = null;
	private ?AutocompleteRule $autocompleteRule = null;

	/** @var ActivityBinding[]|null */
	private ?array $bindings = null;

	/** @var ActivityCommunication[]|null */
	private ?array $communications = null;

	/** @var ActivityStorageElement[]|null */
	private ?array $files = null;

	/** @var int[]|null */
	private ?array $pingOffsets = null;

	// --- change tracking ---

	/**
	 * @return string[]
	 * @internal
	 */
	public function getChangedFieldNames(): array
	{
		return array_keys($this->changedFields);
	}

	/** @internal */
	public function hasChangedFields(): bool
	{
		return $this->changedFields !== [];
	}

	/** @internal */
	public function isFieldChanged(string $fieldName): bool
	{
		return isset($this->changedFields[$fieldName]);
	}

	/** @internal */
	public function resetChangedFields(): void
	{
		$this->changedFields = [];
	}

	/**
	 * Assigns the field the way {@see self::internalSet()} does and says it is to be written all the same.
	 *
	 * The one caller of it is the step publishing the event before an update: what a subscriber of that event
	 * changed has to reach the row, and an update writes only the fields marked as changed - while the value
	 * itself has to go in through the hydration path, because the shapes coming back off a legacy field array do
	 * not fit the setters, one of which refuses the assignment outright.
	 *
	 * @internal Hydration path of the pipeline. Client code uses the setters, which mark the field themselves.
	 * @throws ArgumentException on an unknown field name.
	 */
	public function internalSetAsChanged(string $fieldName, mixed $value): static
	{
		$this->internalSet($fieldName, $value);
		$this->markChanged($fieldName);

		return $this;
	}

	/**
	 * Takes the mark off the field and leaves its value where it is: an update writes only what is marked, so
	 * this is how a step says "do not write this one after all".
	 *
	 * The one caller of it is the step publishing the event before an update: a subscriber that takes a key out
	 * of the field array is saying what legacy says by leaving the column out of its statement, and a mark left
	 * behind would write the value all the same.
	 *
	 * @internal Hydration path of the pipeline. Client code uses the setters, which mark the field themselves.
	 */
	public function internalUnmarkChanged(string $fieldName): static
	{
		unset($this->changedFields[$fieldName]);

		return $this;
	}

	private function markChanged(string $fieldName): void
	{
		$this->changedFields[$fieldName] = true;
	}

	// --- read-only fields ---

	public function getId(): ?int
	{
		return $this->id;
	}

	public function getCreatedTime(): ?DateTime
	{
		return $this->createdTime;
	}

	public function getUpdatedTime(): ?DateTime
	{
		return $this->updatedTime;
	}

	/**
	 * Deadline of the activity, null when it has none.
	 *
	 * There is no setter: the core derives the deadline from the start time, and moving it is a
	 * provider operation. The max-date sentinel the column uses for "no deadline" is resolved by
	 * the repository, so this model only ever sees a real date or null.
	 */
	public function getDeadline(): ?DateTime
	{
		return $this->deadline;
	}

	/**
	 * Whether the activity is registered as an incoming channel. Read-only: the flag lives in
	 * `b_crm_act_incoming_channel` and is maintained by the pipeline, not by the model.
	 */
	public function isIncomingChannel(): ?bool
	{
		return $this->isIncomingChannel;
	}

	// --- writable fields ---

	public function getProviderId(): ?string
	{
		return $this->providerId;
	}

	public function setProviderId(string $providerId): static
	{
		$this->providerId = $providerId;
		$this->markChanged(self::providerId);

		return $this;
	}

	public function getProviderTypeId(): ?string
	{
		return $this->providerTypeId;
	}

	public function setProviderTypeId(?string $providerTypeId): static
	{
		$this->providerTypeId = $providerTypeId;
		$this->markChanged(self::providerTypeId);

		return $this;
	}

	public function getSubject(): ?string
	{
		return $this->subject;
	}

	public function setSubject(string $subject): static
	{
		$this->subject = $subject;
		$this->markChanged(self::subject);

		return $this;
	}

	public function getDescription(): ?string
	{
		return $this->description;
	}

	public function setDescription(string $description): static
	{
		$this->description = $description;
		$this->markChanged(self::description);

		return $this;
	}

	public function getDescriptionType(): ?DescriptionType
	{
		return $this->descriptionType;
	}

	public function setDescriptionType(DescriptionType $descriptionType): static
	{
		$this->descriptionType = $descriptionType;
		$this->markChanged(self::descriptionType);

		return $this;
	}

	public function isCompleted(): ?bool
	{
		return $this->isCompleted;
	}

	public function setCompleted(bool $isCompleted): static
	{
		$this->isCompleted = $isCompleted;
		$this->markChanged(self::isCompleted);

		return $this;
	}

	public function getCompletionStatus(): ?CompletionStatus
	{
		return $this->completionStatus;
	}

	public function setCompletionStatus(CompletionStatus $completionStatus): static
	{
		$this->completionStatus = $completionStatus;
		$this->markChanged(self::completionStatus);

		return $this;
	}

	public function getResponsibleId(): ?int
	{
		return $this->responsibleId;
	}

	public function setResponsibleId(int $responsibleId): static
	{
		$this->responsibleId = $responsibleId;
		$this->markChanged(self::responsibleId);

		return $this;
	}

	public function getAuthorId(): ?int
	{
		return $this->authorId;
	}

	public function setAuthorId(int $authorId): static
	{
		$this->authorId = $authorId;
		$this->markChanged(self::authorId);

		return $this;
	}

	public function getEditorId(): ?int
	{
		return $this->editorId;
	}

	public function setEditorId(int $editorId): static
	{
		$this->editorId = $editorId;
		$this->markChanged(self::editorId);

		return $this;
	}

	public function isImportant(): ?bool
	{
		return $this->isImportant;
	}

	public function setImportant(bool $isImportant): static
	{
		$this->isImportant = $isImportant;
		$this->markChanged(self::isImportant);

		return $this;
	}

	public function getDirection(): ?Direction
	{
		return $this->direction;
	}

	public function setDirection(Direction $direction): static
	{
		$this->direction = $direction;
		$this->markChanged(self::direction);

		return $this;
	}

	public function getStartTime(): ?DateTime
	{
		return $this->startTime;
	}

	public function setStartTime(?DateTime $startTime): static
	{
		$this->startTime = $startTime;
		$this->markChanged(self::startTime);

		return $this;
	}

	public function getEndTime(): ?DateTime
	{
		return $this->endTime;
	}

	public function setEndTime(?DateTime $endTime): static
	{
		$this->endTime = $endTime;
		$this->markChanged(self::endTime);

		return $this;
	}

	public function getParentActivityId(): ?int
	{
		return $this->parentActivityId;
	}

	/**
	 * Attaches the activity to its parent - the previous activity of a mail thread or of a
	 * hierarchy.
	 *
	 * A parent may be given only once: once the activity has one, changing or detaching it is
	 * an error here. Legacy refuses the same change silently, and keeping that silence is the
	 * job of the facade.
	 *
	 * @throws ArgumentException when the activity already has a different parent.
	 */
	public function setParentActivityId(?int $parentActivityId): static
	{
		if ($this->parentActivityId !== null && $this->parentActivityId !== $parentActivityId)
		{
			throw new ArgumentException(
				"The parent of an activity may be set only once, it is already {$this->parentActivityId}",
			);
		}

		$this->parentActivityId = $parentActivityId;
		$this->markChanged(self::parentActivityId);

		return $this;
	}

	public function getAutocompleteRule(): ?AutocompleteRule
	{
		return $this->autocompleteRule;
	}

	public function setAutocompleteRule(AutocompleteRule $autocompleteRule): static
	{
		$this->autocompleteRule = $autocompleteRule;
		$this->markChanged(self::autocompleteRule);

		return $this;
	}

	// --- collections ---

	/** @return ActivityBinding[]|null */
	public function getBindings(): ?array
	{
		return $this->bindings;
	}

	public function setBindings(ActivityBinding ...$bindings): static
	{
		$this->bindings = $bindings;
		$this->markChanged(self::bindings);

		return $this;
	}

	/** @return ActivityCommunication[]|null */
	public function getCommunications(): ?array
	{
		return $this->communications;
	}

	public function setCommunications(ActivityCommunication ...$communications): static
	{
		$this->communications = $communications;
		$this->markChanged(self::communications);

		return $this;
	}

	/** @return ActivityStorageElement[]|null */
	public function getFiles(): ?array
	{
		return $this->files;
	}

	public function setFiles(ActivityStorageElement ...$files): static
	{
		$this->files = $files;
		$this->markChanged(self::files);

		return $this;
	}

	/** @return int[]|null */
	public function getPingOffsets(): ?array
	{
		return $this->pingOffsets;
	}

	public function setPingOffsets(int ...$pingOffsets): static
	{
		$this->pingOffsets = $pingOffsets;
		$this->markChanged(self::pingOffsets);

		return $this;
	}

	// --- hydration ---

	/**
	 * Sets any field, including the read-only ones, without marking it as changed.
	 *
	 * @internal Hydration path of the repository and of the pipeline. Client code uses the setters.
	 * @throws ArgumentException on an unknown field name.
	 */
	public function internalSet(string $fieldName, mixed $value): static
	{
		match ($fieldName)
		{
			self::id => $this->id = $value,
			self::createdTime => $this->createdTime = $value,
			self::updatedTime => $this->updatedTime = $value,
			self::deadline => $this->deadline = $value,
			self::isIncomingChannel => $this->isIncomingChannel = $value,
			self::providerId => $this->providerId = $value,
			self::providerTypeId => $this->providerTypeId = $value,
			self::subject => $this->subject = $value,
			self::description => $this->description = $value,
			self::descriptionType => $this->descriptionType = $value,
			self::isCompleted => $this->isCompleted = $value,
			self::completionStatus => $this->completionStatus = $value,
			self::responsibleId => $this->responsibleId = $value,
			self::authorId => $this->authorId = $value,
			self::editorId => $this->editorId = $value,
			self::isImportant => $this->isImportant = $value,
			self::direction => $this->direction = $value,
			self::startTime => $this->startTime = $value,
			self::endTime => $this->endTime = $value,
			self::parentActivityId => $this->parentActivityId = $value,
			self::autocompleteRule => $this->autocompleteRule = $value,
			self::bindings => $this->bindings = $value,
			self::communications => $this->communications = $value,
			self::files => $this->files = $value,
			self::pingOffsets => $this->pingOffsets = $value,
			default => throw new ArgumentException("Unknown field: {$fieldName}"),
		};

		return $this;
	}

	/**
	 * A copy sharing nothing changeable with the original.
	 *
	 * The moments are objects and the getters hand them out, so a caller moving one in place would move it for
	 * every holder of the model. Everything else is a value or an object that cannot change after it is built:
	 * the elements of the three collections ({@see ActivityBinding}, {@see ActivityCommunication},
	 * {@see ActivityStorageElement}) are read-only, and the arrays holding them are values of their own.
	 */
	public function __clone(): void
	{
		$this->createdTime = self::copyOfMoment($this->createdTime);
		$this->updatedTime = self::copyOfMoment($this->updatedTime);
		$this->deadline = self::copyOfMoment($this->deadline);
		$this->startTime = self::copyOfMoment($this->startTime);
		$this->endTime = self::copyOfMoment($this->endTime);
	}

	private static function copyOfMoment(?DateTime $moment): ?DateTime
	{
		return $moment === null ? null : clone $moment;
	}
}

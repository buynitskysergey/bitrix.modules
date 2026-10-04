<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\SourceGeneration\Matching;

use Bitrix\Mail\Internal\Entity\SourceGeneration\Context;
use Bitrix\Mail\Internals\MailEntityDataTable;
use Bitrix\Mail\Internals\MailEntityOptionsTable;
use Bitrix\Mail\Internals\MessageFingerprintTable;
use Bitrix\Mail\Internals\SourceGenerationMatchTable;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;

/**
 * The matcher of an imported uid against the local logical messages (ALG-01).
 *
 * It decides MATCHED, NEW or AMBIGUOUS by indexed lookups only: the reference of a
 * managed migrator first, then the normalized Message-ID, then the normalized content.
 * It never walks the history pairwise, and it never merges an ambiguous letter - such a
 * letter becomes a separate logical message.
 *
 * Repeating the import of the same uid reuses the stored terminal result instead of
 * deciding again.
 */
final class MessageMatcher
{
	/**
	 * How much of the tail of the shorter body is ignored when two bodies are
	 * compared: a body cut to the allowed field size loses its last word.
	 */
	private const BODY_COMPARISON_MARGIN = 16;

	private const FINGERPRINT_PAGE_SIZE = 200;

	/**
	 * The metadata a stored message is recognized by. A letter claiming to be it has to
	 * repeat every one of them the stored message still has ({@see corroborates()}).
	 */
	private const CONTROL_FIELDS = ['subject', 'from', 'to', 'cc', 'date'];

	/** @var array<int, bool> Mailboxes whose candidate fingerprints this run has already completed */
	private array $fingerprintsReady = [];

	/**
	 * The results this instance has stored, so the letter it has just decided about is not
	 * read back to be completed.
	 *
	 * @var array<string, array{id: int, messageId: int}> Generation and uid row => its result.
	 */
	private array $storedResults = [];

	public function __construct(
		private readonly CanonicalMessageNormalizer $normalizer = new CanonicalMessageNormalizer(),
		private readonly FingerprintBuilder $fingerprints = new FingerprintBuilder(),
		private readonly CandidateProvider $candidates = new CandidateProvider(),
		private readonly MigratorReferenceLocator $migratorReferences = new MigratorReferenceLocator(),
	)
	{
	}

	/**
	 * The canonicalization both sides of the matching go through.
	 */
	public function getNormalizer(): CanonicalMessageNormalizer
	{
		return $this->normalizer;
	}

	public function getFingerprints(): FingerprintBuilder
	{
		return $this->fingerprints;
	}

	/**
	 * Builds the fingerprints the lookup needs for the local messages of the mailbox.
	 * Import must not start before it reports completion: a missing fingerprint would
	 * turn an existing letter into a duplicate.
	 *
	 * @param int $maxMessages The page budget of this call, 0 keeps going until the mailbox is covered.
	 * @return bool The mailbox is fully covered.
	 * @throws ArgumentException|ObjectPropertyException|SystemException
	 */
	public function ensureCandidateFingerprints(Context $context, int $maxMessages = 0): bool
	{
		if ($this->fingerprintsReady[$context->mailboxId] ?? false)
		{
			return true;
		}

		$this->buildCandidateFingerprints($context, 0, $maxMessages);

		return $this->fingerprintsReady[$context->mailboxId] ?? false;
	}

	/**
	 * The candidate fingerprints of the mailbox cover its history: a walk of this instance
	 * has reached the end of it.
	 */
	public function hasCandidateFingerprints(Context $context): bool
	{
		return $this->fingerprintsReady[$context->mailboxId] ?? false;
	}

	/**
	 * Builds the fingerprints of one budgeted slice of the mailbox and reports where the
	 * next slice starts, so a caller that keeps the cursor of its own continues instead of
	 * paying for the history it has already walked.
	 *
	 * The cursor is a message id and message ids only grow, which is what makes it a high
	 * water mark rather than a one way finish line: a slice that finds nothing beyond the
	 * cursor leaves it where it is, and a later run of the same operation picks up exactly
	 * the letters that arrived meanwhile.
	 *
	 * @param int $afterMessageId The cursor of the caller, 0 starts the walk.
	 * @param int $maxMessages The budget of this call in messages, 0 keeps going until the mailbox is covered.
	 * @return int The cursor to continue from, equal to the given one when the mailbox is covered.
	 * @throws ArgumentException|ObjectPropertyException|SystemException
	 */
	public function buildCandidateFingerprints(Context $context, int $afterMessageId, int $maxMessages): int
	{
		$cursor = max($afterMessageId, 0);
		$processed = 0;
		// letters that arrived after the previous walk are not covered until this one ends
		$this->fingerprintsReady[$context->mailboxId] = false;

		while ($maxMessages <= 0 || $processed < $maxMessages)
		{
			$reached = $this->fingerprints->buildBatch($context->mailboxId, $cursor, self::FINGERPRINT_PAGE_SIZE);
			if ($reached === 0)
			{
				$this->fingerprintsReady[$context->mailboxId] = true;

				return $cursor;
			}

			$cursor = $reached;
			$processed += self::FINGERPRINT_PAGE_SIZE;
		}

		return $cursor;
	}

	/**
	 * Decides what the incoming uid means for the local history and stores the result
	 * before any delivery side effect of the import.
	 *
	 * @param string $uidId The provisional uid row of the imported generation, already registered with MESSAGE_ID = 0.
	 * @param string|null $migratorReference The reference of a managed migrator, when the letter carried one
	 *        {@see MigratorReference}.
	 * @param bool|null $decidedBefore Receives whether the journal already held a result of this
	 *        uid, which is what tells an attempt repeated after an interrupted one from the first
	 *        attempt of a letter. The row is read here anyway, so the answer costs nothing.
	 * @throws ArgumentException|ObjectPropertyException|SystemException
	 */
	public function match(
		Context $context,
		string $uidId,
		CanonicalMessageData $incoming,
		?string $migratorReference = null,
		?bool &$decidedBefore = null,
	): MatchDecision
	{
		// The stored row is read once and handed over: it is what the result is written on top of
		$row = $this->loadStoredRow($context, $uidId);
		$decidedBefore = $row !== null;
		$stored = $this->decisionOfRow($row);

		if ($stored !== null && $stored->isTerminal())
		{
			return $stored;
		}

		$decision = $this->decide($context, $incoming, $migratorReference, true);
		$this->persist($context, $uidId, $decision, $row);

		return $decision;
	}

	/**
	 * What the matching would decide for an incoming letter, storing nothing at all.
	 *
	 * The shadow pass of a rollout needs the distribution of the outcomes before the
	 * import creates or links a single message, and it must leave no result behind: a
	 * stored one would be reused by the real import instead of being decided again.
	 *
	 * @throws ArgumentException|ObjectPropertyException|SystemException
	 */
	public function preview(
		Context $context,
		CanonicalMessageData $incoming,
		?string $migratorReference = null,
	): MatchDecision
	{
		return $this->decide($context, $incoming, $migratorReference, true);
	}

	/**
	 * The same rehearsal for a letter whose body has not been downloaded.
	 *
	 * The transferable identifier of a migrator and the Message-ID are read from the header
	 * block, and the indexed content lookup needs no body either: the payload it hashes is
	 * the envelope and the number of attachments. Only the confirmation of a candidate found
	 * by that lookup compares the bodies - and there the answer would be a guess, so it is
	 * not given.
	 *
	 * @return MatchDecision|null Null when the outcome cannot be told without the body.
	 * @throws ArgumentException|ObjectPropertyException|SystemException
	 */
	public function previewFromHeaders(
		Context $context,
		CanonicalMessageData $incoming,
		?string $migratorReference = null,
	): ?MatchDecision
	{
		return $this->decide($context, $incoming, $migratorReference, false);
	}

	/**
	 * Reports the logical message the importer created for a NEW or an AMBIGUOUS
	 * decision, which makes the stored result terminal.
	 *
	 * @throws ArgumentException|ObjectPropertyException|SystemException
	 */
	public function finalize(Context $context, string $uidId, int $messageId): void
	{
		$result = $this->storedResults[$this->resultKey($context, $uidId)] ?? $this->loadStoredResult($context, $uidId);

		if ($result === null || $messageId <= 0 || $result['messageId'] > 0)
		{
			return;
		}

		SourceGenerationMatchTable::update($result['id'], [
			'MESSAGE_ID' => $messageId,
			'DATE_UPDATE' => new DateTime(),
		]);

		$this->rememberResult($context, $uidId, $result['id'], $messageId);
	}

	/**
	 * Writes down the letter the tail append stage has just put onto the new source, under
	 * the coordinates the server gave it.
	 *
	 * Nothing is decided here and nothing may be: the letter was assembled out of a local
	 * message and sent by us, so its local identity is known before the new source has ever
	 * been asked about it. The result is stored terminal, which is what makes the reverse
	 * read of that letter stop at {@see match()} instead of comparing anything - and the
	 * comparison is exactly what must not happen, because the copy we assembled is not the
	 * bytes the original arrived as.
	 *
	 * @param string $uidId The placement row the coordinates of the append lead to, built by
	 *        {@see UidIdentity::build()} the way the engine builds it when it reads a letter.
	 * @param string $reason Which of the two routes of the stage answered for the letter: the
	 *        coordinates the source named, or the one time mark of the fallback route.
	 * @throws ArgumentException|ObjectPropertyException|SystemException
	 */
	public function recordAppendedMessage(
		Context $context,
		string $uidId,
		int $messageId,
		string $reason = MatchDecision::REASON_APPENDED,
	): bool
	{
		if ($uidId === '' || $messageId <= 0)
		{
			return false;
		}

		$row = $this->loadStoredRow($context, $uidId);
		$stored = $this->decisionOfRow($row);

		// The coordinates of an append are new ones, so a terminal result under them is ours already
		if ($stored !== null && $stored->isTerminal())
		{
			return $stored->messageId === $messageId;
		}

		$this->persist(
			$context,
			$uidId,
			new MatchDecision(
				SourceGenerationMatchTable::STATE_MATCHED,
				$messageId,
				$reason,
			),
			$row,
		);

		return true;
	}

	/**
	 * Rewrites a stored result of this generation to another letter: the placement was imported
	 * as a new one, and the delivery of the active source has since proved that letter to be one
	 * the mailbox already holds.
	 *
	 * A call of its own rather than a relaxation of {@see finalize()}, because it does what
	 * nothing else here may: it replaces an identity already written down. Every other path
	 * reuses a terminal result instead of deciding again, and only the reconciliation of a late
	 * delivery overrules one.
	 *
	 * @throws ArgumentException|ObjectPropertyException|SystemException
	 */
	public function rebind(Context $context, string $uidId, int $messageId, string $reason): bool
	{
		$row = $this->loadStoredRow($context, $uidId);

		if ($row === null || $messageId <= 0)
		{
			return false;
		}

		$this->persist(
			$context,
			$uidId,
			new MatchDecision(SourceGenerationMatchTable::STATE_MATCHED, $messageId, $reason),
			$row,
		);

		return true;
	}

	/**
	 * Whether two representations of one mailbox are one and the same letter, by the very
	 * confirmation a decision of the content route goes through: nothing the two both know may
	 * differ, everything the second one kept must be repeated by the first, and neither may
	 * answer for a body the other has not got.
	 *
	 * No route is involved and none is needed: both letters are stored here already and the
	 * caller has found them under one hash, so what it asks for is the confirmation alone.
	 *
	 * @param CanonicalMessageData $candidate The letter the first one claims to be.
	 */
	public function agreesWith(CanonicalMessageData $incoming, CanonicalMessageData $candidate): bool
	{
		return $incoming->hasBody === $candidate->hasBody
			&& !$this->conflicts($incoming, $candidate)
			&& $this->corroborates($incoming, $candidate)
		;
	}

	/**
	 * Drops the stored decision about an imported uid, so the next pass decides anew instead of
	 * reusing a verdict that named a letter which is no longer there.
	 *
	 * @throws ArgumentException|ObjectPropertyException|SystemException
	 */
	public function forget(Context $context, string $uidId): void
	{
		$row = $this->loadStoredRow($context, $uidId);

		if ($row !== null)
		{
			// The candidate list of the decision hangs on the row and goes with it. It is written
			// to b_mail_entity_data, so it is removed from there and not from the options table.
			MailEntityDataTable::deleteList([
				'=MAILBOX_ID' => $context->mailboxId,
				'=ENTITY_TYPE' => MailEntityOptionsTable::SOURCE_GENERATION_MATCH_TYPE_NAME,
				'=ENTITY_ID' => (string)$row['ID'],
			]);

			SourceGenerationMatchTable::delete((int)$row['ID']);
		}

		unset($this->storedResults[$this->resultKey($context, $uidId)]);
	}

	/**
	 * Remembers how this generation represents a logical message, so the next
	 * generation can find it by any of the accumulated representations.
	 *
	 * @throws ArgumentException|ObjectPropertyException|SystemException
	 */
	public function registerRepresentation(
		Context $context,
		int $messageId,
		CanonicalMessageData $data,
		?string $migratorReference = null,
	): void
	{
		$this->fingerprints->storeRepresentation(
			$context->mailboxId,
			$messageId,
			$context->generationId,
			$data,
			$migratorReference,
		);
	}

	/**
	 * @param bool $bodyAvailable False for a letter whose parts are still on the server.
	 * @return MatchDecision|null Null only when the body is missing and the decision depends on it.
	 * @throws ArgumentException|ObjectPropertyException|SystemException
	 */
	private function decide(
		Context $context,
		CanonicalMessageData $incoming,
		?string $migratorReference,
		bool $bodyAvailable,
	): ?MatchDecision
	{
		$byMigrator = $this->lookupByMigrator($context, (string)$migratorReference);

		if (count($byMigrator) === 1)
		{
			// The correspondence still has to agree with the control metadata and the body
			$survivors = $this->selectSurvivors($context, $incoming, $byMigrator);

			if (
				count($survivors['ids']) === 1
				&& !$survivors['uncorroborated']
				&& !($bodyAvailable && $survivors['storedBodyUnanswered'])
			)
			{
				return new MatchDecision(
					SourceGenerationMatchTable::STATE_MATCHED,
					(int)reset($survivors['ids']),
					MatchDecision::REASON_MIGRATOR_REFERENCE,
				);
			}
		}

		$route = MatchDecision::REASON_MESSAGE_ID;
		$candidates = $this->lookup(
			$context,
			MessageFingerprintTable::KIND_MESSAGE_ID,
			$this->fingerprints->hashMessageId($incoming),
		);

		if ($candidates === [])
		{
			$route = MatchDecision::REASON_CONTENT;
			$candidates = $this->lookupContent($context, $incoming);
		}

		if ($candidates === [])
		{
			return new MatchDecision(
				SourceGenerationMatchTable::STATE_NEW,
				0,
				MatchDecision::REASON_NO_CANDIDATE,
			);
		}

		$survivors = $this->selectSurvivors($context, $incoming, $candidates);

		if (count($survivors['ids']) !== 1)
		{
			return new MatchDecision(
				SourceGenerationMatchTable::STATE_AMBIGUOUS,
				0,
				$survivors['ids'] === [] ? MatchDecision::REASON_NO_SURVIVOR : MatchDecision::REASON_SEVERAL_CANDIDATES,
				$candidates,
			);
		}

		/*
			A candidate of the content route survived the envelope, so from here on the bodies
			decide: whether they agree, and whether the representations are comparable at all.
			Neither question can be answered by a letter whose body was left on the server.
		*/
		if (!$bodyAvailable && $route === MatchDecision::REASON_CONTENT)
		{
			return null;
		}

		/*
			A candidate whose own representation is incomplete carries no body to
			compare with, and the content route is not strong enough to do without one.

			On any route, the stored message having a body the incoming letter has none of
			is a refusal of its own: everything such a letter says about itself is the
			envelope it reproduced, and the one thing that could still be asked of it - the
			body of the message it claims to be - it does not answer. The opposite case is
			the point of the whole transfer: a stored message whose body never downloaded is
			completed from the letter that carries one.

			Neither question is asked of a rehearsal that has downloaded no bodies at all:
			there every letter looks bodiless, the import will have the body when it runs,
			and nothing is written on the strength of the answer anyway.
		*/
		if (
			($bodyAvailable && $survivors['storedBodyUnanswered'])
			|| ($route === MatchDecision::REASON_CONTENT && $survivors['unconfirmed'])
		)
		{
			return new MatchDecision(
				SourceGenerationMatchTable::STATE_AMBIGUOUS,
				0,
				MatchDecision::REASON_INCOMPLETE,
				$candidates,
			);
		}

		/*
			Every route is a claim the letter makes about itself: the transferable identifier
			and the Message-ID are headers, and a sender writes whatever it likes into them.
			A claim alone therefore never hands an incoming letter the identity of a stored
			one: every control field that stored letter still has must agree as well.
		*/
		if ($survivors['uncorroborated'])
		{
			return new MatchDecision(
				SourceGenerationMatchTable::STATE_AMBIGUOUS,
				0,
				MatchDecision::REASON_UNCORROBORATED,
				$candidates,
			);
		}

		return new MatchDecision(
			SourceGenerationMatchTable::STATE_MATCHED,
			(int)reset($survivors['ids']),
			$route,
		);
	}

	/**
	 * Candidates of the route of a managed transfer service.
	 *
	 * The reference a copied letter carries is a coordinate of the source it was taken from,
	 * so the placement the mailbox keeps under that coordinate is the first thing asked. What
	 * answers when it leads nowhere is the reference stored as a fingerprint of its own: a
	 * repeated pass over a letter this migration has already imported finds it there even
	 * after the placement of the old source is gone.
	 *
	 * @return int[]
	 * @throws ArgumentException|ObjectPropertyException|SystemException
	 */
	private function lookupByMigrator(Context $context, string $migratorReference): array
	{
		$byCoordinate = $this->migratorReferences->locate($context, $migratorReference);

		if ($byCoordinate !== [])
		{
			return $byCoordinate;
		}

		return $this->lookup(
			$context,
			MessageFingerprintTable::KIND_MIGRATOR_ID,
			$this->fingerprints->hashMigratorReference($migratorReference),
		);
	}

	/**
	 * @return int[]
	 * @throws ArgumentException|ObjectPropertyException|SystemException
	 */
	private function lookup(Context $context, string $kind, string $hash): array
	{
		return $this->candidates->findByHash($context->mailboxId, $kind, $hash);
	}

	/**
	 * Candidates of the content route, by every number of attachments this letter can be found
	 * under: the module classifies the parts of a letter by two different rules, and a letter
	 * saved through one of them answers another number than the same letter fetched through the
	 * other {@see CanonicalMessageData::getContentPayloadVariants()}.
	 *
	 * @return int[]
	 * @throws ArgumentException|ObjectPropertyException|SystemException
	 */
	private function lookupContent(Context $context, CanonicalMessageData $incoming): array
	{
		$hashes = $this->fingerprints->hashContentVariants($incoming);

		if (count($hashes) === 1)
		{
			return $this->lookup($context, MessageFingerprintTable::KIND_CONTENT, reset($hashes));
		}

		// The answer is keyed by hash: the letters of every variant make one set of candidates
		$byHash = $this->candidates->findByHashes(
			$context->mailboxId,
			MessageFingerprintTable::KIND_CONTENT,
			$hashes,
		);

		$candidates = [];
		foreach ($byHash as $messageIds)
		{
			foreach ($messageIds as $messageId)
			{
				$candidates[(int)$messageId] = (int)$messageId;
			}
		}

		return array_values($candidates);
	}

	/**
	 * Confirms the candidates by the normalized metadata and the body.
	 *
	 * A candidate is answered as it arrives, and what survives of it is its id alone: the
	 * lookups above are indexed but not bounded - one Message-ID can be carried by as much
	 * of the history as the sender likes - and a canonical view holds a whole body, so the
	 * views of all the candidates at once would be the history in memory.
	 *
	 * @param int[] $candidates
	 * @return array{ids: int[], unconfirmed: bool, storedBodyUnanswered: bool, uncorroborated: bool}
	 * @throws ArgumentException|ObjectPropertyException|SystemException
	 */
	private function selectSurvivors(Context $context, CanonicalMessageData $incoming, array $candidates): array
	{
		$ids = [];
		$unconfirmed = false;
		$storedBodyUnanswered = false;
		$uncorroborated = false;

		foreach ($this->candidates->streamCanonical($context->mailboxId, $candidates) as $messageId => $candidate)
		{
			if ($this->conflicts($incoming, $candidate))
			{
				continue;
			}

			$ids[] = $messageId;
			$unconfirmed = $unconfirmed || $incoming->hasBody !== $candidate->hasBody;
			$storedBodyUnanswered = $storedBodyUnanswered || ($candidate->hasBody && !$incoming->hasBody);
			$uncorroborated = $uncorroborated || !$this->corroborates($incoming, $candidate);
		}

		return [
			'ids' => $ids,
			'unconfirmed' => $unconfirmed,
			'storedBodyUnanswered' => $storedBodyUnanswered,
			'uncorroborated' => $uncorroborated,
		];
	}

	/**
	 * Whether anything besides the route itself says that the two are one letter.
	 *
	 * Every control field the stored message really kept has to be there and equal on the
	 * incoming side. That is what separates this from {@see conflicts()}, which lets an
	 * absent value pass on either side: a field the stored message lost asks for nothing,
	 * but a field it has and the incoming letter left out is a refusal. Two copies of one
	 * letter carry one envelope, so nothing legitimate is asked twice - while a letter
	 * composed to be taken for a stored one has to reproduce that envelope whole.
	 *
	 * A stored message that kept no control field at all is confirmed by the bodies of the
	 * two, and by nothing else: the number of the attachments agrees between any two
	 * letters that carry none.
	 */
	private function corroborates(CanonicalMessageData $incoming, CanonicalMessageData $candidate): bool
	{
		$agreed = 0;

		foreach (self::CONTROL_FIELDS as $field)
		{
			$stored = $candidate->$field;

			if ($this->isBlank($stored))
			{
				continue;
			}

			if ($stored !== $incoming->$field)
			{
				return false;
			}

			++$agreed;
		}

		return $agreed > 0 || ($incoming->hasBody && $candidate->hasBody);
	}

	/**
	 * @param string|string[]|int $value A control field of a canonical view.
	 */
	private function isBlank(string|array|int $value): bool
	{
		return $value === '' || $value === [] || $value === 0;
	}

	private function conflicts(CanonicalMessageData $incoming, CanonicalMessageData $candidate): bool
	{
		/*
			The number of attachments of the candidate has to be a number the incoming letter could
			answer - its own, or the one the other classification rule of the module would give the
			very same parts. Demanding its own number alone would throw away the twin the lookup by
			variants has just found, and the letter would arrive as a new one.
		*/
		if (!$incoming->countsAs($candidate->attachmentCount))
		{
			return true;
		}

		if ($this->attachmentsDiffer($incoming, $candidate))
		{
			return true;
		}

		if ($this->differs($incoming->subject, $candidate->subject))
		{
			return true;
		}

		if (
			$this->differsList($incoming->from, $candidate->from)
			|| $this->differsList($incoming->to, $candidate->to)
			|| $this->differsList($incoming->cc, $candidate->cc)
		)
		{
			return true;
		}

		if ($incoming->date > 0 && $candidate->date > 0 && $incoming->date !== $candidate->date)
		{
			return true;
		}

		if (
			$incoming->hasMessageId()
			&& $candidate->hasMessageId()
			&& $incoming->messageId !== $candidate->messageId
		)
		{
			return true;
		}

		return $incoming->hasBody
			&& $candidate->hasBody
			&& !$this->bodiesAgree($incoming->body, $candidate->body)
		;
	}

	private function differs(string $incoming, string $candidate): bool
	{
		return $incoming !== '' && $candidate !== '' && $incoming !== $candidate;
	}

	/**
	 * @param string[] $incoming
	 * @param string[] $candidate
	 */
	private function differsList(array $incoming, array $candidate): bool
	{
		return $incoming !== [] && $candidate !== [] && $incoming !== $candidate;
	}

	/**
	 * Whether the two disagree about what their attachments are.
	 *
	 * The names are compared only when both sides know them all
	 * ({@see CanonicalMessageData::hasNamedAttachments()}): a letter whose attachments were
	 * left to the lazy path is confirmed by their number alone, as it always was.
	 */
	private function attachmentsDiffer(CanonicalMessageData $incoming, CanonicalMessageData $candidate): bool
	{
		return $incoming->hasNamedAttachments()
			&& $candidate->hasNamedAttachments()
			&& $incoming->attachmentNames !== $candidate->attachmentNames
		;
	}

	/**
	 * A stored body may be shorter than the one just fetched: it could have been
	 * prepared as a long message or cut to the size the database accepts. The
	 * shorter body is therefore expected to open the longer one.
	 */
	private function bodiesAgree(string $incoming, string $candidate): bool
	{
		if ($incoming === $candidate)
		{
			return true;
		}

		$incomingLength = mb_strlen($incoming);
		$candidateLength = mb_strlen($candidate);

		/*
			Equal lengths leave the margin nothing to cover: a cut shortens the side it took the tail
			from, so two bodies of one and the same length are either identical - answered above - or
			two different letters. The margin belongs to the case it was made for: one side is shorter
			because a hard cut took its last word.
		*/
		if ($incomingLength === $candidateLength)
		{
			return false;
		}

		$length = min($incomingLength, $candidateLength) - self::BODY_COMPARISON_MARGIN;

		return $length > 0 && mb_substr($incoming, 0, $length) === mb_substr($candidate, 0, $length);
	}

	private function decisionOfRow(?array $row): ?MatchDecision
	{
		return $row === null
			? null
			: new MatchDecision(
				(string)$row['STATE'],
				(int)$row['MESSAGE_ID'],
				MatchDecision::REASON_STORED,
			)
		;
	}

	/**
	 * @throws ArgumentException|ObjectPropertyException|SystemException
	 */
	private function loadStoredRow(Context $context, string $uidId): ?array
	{
		return SourceGenerationMatchTable::getRow([
			'select' => ['ID', 'STATE', 'MESSAGE_ID', 'ATTEMPTS'],
			'filter' => [
				'=GENERATION_ID' => $context->generationId,
				'=UID_ID' => $uidId,
			],
		]);
	}

	/**
	 * @param array|null $row The stored result of the letter, as {@see loadStoredRow()} read it.
	 * @throws ArgumentException|ObjectPropertyException|SystemException
	 */
	private function persist(Context $context, string $uidId, MatchDecision $decision, ?array $row): void
	{
		$now = new DateTime();

		if ($row === null)
		{
			$added = SourceGenerationMatchTable::add([
				'MAILBOX_ID' => $context->mailboxId,
				'GENERATION_ID' => $context->generationId,
				'UID_ID' => $uidId,
				'OPERATION_ID' => $context->operationId,
				'STATE' => $decision->state,
				'MESSAGE_ID' => $decision->messageId,
				'NORMALIZER_VERSION' => CanonicalMessageNormalizer::VERSION,
				'REASON_CODE' => $decision->reasonCode,
				'ATTEMPTS' => 1,
				'DATE_CREATE' => $now,
				'DATE_UPDATE' => $now,
			]);

			$this->rememberResult($context, $uidId, (int)$added->getId(), $decision->messageId);

			return;
		}

		SourceGenerationMatchTable::update((int)$row['ID'], [
			'STATE' => $decision->state,
			'MESSAGE_ID' => $decision->messageId,
			'NORMALIZER_VERSION' => CanonicalMessageNormalizer::VERSION,
			'REASON_CODE' => $decision->reasonCode,
			'ATTEMPTS' => (int)$row['ATTEMPTS'] + 1,
			'DATE_UPDATE' => $now,
		]);

		$this->rememberResult($context, $uidId, (int)$row['ID'], $decision->messageId);
	}

	/**
	 * @return array{id: int, messageId: int}|null
	 * @throws ArgumentException|ObjectPropertyException|SystemException
	 */
	private function loadStoredResult(Context $context, string $uidId): ?array
	{
		$row = $this->loadStoredRow($context, $uidId);

		return $row === null
			? null
			: ['id' => (int)$row['ID'], 'messageId' => (int)$row['MESSAGE_ID']]
		;
	}

	private function rememberResult(Context $context, string $uidId, int $resultId, int $messageId): void
	{
		if ($resultId > 0)
		{
			$this->storedResults[$this->resultKey($context, $uidId)] = ['id' => $resultId, 'messageId' => $messageId];
		}
	}

	private function resultKey(Context $context, string $uidId): string
	{
		return $context->generationId . "\n" . $uidId;
	}
}

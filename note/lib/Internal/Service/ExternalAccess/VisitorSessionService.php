<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\ExternalAccess;

use Bitrix\Main\Application;
use Bitrix\Main\Session\SessionInterface;
use Bitrix\Note\Internal\Model\ExternalAccessCollectionTable;
use Bitrix\Note\Internal\Model\ExternalAccessTable;
use Bitrix\Note\Internal\Repository\ExternalAccessRepository;

/**
 * The session state of a visitor of a public link: whether they opened this link and whether they
 * named its password.
 *
 * Two marks, and they are deliberately different. The context mark is set when ANY link resolves
 * successfully, and it is what tells a request that came through a link from a request that only
 * looks like one. The confirmation mark is set solely after a correct password. Merging them would
 * leave a password-less link - the majority of them - with no attachments at all, because the
 * attachment verdict asks the context, not the password.
 *
 * Each mark holds a fingerprint of the secret it was given for, and lives exactly as long as that
 * secret does. A confirmation is bound to the password hash the visitor was actually checked
 * against, so changing or dropping the password invalidates every confirmation already handed
 * out. A context mark is bound to the token
 * of the link, so switching the link off and on again - which issues a new token - invalidates
 * every context mark of the previous generation by itself: a visitor whose session outlived the
 * reissue stops being recognised as one who came through this link, and the attachments of the
 * knowledge base close along with its pages. Neither the hash nor the token leaves this
 * service, and neither mark needs a sweep of its own on revocation.
 *
 * The mark belongs to a visitor by where it is kept and not by what it holds: a digest of the very
 * session that stores the mark could differ only together with that session, and the marks go away
 * with it.
 *
 * The marks live in the kernel session because that is the session an anonymous visitor is
 * guaranteed to have.
 */
class VisitorSessionService
{
	private const CONTEXT_MARKS_KEY = 'note.external.context';
	private const CONFIRM_MARKS_KEY = 'note.external.confirm';

	public function __construct(
		private readonly ExternalAccessRepository $accessRepository = new ExternalAccessRepository(),
	) {}

	/**
	 * Marks the link as the one this visitor came through. Called on a successful resolve of ANY
	 * link, password-protected or not.
	 *
	 * The mark carries the fingerprint of the token the visitor actually presented, and the caller
	 * hands it over for the same reason markConfirmed() is handed the verdict of the comparison: a
	 * re-read here would be a second question, and between the two the moderator can switch the link
	 * off and on again. The record would then answer with the freshly issued token, and the mark
	 * would be made for a token the visitor has never seen - an access surviving the very reissue
	 * that is supposed to end it. The opposite direction stays harmless: hasContext() compares the
	 * mark against the token stored at that moment, so a mark of a superseded token simply stops
	 * matching.
	 *
	 * An empty token has nothing to bind to and is not marked - it is a link that has never been
	 * enabled, and no visitor can have come through it.
	 */
	public function markContext(int $linkRecordId, string $presentedToken): void
	{
		if ($linkRecordId <= 0 || $presentedToken === '')
		{
			return;
		}

		$marks = $this->readMarks(self::CONTEXT_MARKS_KEY);
		$marks[$linkRecordId] = $this->fingerprint($presentedToken);
		$this->writeMarks(self::CONTEXT_MARKS_KEY, $marks);
	}

	public function hasContext(int $linkRecordId): bool
	{
		if ($linkRecordId <= 0)
		{
			return false;
		}

		$stored = $this->readMarks(self::CONTEXT_MARKS_KEY)[$linkRecordId] ?? null;
		if (!is_string($stored))
		{
			return false;
		}

		$token = $this->readToken($linkRecordId);

		return $token !== null && $token !== '' && hash_equals($this->fingerprint($token), $stored);
	}

	/**
	 * The links of this knowledge base the visitor has actually come through.
	 *
	 * The last step of the chain "document -> knowledge base -> link record" walked by the paths
	 * that carry no token: the file actions and the verdict of the attachment connector.
	 * The set of marks is read by the service that owns it, and it deliberately knows
	 * nothing about documents - what arrives here is the identifier of a knowledge base.
	 *
	 * Several candidates are normal: one visitor may hold marks of two links into the same knowledge
	 * base. The order of the marks is kept, so the caller takes the first one that is still valid
	 * and confirmed instead of an arbitrary one.
	 *
	 * A mark left from a previous generation of a link is not a candidate at all: its fingerprint no
	 * longer matches the token of the record, and the file paths - the only ones that get here - close
	 * on a reissue together with everything else.
	 *
	 * @return int[] ids of the link records marked by this visitor whose scope holds the collection
	 */
	public function contextsForCollection(int $collectionId): array
	{
		if ($collectionId <= 0)
		{
			return [];
		}

		$marked = [];
		foreach ($this->readMarks(self::CONTEXT_MARKS_KEY) as $rawId => $storedFingerprint)
		{
			$linkRecordId = (int)$rawId;
			if ($linkRecordId > 0 && is_string($storedFingerprint))
			{
				$marked[$linkRecordId] = $storedFingerprint;
			}
		}
		if ($marked === [])
		{
			return [];
		}

		$rows = ExternalAccessCollectionTable::query()
			->setSelect(['ACCESS_ID'])
			->where('COLLECTION_ID', $collectionId)
			->whereIn('ACCESS_ID', array_keys($marked))
			->setCacheTtl(0)
			->fetchAll()
		;

		$inScope = array_map(static fn(array $row): int => (int)$row['ACCESS_ID'], $rows);
		if ($inScope === [])
		{
			return [];
		}

		$isInScope = array_flip($inScope);
		$tokens = $this->readTokens($inScope);

		$contexts = [];
		foreach ($marked as $linkRecordId => $storedFingerprint)
		{
			$token = $tokens[$linkRecordId] ?? '';
			if (
				isset($isInScope[$linkRecordId])
				&& $token !== ''
				&& hash_equals($this->fingerprint($token), $storedFingerprint)
			)
			{
				$contexts[] = $linkRecordId;
			}
		}

		return $contexts;
	}

	/**
	 * Records that the password of this link has been named correctly. The caller has already
	 * compared it - this service does not compare passwords - and hands over the verdict of that
	 * comparison, because the mark is bound to the very secret the comparison passed against.
	 *
	 * The hash is taken from the verdict and never re-read here. A re-read is a second question, and
	 * between the two the moderator can replace the password: the mark would then be made for the new
	 * secret on the strength of the old one having been named, and the visitor would keep an access
	 * he never proved. A mark left over for a replaced password is the harmless direction of the same
	 * race - isConfirmed() compares it against the hash stored at that moment and asks again.
	 *
	 * @return bool false when there is nothing to bind the confirmation to: the comparison did not
	 *              pass, or there is no link to mark.
	 */
	public function markConfirmed(int $linkRecordId, PasswordCheckResult $verified): bool
	{
		if ($linkRecordId <= 0 || !$verified->isAllowed)
		{
			return false;
		}

		$verifiedHash = $verified->getVerifiedHash();
		if ($verifiedHash === null)
		{
			// The link carries no password: isConfirmed() answers true for it with no mark at all, and
			// a mark of a secret that does not exist has nothing to stay valid against.
			return true;
		}

		$marks = $this->readMarks(self::CONFIRM_MARKS_KEY);
		$marks[$linkRecordId] = $this->fingerprint($verifiedHash);
		$this->writeMarks(self::CONFIRM_MARKS_KEY, $marks);

		return true;
	}

	/**
	 * The single form of the question about the password: for a link without one the answer is
	 * true, so no caller has to find out first whether the link has a password. The password hash
	 * never leaves this service - the caller passes only the id of the link record.
	 */
	public function isConfirmed(int $linkRecordId): bool
	{
		$passwordHash = $this->readPasswordHash($linkRecordId);
		if ($passwordHash === null)
		{
			return false;
		}

		if ($passwordHash === '')
		{
			// Nothing to confirm.
			return true;
		}

		$stored = $this->readMarks(self::CONFIRM_MARKS_KEY)[$linkRecordId] ?? null;

		return is_string($stored) && hash_equals($this->fingerprint($passwordHash), $stored);
	}

	/**
	 * A mark is bound to the secret it was given for and to nothing else - the stored password hash
	 * for a confirmation, the token for a context - so that replacing that secret invalidates the
	 * mark by itself. The secret is never stored in the session, only this digest of it.
	 */
	private function fingerprint(string $secret): string
	{
		return hash('sha256', $secret);
	}

	/**
	 * @return string|null the current token ('' when the link has never been enabled), null when
	 *                     there is no such record.
	 */
	private function readToken(int $linkRecordId): ?string
	{
		if ($linkRecordId <= 0)
		{
			return null;
		}

		return $this->readTokens([$linkRecordId])[$linkRecordId] ?? null;
	}

	/**
	 * Read live, every time, and never through anything that remembers an answer. A context mark
	 * stops working because the token it was given for is gone, so this read is what closes a session
	 * that outlived a reissue: an answer from memory would keep a dead mark alive for as long as
	 * that memory lasts. It costs one read by the primary key per request, and that is the price of
	 * the property.
	 *
	 * @param int[] $linkRecordIds
	 * @return array<int, string> current tokens keyed by link record id, '' for a link never enabled
	 */
	private function readTokens(array $linkRecordIds): array
	{
		if ($linkRecordIds === [])
		{
			return [];
		}

		$rows = ExternalAccessTable::query()
			->setSelect(['ID', 'TOKEN'])
			->whereIn('ID', $linkRecordIds)
			->setCacheTtl(0)
			->fetchAll()
		;

		$tokens = [];
		foreach ($rows as $row)
		{
			$tokens[(int)$row['ID']] = (string)($row['TOKEN'] ?? '');
		}

		return $tokens;
	}

	/**
	 * The hash comes from the repository that owns the table and is read afresh every time, so a
	 * changed password invalidates the confirmations it should. Only the one column is read: the
	 * record around it is not needed here, and the hash itself goes no further than this service -
	 * what leaves here is a digest of it.
	 *
	 * @return string|null the stored hash ('' when the link has no password), null when there is no
	 *                     such record.
	 */
	private function readPasswordHash(int $linkRecordId): ?string
	{
		if ($linkRecordId <= 0)
		{
			return null;
		}

		return $this->accessRepository->findPasswordHashById($linkRecordId);
	}

	/**
	 * @return array<int, mixed> marks keyed by link record id
	 */
	private function readMarks(string $key): array
	{
		$session = $this->getSession();
		$marks = $session->has($key) ? $session->get($key) : null;

		return is_array($marks) ? $marks : [];
	}

	/**
	 * @param array<int, mixed> $marks
	 */
	private function writeMarks(string $key, array $marks): void
	{
		$this->getSession()->set($key, $marks);
	}

	// Seam for tests: the kernel session is process-wide state.
	protected function getSession(): SessionInterface
	{
		return Application::getInstance()->getKernelSession();
	}
}

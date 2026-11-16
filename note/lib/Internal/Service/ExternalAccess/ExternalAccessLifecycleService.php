<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\ExternalAccess;

use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Security\Password;
use Bitrix\Main\Type\Date;
use Bitrix\Note\Internal\Access\Service\CollectionAccessService;
use Bitrix\Note\Internal\Repository\ExternalAccessRepository;

/**
 * The whole lifecycle of the public link of one knowledge base.
 *
 * One saving operation creates and updates alike, idempotently keyed by the knowledge base, so
 * enabling a link and issuing its token never leave as two calls: a caller cannot produce an
 * enabled record without a token, and cannot re-enable a record while keeping the old address.
 *
 * The password lives here as a hash and nowhere else: nothing above this class receives either the
 * password or its hash, and the confirmation screen of the visitor asks this service instead of
 * comparing anything itself.
 *
 * A moderator action that takes away what has already been handed out - switching a working link
 * off, changing its password - publishes the revoke command, exactly as the cascades of
 * archiving and deletion do. Without it a visitor who only keeps the tab open sits in the interface
 * until their next request of their own.
 */
final class ExternalAccessLifecycleService
{
	public function __construct(
		private readonly ExternalAccessRepository $repository = new ExternalAccessRepository(),
		private readonly TokenGenerator $tokenGenerator = new TokenGenerator(),
		private readonly RevokePullService $revokePullService = new RevokePullService(),
		private readonly PasswordProtectionService $passwordProtectionService = new PasswordProtectionService(),
	) {}

	/**
	 * Stores the state of the link of one knowledge base.
	 *
	 * $options tells "leave as is" from "clear" by the presence of the key:
	 * - `expiresAt`: key absent - the last day is kept; `null` - the term is dropped; Date - set.
	 * - `password`: key absent - the password is kept; `null` or an empty string - dropped; a
	 *   string - hashed and stored.
	 *
	 * A record that was not active before this call - never enabled, disabled or already expired -
	 * gets a brand new token when it is enabled. Extending an expired link therefore lands in the
	 * same branch as re-enabling: the address that has already been handed out and already cut off
	 * does not come back to life from an edit of the term.
	 *
	 * A save that takes working access away publishes the revoke command - see accessTakenAway().
	 *
	 * @param array{expiresAt?: Date|null, password?: string|null} $options
	 * @return array the stored record
	 * @throws ArgumentException when the last day is earlier than today
	 */
	public function save(int $collectionId, int $userId, bool $isEnabled, array $options = []): array
	{
		$record = $this->repository->findByCollection($collectionId);
		$fields = [
			'IS_ENABLED' => $isEnabled,
			'UPDATED_BY' => $userId,
		];

		if ($record === null)
		{
			$fields['CREATED_BY'] = $userId;
			$fields['ACCESS_LEVEL'] = CollectionAccessService::LEVEL_VIEW;
		}

		if (array_key_exists('expiresAt', $options))
		{
			$expiresAt = $options['expiresAt'];
			if ($expiresAt !== null)
			{
				$this->assertNotBeforeToday($expiresAt);
			}

			$fields['EXPIRES_AT'] = $expiresAt;
		}

		if (array_key_exists('password', $options))
		{
			$password = $options['password'];
			$fields['PASSWORD_HASH'] = ($password === null || $password === '')
				? null
				: Password::hash($password)
			;
		}

		if ($record !== null && $this->passwordDropped($record, $fields))
		{
			// A link without a password has nothing to guess: the counter of attempts must not
			// outlive the password it was protecting, or the link stays different from one that
			// never had a password until the hour of the counter runs out.
			$this->passwordProtectionService->forgetAttempts((int)$record['ID']);
		}

		$wasActive = $record !== null && $record['IS_ENABLED'] && !$this->isExpired($record);
		if ($isEnabled && !$wasActive)
		{
			return $this->tokenGenerator->issueUnique(
				fn(string $token): array => $this->repository->saveByCollection(
					$collectionId,
					['TOKEN' => $token] + $fields,
				),
			);
		}

		$saved = $this->repository->saveByCollection($collectionId, $fields);

		if ($wasActive && $this->accessTakenAway($record, $fields, $isEnabled))
		{
			$this->publishRevoke((int)$record['ID']);
		}

		return $saved;
	}

	/**
	 * Whether this save takes away a password that was stored. A change of the password is not a
	 * drop: there is still a word to guess, and the counter of attempts stays where it was.
	 *
	 * @param array $record the record as it was before this save
	 * @param array $fields the fields being written
	 */
	private function passwordDropped(array $record, array $fields): bool
	{
		return array_key_exists('PASSWORD_HASH', $fields)
			&& $fields['PASSWORD_HASH'] === null
			&& $record['PASSWORD_HASH'] !== null
		;
	}

	/**
	 * Whether this save took away access that was already working.
	 *
	 * Switching the link off is the plain case. A new password is the same thing: a confirmation is
	 * bound to the hash it was given for, so every confirmation already handed out becomes
	 * worthless at once and the visitor has to name the word again. Dropping the password only
	 * widens the access and revokes nobody.
	 *
	 * Re-hashing the same word counts as a change too - the stored hash is salted, and the
	 * confirmations are bound to it and not to the word.
	 *
	 * @param array $record the record as it was before this save
	 * @param array $fields the fields being written
	 */
	private function accessTakenAway(array $record, array $fields, bool $isEnabled): bool
	{
		if (!$isEnabled)
		{
			return true;
		}

		return array_key_exists('PASSWORD_HASH', $fields)
			&& $fields['PASSWORD_HASH'] !== null
			&& $fields['PASSWORD_HASH'] !== $record['PASSWORD_HASH']
		;
	}

	/**
	 * The revoke command is irreversible, and this service carries no transaction of its own: a
	 * caller may have wrapped it in one. A background job runs at the very end of the request, when
	 * any such transaction is already closed.
	 */
	private function publishRevoke(int $linkRecordId): void
	{
		$service = $this->revokePullService;
		Application::getInstance()->addBackgroundJob(static function () use ($service, $linkRecordId): void {
			$service->publishRevoke($linkRecordId);
		});
	}

	/**
	 * Drops the settings of a knowledge base whose link has never been enabled. A record that
	 * worked at least once stays: its token has been handed out, and the fact of it is what tells a
	 * draft from a revoked link.
	 *
	 * @return bool whether a draft was dropped
	 */
	public function discard(int $collectionId): bool
	{
		return $this->repository->deleteDraftByCollection($collectionId);
	}

	/**
	 * Puts the link out on behalf of the system - archiving, deleting and the other cascades. The
	 * token, the term and the password survive, and the author of the settings is not overwritten
	 * by whoever happened to trigger the cascade.
	 */
	public function disable(int $collectionId): void
	{
		$this->repository->disableByCollection($collectionId);
	}

	/**
	 * The record id of the link of a knowledge base, or null when it has none. The cascades read it
	 * before they touch anything, because the revoke command is addressed by this id alone and has
	 * to be publishable when neither the knowledge base nor the scope row exists any more.
	 */
	public function findLinkId(int $collectionId): ?int
	{
		return $this->repository->findByCollection($collectionId)['ID'] ?? null;
	}

	/**
	 * Puts the link out by its own id - see findLinkId() for why the cascades address it this way.
	 */
	public function disableLink(int $linkRecordId): void
	{
		$this->repository->disableById($linkRecordId);
	}

	/**
	 * Drops the knowledge base from the scope of its link. Called where the knowledge base itself
	 * is being deleted: the record survives as the history of a link that once worked, the row
	 * pointing at a collection that no longer exists does not.
	 */
	public function deleteScopeRows(int $collectionId): void
	{
		$this->repository->removeCollectionFromScope($collectionId);
	}

	/**
	 * Whether the visitor named the password of the link. Asked by the confirmation screen, which
	 * knows the knowledge base of the link it resolved and never sees the hash.
	 *
	 * A link with no password answers "allowed" and not "wrong password". The moderator can take the
	 * password away while a visitor is standing at the prompt, and the prompt is then unanswerable:
	 * no secret is left that anything could match, so every attempt - the previously correct one
	 * included - would be refused until the visitor thought of reloading the page. Protection that
	 * has been removed is not protection that failed.
	 *
	 * The verdict carries the version of the secret it passed against, so the confirmation is bound
	 * to what was verified and not to whatever the column holds by the time the mark is written -
	 * see PasswordCheckResult.
	 */
	public function checkPassword(int $collectionId, string $password): PasswordCheckResult
	{
		$record = $this->repository->findByCollection($collectionId);
		if ($record === null)
		{
			return PasswordCheckResult::refused();
		}

		$storedHash = $record['PASSWORD_HASH'];
		if ($storedHash === null)
		{
			return PasswordCheckResult::unprotected();
		}

		return Password::equals($storedHash, $password)
			? PasswordCheckResult::matched((string)$storedHash)
			: PasswordCheckResult::refused()
		;
	}

	/**
	 * @param array $record
	 */
	public function isExpired(array $record): bool
	{
		$expiresAt = $record['EXPIRES_AT'] ?? null;
		if (!$expiresAt instanceof Date)
		{
			return false;
		}

		return $expiresAt->getTimestamp() < (new Date())->getTimestamp();
	}

	/**
	 * @throws ArgumentException
	 */
	private function assertNotBeforeToday(Date $expiresAt): void
	{
		if ($expiresAt->getTimestamp() < (new Date())->getTimestamp())
		{
			throw new ArgumentException('Note external access: the last day is earlier than today', 'expiresAt');
		}
	}
}

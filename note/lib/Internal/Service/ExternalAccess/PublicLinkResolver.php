<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\ExternalAccess;

use Bitrix\Note\Internal\Access\Service\CollectionAccessService;
use Bitrix\Note\Internal\Model\Collection;
use Bitrix\Note\Internal\Repository\CollectionRepository;
use Bitrix\Note\Internal\Repository\ExternalAccessRepository;
use Bitrix\Note\Internal\Service\License\LicenseService;

/**
 * Turns a token into the link it stands for, or refuses.
 *
 * Two callers ask the same question about a public request: the pre-filter of the public actions
 * (ActionFilter\ExternalAccessLink) and the public page component, which has to decide the fate of
 * the request before it mounts anything. The chain of questions lives here, in one copy, because
 * the order of the questions is what the answer tells about the existence of a link: two chains
 * drifting apart is exactly the leak the whole circuit is built to avoid.
 *
 * The password is deliberately not asked here. Resolving a token and confirming a password are two
 * different questions with two different session marks, and the callers differ in what
 * they do with the second one: the filter refuses the action, the page mounts a skeleton with the
 * password prompt on top.
 *
 * One refusal covers every case - no token, malformed token, disabled, expired, reissued, archived
 * base, closed tariff - so neither a code nor a text tells "there was none" from "it was closed".
 */
final class PublicLinkResolver
{
	private const TOKEN_PATTERN = '/^[0-9A-Za-z]{32}$/D';

	public function __construct(
		private readonly ExternalAccessRepository $repository = new ExternalAccessRepository(),
		private readonly CollectionRepository $collectionRepository = new CollectionRepository(),
		private readonly LicenseService $licenseService = new LicenseService(),
	) {}

	public function resolve(string $token): ?ResolvedPublicLink
	{
		if ($this->licenseService->isAccessBlocked())
		{
			return null;
		}

		if ($token === '' || preg_match(self::TOKEN_PATTERN, $token) !== 1)
		{
			// Cheap shape check first: malformed tokens never reach the database.
			return null;
		}

		return $this->buildLink($this->repository->findActiveByToken($token));
	}

	/**
	 * The same chain of questions, asked about a link the caller already knows by its record.
	 *
	 * The file paths of the public circuit carry no token at all and reach the record
	 * through the marks of the visitor session instead. What has to be asked about
	 * the link after that is identical to the token path, and asking it here is the whole point of
	 * this class: a second chain living somewhere else is exactly the drift it exists to prevent.
	 */
	public function resolveByRecordId(int $linkRecordId): ?ResolvedPublicLink
	{
		if ($linkRecordId <= 0 || $this->licenseService->isAccessBlocked())
		{
			return null;
		}

		return $this->buildLink($this->repository->findActiveById($linkRecordId));
	}

	/**
	 * @param array|null $record an active record, as returned by the repository
	 */
	private function buildLink(?array $record): ?ResolvedPublicLink
	{
		if ($record === null)
		{
			return null;
		}

		if ($record['ACCESS_LEVEL'] !== CollectionAccessService::LEVEL_VIEW)
		{
			// Fail closed: a level this release does not implement is a refusal, not a wider right.
			return null;
		}

		$collection = $this->collectionRepository->getById($record['COLLECTION_ID']);
		if (!$collection instanceof Collection || $collection->getIsArchived())
		{
			return null;
		}

		return new ResolvedPublicLink(
			(int)$record['ID'],
			(int)$record['COLLECTION_ID'],
			(int)$record['ACCESS_LEVEL'],
			$collection,
			(string)($record['TOKEN'] ?? ''),
		);
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Note\Public\Provider;

use Bitrix\Main\Engine\UrlManager;
use Bitrix\Main\Type\Date;
use Bitrix\Note\Internal\Access\Service\CollectionAccessService;
use Bitrix\Note\Internal\Repository\ExternalAccessRepository;
use Bitrix\Note\Internal\Service\ExternalAccess\ExternalAccessLifecycleService;

/**
 * The read side of the public link: the state form of the link for the moderator.
 *
 * Neither the password nor its hash ever leaves this class, and the address is given out only for
 * a link that actually works - enabled and not past its last day.
 */
class ExternalAccessProvider
{
	/**
	 * The address of a visitor lives under /pub/ - the one prefix the portal authorization lets
	 * through without an account. The path is counted from the directory of the site and not from
	 * the root of the host: see publicBasePath().
	 */
	public const PUBLIC_URL_PATH = '/pub/note/';
	private const ACCESS_LEVEL_CODES = [
		CollectionAccessService::LEVEL_NONE => CollectionAccessService::LEVEL_CODE_NONE,
		CollectionAccessService::LEVEL_VIEW => CollectionAccessService::LEVEL_CODE_VIEW,
		CollectionAccessService::LEVEL_MANAGE => CollectionAccessService::LEVEL_CODE_MANAGE,
		CollectionAccessService::LEVEL_MODERATE => CollectionAccessService::LEVEL_CODE_MODERATE,
	];

	public function __construct(
		private readonly ExternalAccessRepository $repository = new ExternalAccessRepository(),
		private readonly ExternalAccessLifecycleService $lifecycleService = new ExternalAccessLifecycleService(),
	) {}

	/**
	 * The state of the link of one knowledge base. Reading creates nothing: a knowledge
	 * base without a record answers with the zero form.
	 *
	 * @return array{
	 *     isEnabled: bool,
	 *     url: string|null,
	 *     expiresAt: string|null,
	 *     hasPassword: bool,
	 *     accessLevel: string,
	 *     wasEverEnabled: bool,
	 * }
	 */
	public function getState(int $collectionId): array
	{
		$record = $this->repository->findByCollection($collectionId);
		if ($record === null)
		{
			return [
				'isEnabled' => false,
				'url' => null,
				'expiresAt' => null,
				'hasPassword' => false,
				'accessLevel' => CollectionAccessService::LEVEL_CODE_VIEW,
				'wasEverEnabled' => false,
			];
		}

		$expiresAt = $record['EXPIRES_AT'];

		return [
			'isEnabled' => $record['IS_ENABLED'],
			'url' => $this->buildUrl($record),
			'expiresAt' => $expiresAt instanceof Date ? $expiresAt->format('Y-m-d') : null,
			'hasPassword' => $record['PASSWORD_HASH'] !== null,
			'accessLevel' => $this->accessLevelCode($record['ACCESS_LEVEL']),
			'wasEverEnabled' => $record['TOKEN'] !== null,
		];
	}

	/**
	 * The base every address of the public circuit hangs off, the directory of the site included.
	 *
	 * A box portal may live in a directory (DIR=/portal/), and the route of the page is declared
	 * under it: intranet/install/routes/intranet.php builds the /pub/ group from the prefix of the
	 * site, and the rewrite rule of the same address is written with it too. An address built from
	 * the root would therefore fall outside the route it is meant to open.
	 *
	 * One method for the three places that need the base - the link of the moderator, the router of
	 * the mounted application (note.public component) and the token read back out of it on the
	 * client - so the three cannot disagree about the same address.
	 *
	 * The directory is an argument only so that it can be named in a test: a constant of the page
	 * cannot be given two values in one process. Every caller of the module leaves it out.
	 */
	public static function publicBasePath(?string $siteDir = null): string
	{
		$dir = trim((string)($siteDir ?? self::currentSiteDir()));
		if ($dir === '' || $dir === '/')
		{
			return self::PUBLIC_URL_PATH;
		}

		return '/' . trim($dir, '/') . self::PUBLIC_URL_PATH;
	}

	private static function currentSiteDir(): string
	{
		// A cloud portal and any portal at the root of its host answer '/' here, and that is the case
		// the address has always been built for.
		return defined('SITE_DIR') ? (string)SITE_DIR : '/';
	}

	/**
	 * @param array $record
	 */
	private function buildUrl(array $record): ?string
	{
		$token = $record['TOKEN'];
		if ($token === null || !$record['IS_ENABLED'] || $this->lifecycleService->isExpired($record))
		{
			return null;
		}

		return $this->portalOrigin() . self::publicBasePath() . $token . '/';
	}

	private function portalOrigin(): string
	{
		try
		{
			return UrlManager::getInstance()->getHostUrl();
		}
		catch (\Throwable)
		{
			// No request context (agent, CLI): the address stays relative, which is still usable.
			return '';
		}
	}

	private function accessLevelCode(int $level): string
	{
		return self::ACCESS_LEVEL_CODES[$level] ?? CollectionAccessService::LEVEL_CODE_VIEW;
	}
}

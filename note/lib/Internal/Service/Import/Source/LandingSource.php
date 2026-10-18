<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Import\Source;

use Bitrix\Main\IO\File;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\Uri;
use Bitrix\Note\Internal\Mention\MentionType;
use Bitrix\Note\Internal\Settings;
use Bitrix\Note\Internal\Service\Import\ImportLogger;
use Bitrix\Note\Internal\Integration\Landing\BlockReader;
use Bitrix\Note\Internal\Integration\Landing\FileReader;
use Bitrix\Note\Internal\Integration\Landing\MenuReader;
use Bitrix\Note\Internal\Integration\Landing\PageReader;
use Bitrix\Note\Internal\Integration\Landing\RightsReader;
use Bitrix\Note\Internal\Integration\Landing\SiteReader;
use Bitrix\Note\Internal\Service\Import\Source\Landing\AccessLevelMapper;
use Bitrix\Note\Internal\Service\Import\Source\Landing\LandingId;
use Bitrix\Note\Internal\Service\Import\Transformer\Landing\BlockConverter;
use Bitrix\Note\Internal\Service\Import\Transformer\Landing\MenuConverter;
use Bitrix\Note\Internal\Service\Import\Transformer\LandingMentionTransformer;

/**
 * Reads Knowledge Base 1.0 (landing module, TYPE='KNOWLEDGE') from the same portal.
 *
 * external_id and anchor formats live in {@see LandingId} (TPL-01): the key of a page
 * carries its folder because a landing page CODE is unique only inside one, while the
 * anchor carries the id of the source element - the base its site id, a page its b_landing
 * row id, a section its folder id, each in a prefix of its own. In-content links are brought
 * to those same ids here ({@see resolvePublicAddressLinks}), because that needs landing's own rows.
 *
 * The home page of the base becomes no document: it is absent from the tree, while
 * getDocumentsPage() marks it `isCollectionDescription`, on which
 * {@see \Bitrix\Note\Internal\Service\Import\Step\FillContentStep} writes its content into the
 * knowledge base description.
 *
 * The index page of a section becomes no document of its own either: landing shows it at the
 * address of the folder, so its content is the BODY of the section document ([P3.T19]). The
 * section keeps its own key and anchor - it stays a section - while everything that pointed at
 * the page (its old address, an in-content link, a menu item and the position it gives) is
 * brought to the section instead. {@see sectionBodyPageId} is the single statement of the rule.
 *
 * attachment URL syntax produced by the renderer:
 *   landing-file://<b_file.ID>
 * FileReader::filesForBlock selects b_landing_file.FILE_ID, which is the core
 * b_file row id - that is the value embedded in the URL and consumed downstream
 * by CFile::CopyFile via ImportFileService::copyFromLanding.
 */
final class LandingSource implements SourceInterface
{
	public const ATTACHMENT_SCHEME = 'landing-file://';
	// Placeholder for external `<img src="http...">` images that are not stored as portal files.
	// The URL is base64url-encoded after the scheme so the download step can fetch it while the
	// `?w=..&a=..` positioning suffix stays separable. See {@see externalAttachmentUrl()}.
	public const EXTERNAL_ATTACHMENT_SCHEME = 'landing-ext://';
	public const FOLDER_EXTERNAL_ID_PREFIX = LandingId::FOLDER_EXTERNAL_ID_PREFIX;

	// Root of the folder hierarchy, in the two forms it is addressed by: the id space of folders,
	// where real ids are positive, and the key space of tree nodes, where every key is an anchor
	// and an anchor is never empty.
	private const TREE_ROOT_ID = 0;
	private const TREE_ROOT_KEY = '';

	private const EXTERNAL_IMAGE_MAX_BYTES = 15 * 1024 * 1024;
	private const EXTERNAL_RETRY_AFTER_DEFAULT = 5;
	private const EXTERNAL_RETRY_AFTER_MAX = 60;

	// [label](/knowledge/<siteCode>[/<segment>...]/[#fragment]) - a public landing address of a page,
	// a section or a base, in the desktop or the /mobile/ form. {@see resolvePublicAddressLinks}
	private const PUBLIC_ADDRESS_PATTERN =
		'~\[(?P<label>[^\]\n]+)\]\((?P<url>/(?:mobile/)?knowledge/'
		. '(?P<path>[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*)/?(?:\#[^\s)]+)?)\)~';

	private SiteReader $siteReader;
	private PageReader $pageReader;
	private BlockReader $blockReader;
	private FileReader $fileReader;
	private MenuReader $menuReader;
	private RightsReader $rightsReader;
	private LandingBlockMarkdownRenderer $blockRenderer;
	private MenuConverter $menuConverter;

	/** @var array<string, array{siteId:int, byPath:array<string,string>, byCode:array<string,string>}|null> */
	private array $addressIndexes = [];
	/** @var array<int, array<int, array{ID:int, CODE:?string, TITLE:string, FOLDER_ID:?int, INDEX_ID:?int}>> */
	private array $foldersBySite = [];
	/** @var array<string, int|null> external id => site id, {@see resolveSiteIdByExternalId} */
	private array $siteIdsByExternalId = [];

	/**
	 * @param string|null $collectionId the base being converted, as the state of the conversion names it
	 *   - the boundary a file has to be inside of to be copied over ({@see downloadAttachment}). Every
	 *   other read is asked about a base by name, so only the attachment step needs it, and it is last
	 *   in the list to keep the readers where the callers of this class already pass them.
	 */
	public function __construct(
		private readonly int $userId,
		?SiteReader $siteReader = null,
		?PageReader $pageReader = null,
		?BlockReader $blockReader = null,
		?FileReader $fileReader = null,
		?LandingBlockMarkdownRenderer $blockRenderer = null,
		?MenuReader $menuReader = null,
		?MenuConverter $menuConverter = null,
		?RightsReader $rightsReader = null,
		private readonly ?string $collectionId = null,
	)
	{
		$this->siteReader = $siteReader ?? new SiteReader();
		$this->pageReader = $pageReader ?? new PageReader();
		$this->blockReader = $blockReader ?? new BlockReader();
		$this->fileReader = $fileReader ?? new FileReader();
		$this->blockRenderer = $blockRenderer ?? new BlockConverter($this->fileReader);
		$this->menuReader = $menuReader ?? new MenuReader();
		$this->menuConverter = $menuConverter ?? new MenuConverter($this->pageReader);
		$this->rightsReader = $rightsReader ?? new RightsReader();
	}

	public function checkConnection(): SourceResult
	{
		if (!Loader::includeModule('landing'))
		{
			return new SourceResult(
				false,
				error: Loc::getMessage('NOTE_IMPORT_LANDING_ERROR_MODULE_UNAVAILABLE'),
			);
		}

		$sites = $this->siteReader->listKnowledgeBaseSitesForUser($this->userId);
		if (empty($sites))
		{
			return new SourceResult(
				false,
				error: Loc::getMessage('NOTE_IMPORT_LANDING_ERROR_NO_ACCESSIBLE_SITES'),
			);
		}

		return new SourceResult(true, [
			'instanceName' => $this->resolvePortalName(),
		]);
	}

	public function getCollections(): SourceResult
	{
		$sites = $this->siteReader->listKnowledgeBaseSitesForUser($this->userId);

		$collections = [];
		foreach ($sites as $site)
		{
			// The key of a base is its normalised site CODE, the anchor is its site id (TPL-01):
			// the key has to stay stable for a repeat conversion, the anchor has to name one row.
			$externalId = $this->siteExternalId($site);
			$collections[] = [
				'id' => $externalId,
				'urlId' => LandingId::collectionAnchor((int)$site['ID']),
				'name' => $site['TITLE'] !== '' ? $site['TITLE'] : ('Site #' . $site['ID']),
				'externalId' => $externalId,
				'pageCount' => $site['pageCount'] ?? 0,
			];
		}

		return new SourceResult(true, ['collections' => $collections]);
	}

	public function getDocumentTree(string $collectionId): SourceResult
	{
		$siteId = $this->resolveSiteIdByExternalId($collectionId);
		if ($siteId === null)
		{
			return new SourceResult(false, error: Loc::getMessage('NOTE_IMPORT_LANDING_ERROR_SITE_NOT_FOUND'));
		}

		$site = $this->siteReader->getSiteById($siteId);
		if ($site === null)
		{
			return new SourceResult(false, error: Loc::getMessage('NOTE_IMPORT_LANDING_ERROR_SITE_NOT_FOUND'));
		}

		$siteCode = $this->siteCode($site);
		$folders = $this->foldersForSite($siteId);
		// The home page (LANDING_ID_INDEX) is left out of the listing: it becomes no document of
		// its own, its content is written into the knowledge base description instead (see
		// getDocumentsPage()). It is a SYS='Y' page, so the plain listing already excludes it;
		// buildTree() drops it by id as well, for a base whose home page is a regular one.
		$pages = $this->pageReader->listForSite($siteId);
		// Read once per step: the menu supplies the author's order for every tree level.
		$menu = $this->menuReader->getMenuForSite($siteId);

		$tree = $this->buildTree($siteCode, $folders, $pages, $menu, $this->indexPageId($site));

		return new SourceResult(true, ['documents' => $tree]);
	}

	public function getDocumentsPage(string $collectionId, int $offset, int $limit): SourceResult
	{
		$siteId = $this->resolveSiteIdByExternalId($collectionId);
		if ($siteId === null)
		{
			return new SourceResult(false, error: Loc::getMessage('NOTE_IMPORT_LANDING_ERROR_SITE_NOT_FOUND'));
		}

		$site = $this->siteReader->getSiteById($siteId);
		if ($site === null)
		{
			return new SourceResult(false, error: Loc::getMessage('NOTE_IMPORT_LANDING_ERROR_SITE_NOT_FOUND'));
		}

		$siteCode = $this->siteCode($site);
		$indexPageId = $this->indexPageId($site);
		// The window this batch is answered with: the one the caller asked for, narrowed to what the
		// reader of pages hands out at once. It is named in the answer rather than left for the caller
		// to assume, because the step tells the end of a base by a batch shorter than its window - given
		// its own number instead, a caller whose window is wider than the ceiling would read the very
		// first batch as the end of the base and leave the rest of it unconverted.
		$windowLimit = min($limit, PageReader::MAX_PAGE_LIMIT);

		// Section documents come first (offset 0), then pages ordered by ID asc.
		// The SYS='Y' home page (LANDING_ID_INDEX) is opted into the page listing so its content -
		// and the in-body cross-page links it carries - gets imported too, while the index page of a
		// section is left out of it: it is the body of that section.
		$folders = $this->foldersForSite($siteId);
		$folderCodes = $this->folderCodesById($folders);
		$sectionBodyPageIds = array_keys($this->sectionIdsByIndexPage($folders, $indexPageId));
		$folderCount = count($folders);

		$documents = [];
		$pageOffset = $offset;
		// How far the window reached down the list of the base, which is not the number of documents
		// it produced: a page whose row cannot be read produces none and still occupies the window
		// ({@see pageDocuments}). The step pages by this number and stops on it, so counting documents
		// instead would rewind the window onto pages already converted and call the base finished
		// early - the rest of it would never be read.
		$consumed = 0;
		// What is left of the window after the sections of this batch.
		$remaining = $windowLimit;

		if ($pageOffset < $folderCount)
		{
			$folderSlice = array_slice($folders, $pageOffset, $remaining);
			array_push($documents, ...$this->folderDocuments($siteId, $siteCode, $folderSlice, $indexPageId));
			$consumed = count($folderSlice);
			$pageOffset = 0;
			$remaining -= $consumed;
		}
		else
		{
			$pageOffset -= $folderCount;
		}

		if ($remaining > 0)
		{
			$pageIds = $this->pageReader->listPageIdsForSite(
				$siteId,
				$pageOffset,
				$remaining,
				$indexPageId,
				$sectionBodyPageIds,
			);
			$consumed += count($pageIds);
			// Only the home page needs the menu - it becomes the table of contents of the base
			// description - so it is read for the batch that carries it and for no other.
			$menu = ($indexPageId !== null && in_array($indexPageId, $pageIds, true))
				? $this->menuReader->getMenuForSite($siteId)
				: []
			;

			array_push(
				$documents,
				...$this->pageDocuments($siteId, $siteCode, $pageIds, $folderCodes, $menu, $indexPageId),
			);
		}

		// The batch carries no count of the base: the step pages by `consumed` and tells the end of a
		// base by a batch shorter than `windowLimit`, so counting the base would cost a query on
		// every step for a number nobody reads.
		return new SourceResult(true, [
			'documents' => $documents,
			'consumed' => $consumed,
			'windowLimit' => $windowLimit,
		]);
	}

	public function downloadAttachment(string $attachmentId): SourceResult
	{
		// External images carry the `landing-ext://<base64url>` scheme; everything else is a
		// numeric `landing-file://` core file id resolved locally (no HTTP).
		if (str_starts_with($attachmentId, self::EXTERNAL_ATTACHMENT_SCHEME))
		{
			return $this->downloadExternalImage(
				substr($attachmentId, strlen(self::EXTERNAL_ATTACHMENT_SCHEME)),
			);
		}

		// ImageConverter / VideoConverter emit `landing-file://<b_file.ID>`: the URL
		// already carries the core file id (FileReader::filesForBlock returns FILE_ID,
		// not b_landing_file.ID). Skip the b_landing_file lookup and validate that the
		// core file row still exists - CFile::CopyFile downstream needs exactly this id.
		$fileId = (int)$attachmentId;
		if ($fileId <= 0)
		{
			return new SourceResult(false, error: 'Invalid landing file id');
		}

		$file = \CFile::GetFileArray($fileId);
		if (!is_array($file) || (int)($file['ID'] ?? 0) <= 0)
		{
			return new SourceResult(false, error: 'Landing file not found');
		}

		if (!$this->fileBelongsToConvertedBase($fileId))
		{
			return new SourceResult(false, error: 'Landing file does not belong to the converted base');
		}

		return new SourceResult(true, [
			'fileId' => $fileId,
			'inLanding' => true,
		]);
	}

	/**
	 * Whether the file is a file of the base being converted - the boundary of the whole attachment
	 * step, and the reason a number in a marker is not enough to copy a file by.
	 *
	 * `b_file` holds the files of the whole portal: avatars, documents of Disk, files of another base.
	 * The number in the marker used to be checked for one thing only - that such a row is still there -
	 * so a page authored with `landing-file://<any id>` had a file of the portal copied into a document
	 * of note, where the base it named nothing about. The markers a conversion writes itself are
	 * neutralised in the authored text ({@see neutralizeAuthoredMarkers}), and this is the answer that
	 * does not depend on where a marker came from.
	 *
	 * A file of the base is a file attached to a block of one of its pages, to a page of it, or to the
	 * base itself - the three kinds of link landing keeps in b_landing_file, all three inside the same
	 * base. The state of the block or the page is not asked about: a file of a block long deleted is
	 * still a file of this base and nothing of anybody else's.
	 *
	 * Without the base in hand - a caller that named no base - nothing is copied: the question cannot be
	 * answered, and an unanswered question about a boundary is not an answer of yes.
	 */
	private function fileBelongsToConvertedBase(int $fileId): bool
	{
		$siteId = $this->collectionId !== null
			? $this->resolveSiteIdByExternalId($this->collectionId)
			: null
		;
		if ($siteId === null)
		{
			ImportLogger::logError(
				"landing import: file {$fileId} is not copied - the conversion named no base to check it "
				. 'against'
			);

			return false;
		}

		$links = $this->fileReader->entityLinksForFile($fileId);
		if (in_array($siteId, $links['S'], true))
		{
			return true;
		}

		$pageIds = $links['L'];
		if ($links['B'] !== [])
		{
			array_push($pageIds, ...$this->blockReader->pageIdsForBlocks($links['B']));
		}

		if ($pageIds === [])
		{
			return false;
		}

		foreach ($this->pageReader->getByIds($pageIds) as $page)
		{
			if ($page['SITE_ID'] === $siteId)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Wraps an external image URL in the `landing-ext://<base64url>` placeholder so the
	 * download step fetches it (and the URL survives the `?w=&a=` positioning suffix intact).
	 * Returns null for anything that is not an http(s) URL (data:, relative, protocol-relative),
	 * which the caller then leaves untouched.
	 */
	public static function externalAttachmentUrl(string $src): ?string
	{
		if (preg_match('~^https?://~i', $src) !== 1)
		{
			return null;
		}

		return self::EXTERNAL_ATTACHMENT_SCHEME . rtrim(strtr(base64_encode($src), '+/', '-_'), '=');
	}

	/**
	 * Reverse of {@see externalAttachmentUrl()}: the address the placeholder was built from, or null
	 * when the token does not carry one. Public because the last step of the attachment chain unwraps
	 * every placeholder it could not download back into a working address - see
	 * LandingMdTransformer::unwrapUnresolvedAttachments().
	 */
	public static function decodeExternalAttachmentUrl(string $token): ?string
	{
		$decoded = base64_decode(strtr($token, '-_', '+/'), true);
		if ($decoded === false || preg_match('~^https?://~i', $decoded) !== 1)
		{
			return null;
		}

		return $decoded;
	}

	/**
	 * Fetches an external image over HTTP into a Bitrix tmp file, mirroring OutlineSource's
	 * download contract: on success returns tmpPath/fileName/contentType/size for
	 * ImportFileService::persistAttachment (the DownloadAttachmentsStep "!inLanding" branch).
	 * Only image/* responses within the size cap are accepted; anything else fails so the
	 * original markdown link is preserved rather than turned into a broken attachment.
	 */
	private function downloadExternalImage(string $token): SourceResult
	{
		$url = self::decodeExternalAttachmentUrl($token);
		if ($url === null)
		{
			return new SourceResult(false, error: 'Invalid external image url');
		}

		$tmpPath = \CFile::GetTempName('', 'note_ext_' . bin2hex(random_bytes(8)) . '.bin');
		$tmpFile = new File($tmpPath);
		$client = $this->createExternalHttpClient();

		try
		{
			$client->download($url, $tmpPath);
			$status = $client->getStatus();

			if ($status === 429)
			{
				$this->deleteTmp($tmpFile);

				return new SourceResult(false, ['retryAfter' => $this->parseRetryAfter($client)]);
			}

			if ($status !== 200 || !$tmpFile->isExists())
			{
				$this->deleteTmp($tmpFile);

				return new SourceResult(false, error: 'External image download failed');
			}

			$size = $tmpFile->getSize();
			if ($size <= 0 || $size > self::EXTERNAL_IMAGE_MAX_BYTES)
			{
				$this->deleteTmp($tmpFile);

				return new SourceResult(false, error: 'External image size out of range');
			}

			$rawContentType = $client->getHeaders()->get('Content-Type') ?? '';
			$contentType = trim(explode(';', (string)$rawContentType)[0]);
			$normalizedType = mb_strtolower($contentType);
			// SVG is an image/* type but can carry inline <script>; persisting one fetched from an
			// arbitrary host would be a stored-XSS vector, so reject it outright. Anything that is
			// not image/* (HTML error page, tracking pixel served as text, ...) is dropped too, so
			// the original markdown link is kept instead of persisting junk.
			if (!str_starts_with($normalizedType, 'image/') || $normalizedType === 'image/svg+xml')
			{
				$this->deleteTmp($tmpFile);

				return new SourceResult(false, error: 'External resource is not a supported image');
			}

			return new SourceResult(true, [
				'tmpPath' => $tmpPath,
				'fileName' => $this->externalImageFileName($url, $contentType),
				'contentType' => $contentType,
				'size' => $size,
			]);
		}
		catch (\Throwable $e)
		{
			$this->deleteTmp($tmpFile);
			ImportLogger::logError('downloadExternalImage failed for ' . self::sanitizeUrlForLog($url) . ': ' . $e->getMessage());

			return new SourceResult(false, error: 'External image download error');
		}
	}

	private function createExternalHttpClient(): HttpClient
	{
		$client = new HttpClient([
			'socketTimeout' => 15,
			'streamTimeout' => 60,
			'redirect' => true,
			'redirectMax' => 5,
			// Hard cap on bytes streamed to disk: both handlers abort mid-download once this is
			// exceeded (Curl\Handler write callback / Socket\Handler throws), so a hostile host
			// cannot fill the tmp partition before the post-download size check runs.
			'bodyLengthMax' => self::EXTERNAL_IMAGE_MAX_BYTES,
		]);

		if (Settings::isDevMode())
		{
			// Dev/local install: relax TLS + SSRF guard so self-signed / private-IP hosts work.
			$client->disableSslVerification();
		}
		else
		{
			// SSRF guard: block private/reserved IPs (re-checked on each redirect).
			$client->setPrivateIp(false);
		}

		return $client;
	}

	private function externalImageFileName(string $url, string $contentType): string
	{
		$path = (new Uri($url))->getPath();
		$name = $path !== '' ? basename($path) : '';
		if ($name === '' || !str_contains($name, '.'))
		{
			// image/svg+xml is intentionally absent - SVG is rejected before this point.
			$ext = match (mb_strtolower($contentType)) {
				'image/jpeg' => 'jpg',
				'image/png' => 'png',
				'image/gif' => 'gif',
				'image/webp' => 'webp',
				'image/bmp' => 'bmp',
				default => 'img',
			};
			$name = 'image-' . substr(md5($url), 0, 8) . '.' . $ext;
		}

		return $name;
	}

	/**
	 * Reads a sane retry delay (seconds) from a 429 `Retry-After` header. A non-numeric value
	 * (HTTP-date form) or a missing header falls back to the default; the result is clamped so a
	 * hostile or misconfigured server cannot stall the import with an arbitrarily large delay.
	 */
	private function parseRetryAfter(HttpClient $client): int
	{
		$raw = trim((string)($client->getHeaders()->get('Retry-After') ?? ''));
		$seconds = self::isDigits($raw) ? (int)$raw : self::EXTERNAL_RETRY_AFTER_DEFAULT;

		return max(1, min($seconds, self::EXTERNAL_RETRY_AFTER_MAX));
	}

	/**
	 * Host + path only - keeps query strings (which may carry signed access tokens) out of logs.
	 */
	private static function sanitizeUrlForLog(string $url): string
	{
		$uri = new Uri($url);
		$host = $uri->getHost();

		return $host !== '' ? $host . $uri->getPath() : '[relative url]';
	}

	private function deleteTmp(File $tmpFile): void
	{
		if ($tmpFile->isExists())
		{
			$tmpFile->delete();
		}
	}

	public function getCollectionAccess(string $collectionId): SourceResult
	{
		$siteId = $this->resolveSiteIdByExternalId($collectionId);
		if ($siteId === null)
		{
			return new SourceResult(false, error: Loc::getMessage('NOTE_IMPORT_LANDING_ERROR_SITE_NOT_FOUND'));
		}

		$accessByCode = $this->rightsReader->getSiteAccessTypes($siteId);
		$access = $accessByCode === []
			? ['permissions' => [], 'policyLevel' => null]
			: $this->collectionAccessFromRights($accessByCode)
		;

		// [P6.T5] A base whose rights carry no level of a collection - because its site has no rights
		// of its own, or holds none that name one (DTO-01, last row) - says so in a word of its own.
		// The empty list alone is the same shape a site that was never read would produce, and the
		// consumer would then have to guess which of the two it is holding.
		if ($access['permissions'] === [] && $access['policyLevel'] === null)
		{
			$access['nothingToTransfer'] = true;
		}

		return new SourceResult(true, $access);
	}

	/**
	 * The access map of a base out of the landing rights of its site: a level per named subject and
	 * one policy level for the global subjects, which carry no row of the list of their own. Which
	 * right means which level is {@see AccessLevelMapper} alone (DTO-01) - this only sorts the
	 * answers into the two fields of the map.
	 *
	 * @param array<string, string[]> $accessByCode access code => landing rights, {@see RightsReader}
	 * @return array{permissions: array<int, array{subjectCode:string, level:string}>, policyLevel: ?string}
	 */
	private function collectionAccessFromRights(array $accessByCode): array
	{
		$permissions = [];
		$policyLevel = null;

		foreach ($accessByCode as $subjectCode => $rights)
		{
			$level = AccessLevelMapper::levelFor($subjectCode, $rights);
			if ($level === null)
			{
				continue;
			}

			if (AccessLevelMapper::isGlobalSubject($subjectCode))
			{
				$policyLevel = AccessLevelMapper::maxLevel($policyLevel, $level);

				continue;
			}

			$permissions[$subjectCode] = AccessLevelMapper::maxLevel($permissions[$subjectCode] ?? null, $level);
		}

		ksort($permissions);

		return [
			'permissions' => array_map(
				static fn(string $subjectCode, string $level): array => [
					'subjectCode' => $subjectCode,
					'level' => $level,
				],
				array_keys($permissions),
				$permissions,
			),
			'policyLevel' => $policyLevel,
		];
	}

	/**
	 * The answer is remembered for the life of the source, misses included: the base of a step does
	 * not change under it, while the question is put once per attachment ({@see downloadAttachment}),
	 * and a base named by its CODE costs a query every time it is asked.
	 */
	private function resolveSiteIdByExternalId(string $externalId): ?int
	{
		// externalId is either the site CODE or its ID-as-string fallback.
		// Try numeric form first to avoid an extra query when site CODE is unset.
		if (self::isDigits($externalId))
		{
			return (int)$externalId;
		}

		if (array_key_exists($externalId, $this->siteIdsByExternalId))
		{
			return $this->siteIdsByExternalId[$externalId];
		}

		return $this->siteIdsByExternalId[$externalId] = $this->siteReader->findSiteIdByCode($externalId);
	}

	/**
	 * `ctype_digit()` equivalent: PHP on the cloud infrastructure is built without the `ctype`
	 * extension, so any call to it is a fatal error there. The `D` modifier keeps the semantics
	 * exactly - a trailing newline does not pass - and an empty string stays false.
	 */
	private static function isDigits(string $value): bool
	{
		return preg_match('/^[0-9]+$/D', $value) === 1;
	}

	private function siteExternalId(array $site): string
	{
		return $this->siteCode($site);
	}

	/**
	 * Knowledge-base sites store CODE wrapped in slashes (`/american-football/`),
	 * but public URLs and in-content links use the bare form (`american-football`).
	 * We normalise to the bare form so the external id (`<siteCode>:<pageCode>`) and
	 * the URLs produced by MenuConverter / resolveBodyInternalLinks line up with what
	 * {@see \Bitrix\Note\Internal\Service\Import\Transformer\LandingMentionTransformer}
	 * parses out of `/knowledge/<siteCode>/...` links. {@see SiteReader::findSiteIdByCode}
	 * matches the slash-wrapped DB value back from this bare form.
	 */
	private function siteCode(array $site): string
	{
		$code = $site['CODE'] !== null ? LandingId::normalizeSiteCode((string)$site['CODE']) : '';

		return $code !== '' ? $code : (string)$site['ID'];
	}

	/**
	 * Home page of the base (b_landing_site.LANDING_ID_INDEX), or null for a base that has none.
	 */
	private function indexPageId(array $site): ?int
	{
		$indexPageId = (int)($site['LANDING_ID_INDEX'] ?? 0);

		return $indexPageId > 0 ? $indexPageId : null;
	}

	/**
	 * @param array<int, string> $folderCodes folder id => folder code, {@see folderCodesById}
	 */
	private function pageExternalId(string $siteCode, array $page, array $folderCodes): string
	{
		$folderId = (int)($page['FOLDER_ID'] ?? 0);

		return LandingId::pageExternalId($siteCode, $folderCodes[$folderId] ?? '', $this->rowCode($page));
	}

	private function folderExternalId(string $siteCode, array $folder): string
	{
		return LandingId::folderExternalId($siteCode, $this->rowCode($folder));
	}

	/**
	 * Sections of a site, read once per step: the tree, the content batch and the link index
	 * all need the same rows, and a step builds a new source of its own anyway.
	 *
	 * @return array<int, array{ID:int, CODE:?string, TITLE:string, FOLDER_ID:?int, INDEX_ID:?int}>
	 */
	private function foldersForSite(int $siteId): array
	{
		return $this->foldersBySite[$siteId] ??= $this->pageReader->listFoldersForSite($siteId);
	}

	/**
	 * @param array<int, array{ID:int, CODE:?string}> $folders
	 * @return array<int, string> folder id => folder code
	 */
	private function folderCodesById(array $folders): array
	{
		$codes = [];
		foreach ($folders as $folder)
		{
			$codes[(int)$folder['ID']] = $this->rowCode($folder);
		}

		return $codes;
	}

	/**
	 * Code of a b_landing row as it appears in keys and public URLs, with the row id as
	 * fallback for a code-less row. Folders and pages are both b_landing rows, so one
	 * resolution serves both.
	 */
	private function rowCode(array $row): string
	{
		$code = (string)($row['CODE'] ?? '');

		return $code !== '' ? $code : (string)$row['ID'];
	}

	/**
	 * Title for a row the author left unnamed. A title is written into the document once, at
	 * import time, and nothing rewrites it afterwards - so where the phrase is not translated
	 * yet, the last resort is the row's own code ({@see rowCode}, the slug of its public
	 * address), not a literal of ours in a language the reader may not speak.
	 */
	private function untitledRowTitle(array $row, string $messageCode): string
	{
		$title = (string)Loc::getMessage($messageCode);

		return $title !== '' ? $title : $this->rowCode($row);
	}

	/**
	 * Builds the document tree (ALG-01): every folder is a node of the same shape as a page
	 * node, pages hang off their FOLDER_ID and everything without a folder lands in the root.
	 * Folder nodes are built before pages are distributed, because a page can only find its
	 * parent once the folder nodes exist. Matches CreateStructureStep's expected shape.
	 *
	 * Nodes are assembled by key, not by reference, so no `null` node can enter the tree. The key
	 * is the anchor of the element: sections are numbered independently of pages (their ids come
	 * from landing's own folder table), so a bare id would let one overwrite the other, while the
	 * anchor already separates the two spaces.
	 *
	 * The home page ($indexPageId) is not part of the tree: its content becomes the description
	 * of the knowledge base, so a document of its own would duplicate it. The index page of a
	 * section is out for the same reason - it is the body of that section - and the position the
	 * menu gives it belongs to the section node, because a menu item can only name a page.
	 *
	 * @param array<int, array{ID:int, CODE:?string, TITLE:string, FOLDER_ID:?int, INDEX_ID:?int}> $folders
	 * @param array<int, array{ID:int, CODE:?string, TITLE:string, FOLDER_ID:?int}> $pages
	 * @param array<int, array{title:string, pageId:int, href:string, depth:int}> $menu
	 * @return array<int, array{id:string, urlId:string, title:string, children:array}>
	 */
	private function buildTree(
		string $siteCode,
		array $folders,
		array $pages,
		array $menu,
		?int $indexPageId = null,
	): array
	{
		$folderCodes = $this->folderCodesById($folders);
		$sectionIdsByIndexPage = $this->sectionIdsByIndexPage($folders, $indexPageId);
		$nodes = [];
		$childKeys = [];

		foreach ($folders as $folder)
		{
			$sectionAnchor = LandingId::sectionAnchor((int)$folder['ID']);
			$nodes[$sectionAnchor] = [
				'id' => $this->folderExternalId($siteCode, $folder),
				'urlId' => $sectionAnchor,
				'title' => $folder['TITLE'] !== ''
					? $folder['TITLE']
					: $this->untitledRowTitle($folder, 'NOTE_IMPORT_LANDING_UNTITLED_FOLDER'),
				'children' => [],
			];
		}

		foreach ($this->resolveFolderParents($folders, $folderCodes) as $folderId => $parentId)
		{
			$childKeys[$this->sectionNodeKey($parentId)][] = LandingId::sectionAnchor($folderId);
		}

		foreach ($pages as $page)
		{
			$pageId = (int)$page['ID'];
			if ($pageId === $indexPageId || isset($sectionIdsByIndexPage[$pageId]))
			{
				continue;
			}

			$pageAnchor = LandingId::pageAnchor($pageId);
			$nodes[$pageAnchor] = [
				'id' => $this->pageExternalId($siteCode, $page, $folderCodes),
				'urlId' => $pageAnchor,
				'title' => $page['TITLE'] !== ''
					? $page['TITLE']
					: $this->untitledRowTitle($page, 'NOTE_IMPORT_LANDING_UNTITLED_PAGE'),
				'children' => [],
			];

			$folderId = (int)($page['FOLDER_ID'] ?? 0);
			$childKeys[isset($folderCodes[$folderId]) ? $this->sectionNodeKey($folderId) : self::TREE_ROOT_KEY][]
				= $pageAnchor
			;
		}

		return $this->assembleTree(
			self::TREE_ROOT_KEY,
			$childKeys,
			$nodes,
			$this->menuPositions($menu, $sectionIdsByIndexPage),
		);
	}

	/**
	 * The page whose content becomes the body of a section document, or null for a section that has
	 * none: landing keeps it in `INDEX_ID` and shows it at the address of the folder.
	 *
	 * The home page of the base is never taken, even when a section names it: it is already the
	 * description of the base, and one page cannot become two things at once.
	 *
	 * @param array{ID:int, INDEX_ID:?int} $folder
	 * @param int|null $homePageId home page of the base, {@see indexPageId}
	 */
	private function sectionBodyPageId(array $folder, ?int $homePageId): ?int
	{
		$pageId = (int)($folder['INDEX_ID'] ?? 0);

		return ($pageId > 0 && $pageId !== $homePageId) ? $pageId : null;
	}

	/**
	 * Which section every index page is the body of. Whatever pointed at such a page - an address,
	 * an in-content link, a menu item - has to point at that section instead, and this is the one
	 * map all of them consult.
	 *
	 * @param array<int, array{ID:int, INDEX_ID:?int}> $folders
	 * @param int|null $homePageId home page of the base, {@see indexPageId}
	 * @return array<int, int> index page id => section id
	 */
	private function sectionIdsByIndexPage(array $folders, ?int $homePageId): array
	{
		$sectionIds = [];
		foreach ($folders as $folder)
		{
			$pageId = $this->sectionBodyPageId($folder, $homePageId);
			if ($pageId !== null)
			{
				$sectionIds[$pageId] ??= (int)$folder['ID'];
			}
		}

		return $sectionIds;
	}

	/**
	 * Tree key of a section, and the root key for the root ({@see TREE_ROOT_ID}).
	 */
	private function sectionNodeKey(int $folderId): string
	{
		return $folderId !== self::TREE_ROOT_ID ? LandingId::sectionAnchor($folderId) : self::TREE_ROOT_KEY;
	}

	/**
	 * Parent folder of every folder, with the root as `TREE_ROOT_ID`. A folder pointing at
	 * itself, at a folder outside this site, or at a parent whose own chain leads back to it
	 * is lifted to the root: otherwise a loop in b_landing.FOLDER_ID would either recurse
	 * forever during assembly or leave the whole loop out of the tree.
	 *
	 * @param array<int, array{ID:int, FOLDER_ID:?int}> $folders
	 * @param array<int, string> $folderCodes
	 * @return array<int, int> folder id => parent folder id
	 */
	private function resolveFolderParents(array $folders, array $folderCodes): array
	{
		$parents = [];
		foreach ($folders as $folder)
		{
			$folderId = (int)$folder['ID'];
			$parentId = (int)($folder['FOLDER_ID'] ?? 0);
			$parents[$folderId] = ($parentId !== $folderId && isset($folderCodes[$parentId]))
				? $parentId
				: self::TREE_ROOT_ID;
		}

		foreach (array_keys($parents) as $folderId)
		{
			if ($this->hasParentLoop($folderId, $parents))
			{
				$parents[$folderId] = self::TREE_ROOT_ID;
			}
		}

		return $parents;
	}

	/**
	 * @param array<int, int> $parents folder id => parent folder id
	 */
	private function hasParentLoop(int $folderId, array $parents): bool
	{
		$seen = [$folderId => true];
		$parentId = $parents[$folderId] ?? self::TREE_ROOT_ID;

		while ($parentId !== self::TREE_ROOT_ID)
		{
			if (isset($seen[$parentId]))
			{
				return true;
			}

			$seen[$parentId] = true;
			$parentId = $parents[$parentId] ?? self::TREE_ROOT_ID;
		}

		return false;
	}

	/**
	 * @param array<string, array<int, string>> $childKeys parent node key => child node keys
	 * @param array<string, array{id:string, urlId:string, title:string, children:array}> $nodes
	 * @param array<string, int> $menuPositions
	 * @return array<int, array{id:string, urlId:string, title:string, children:array}>
	 */
	private function assembleTree(string $parentKey, array $childKeys, array $nodes, array $menuPositions): array
	{
		$level = [];
		foreach ($this->orderLevel($childKeys[$parentKey] ?? [], $menuPositions) as $nodeKey)
		{
			$node = $nodes[$nodeKey];
			$node['children'] = $this->assembleTree($nodeKey, $childKeys, $nodes, $menuPositions);
			$level[] = $node;
		}

		return $level;
	}

	/**
	 * Position of every element the site menu lists, in the author's order.
	 *
	 * A menu item can only bind to a page (`#landing<id>`), so a section is represented in the menu
	 * by its index page - and the position of that item is the position of the SECTION node, or the
	 * sections of a level would all fall behind the pages, in an order the author never chose.
	 *
	 * @param array<int, array{title:string, pageId:int, href:string, depth:int}> $menu
	 * @param array<int, int> $sectionIdsByIndexPage {@see sectionIdsByIndexPage}
	 * @return array<string, int> node key => position
	 */
	private function menuPositions(array $menu, array $sectionIdsByIndexPage): array
	{
		$positions = [];
		foreach ($menu as $position => $item)
		{
			$pageId = (int)($item['pageId'] ?? 0);
			$nodeKey = isset($sectionIdsByIndexPage[$pageId])
				? LandingId::sectionAnchor($sectionIdsByIndexPage[$pageId])
				: LandingId::pageAnchor($pageId)
			;
			if ($nodeKey !== '' && !isset($positions[$nodeKey]))
			{
				$positions[$nodeKey] = $position;
			}
		}

		return $positions;
	}

	/**
	 * Orders one tree level by the menu: listed nodes first in the author's order, the rest
	 * after them in reading order (folders by id, then pages by id), so a base without a
	 * menu keeps a stable order between runs.
	 *
	 * @param array<int, string> $nodeKeys
	 * @param array<string, int> $menuPositions
	 * @return array<int, string>
	 */
	private function orderLevel(array $nodeKeys, array $menuPositions): array
	{
		$listed = [];
		$rest = [];
		foreach ($nodeKeys as $nodeKey)
		{
			if (isset($menuPositions[$nodeKey]))
			{
				$listed[] = $nodeKey;
			}
			else
			{
				$rest[] = $nodeKey;
			}
		}

		usort($listed, static fn(string $a, string $b): int => $menuPositions[$a] <=> $menuPositions[$b]);

		return array_merge($listed, $rest);
	}

	/**
	 * Content documents of one section batch. A section whose folder has an index page in landing
	 * carries the content of that page as its body: that is the page landing shows at the address of
	 * the folder, and it becomes no document of its own ([P3.T19]). A section without one keeps the
	 * placeholder text - nothing in landing holds content for it.
	 *
	 * @param array<int, array{ID:int, CODE:?string, TITLE:string, INDEX_ID:?int}> $folders
	 * @param int|null $indexPageId home page of the base, {@see indexPageId}
	 * @return array<int, array{id:string, urlId:string, title:string, text:string}>
	 */
	private function folderDocuments(int $siteId, string $siteCode, array $folders, ?int $indexPageId): array
	{
		$bodyPageIds = [];
		foreach ($folders as $folder)
		{
			$bodyPageIds[(int)$folder['ID']] = $this->sectionBodyPageId($folder, $indexPageId);
		}

		$blocksByPage = $this->readBlocksByPage(array_values(array_filter($bodyPageIds)));

		$documents = [];
		foreach ($folders as $folder)
		{
			$bodyPageId = $bodyPageIds[(int)$folder['ID']];
			$documents[] = [
				'id' => $this->folderExternalId($siteCode, $folder),
				'urlId' => LandingId::sectionAnchor((int)$folder['ID']),
				'title' => $folder['TITLE'] !== ''
					? $folder['TITLE']
					: $this->untitledRowTitle($folder, 'NOTE_IMPORT_LANDING_UNTITLED_FOLDER'),
				'text' => $bodyPageId !== null
					? $this->resolvePublicAddressLinks(
						$this->renderPageBody($blocksByPage[$bodyPageId] ?? [], $siteId, $siteCode, $indexPageId),
						$siteId,
						$siteCode,
						$indexPageId,
					)
					: $this->renderFolderText(),
			];
		}

		return $documents;
	}

	/**
	 * Content documents of one page batch. The home page ($indexPageId) is marked as the
	 * description of the base and carries the menu table of contents on top of its content; every
	 * other page becomes an ordinary document.
	 *
	 * A page whose row is gone by the time it is read - deleted between the listing and this pass -
	 * yields no document: there is nothing left to give it a title, a code or a place. It is said out
	 * loud rather than dropped in silence, and the caller counts it as read all the same, or the
	 * conversion would take the short batch for the end of the base ({@see getDocumentsPage}).
	 *
	 * @param int[] $pageIds
	 * @param array<int, string> $folderCodes folder id => folder code, {@see folderCodesById}
	 * @param array<int, array{title:string, pageId:int, href:string, depth:int}> $menu
	 * @return array<int, array{id:string, urlId:string, title:string, text:string}>
	 */
	private function pageDocuments(
		int $siteId,
		string $siteCode,
		array $pageIds,
		array $folderCodes,
		array $menu,
		?int $indexPageId,
	): array
	{
		$blocksByPage = $this->readBlocksByPage($pageIds);
		$pages = $this->pageReader->getByIds($pageIds);

		$documents = [];
		foreach ($pageIds as $pageId)
		{
			$page = $pages[$pageId] ?? null;
			if ($page === null)
			{
				ImportLogger::logError(
					"landing import: page {$pageId} of site {$siteId} is listed but its row cannot be "
					. 'read - it is converted into nothing'
				);

				continue;
			}

			$body = $this->renderPageBody($blocksByPage[$pageId] ?? [], $siteId, $siteCode, $indexPageId);
			$document = [
				'id' => $this->pageExternalId($siteCode, $page, $folderCodes),
				'urlId' => LandingId::pageAnchor($pageId),
				'title' => $page['TITLE'] !== ''
					? $page['TITLE']
					: $this->untitledRowTitle($page, 'NOTE_IMPORT_LANDING_UNTITLED_PAGE'),
				'text' => $body,
			];

			if ($pageId === $indexPageId)
			{
				// The home page carries the description of the whole base rather than a document of
				// its own: the content step writes it into the collection's main document and maps
				// the page onto it, so the address it used to live at keeps working.
				$document['isCollectionDescription'] = true;
				$document['text'] = $this->prependMenu($body, $menu, $siteCode);
			}

			// Last pass over the finished text: the table of contents and any address an author
			// typed by hand are still public landing addresses at this point.
			$document['text'] = $this->resolvePublicAddressLinks(
				$document['text'],
				$siteId,
				$siteCode,
				$indexPageId,
			);

			$documents[] = $document;
		}

		return $documents;
	}

	/**
	 * Markdown of the blocks of one landing page with its in-body binding links resolved - the two
	 * passes every body goes through, whether the page becomes a document of its own or the body of
	 * a section ({@see folderDocuments}). The public-address pass is left to the caller: the
	 * description of the base gets its table of contents in between.
	 *
	 * @param array $blocks rows from BlockReader::listForPages of one page
	 * @param int|null $homePageId home page of the base, {@see indexPageId}
	 */
	private function renderPageBody(array $blocks, int $siteId, string $siteCode, ?int $homePageId): string
	{
		$rendered = LandingMentionTransformer::neutralizeLinkMarkers(
			$this->blockRenderer->renderPage(self::neutralizeAuthoredMarkers($blocks)),
		);

		return $this->resolveBodyInternalLinks($rendered, $siteId, $siteCode, $homePageId);
	}

	/**
	 * The blocks of a page with every marker they were authored with rendered inert, before a converter
	 * reads a single one of them: what a marker names - an element of the base, a file of the portal -
	 * may be named by the conversion and not by the page it converts.
	 *
	 * Every string of the row goes through it, not the content alone. A block keeps authored values in
	 * fields of its own as well (the address of a video in SOURCE_PARAMS is one), converters read those
	 * fields straight into the markdown they emit, and a marker is a marker wherever it was written.
	 * Nothing of landing is lost to the pass: none of the schemes is landing's, they are all note's own.
	 *
	 * @param array $blocks rows from BlockReader::listForPages
	 */
	private static function neutralizeAuthoredMarkers(array $blocks): array
	{
		foreach ($blocks as &$block)
		{
			$block = self::neutralizeAuthoredValue($block);
		}
		unset($block);

		return $blocks;
	}

	private static function neutralizeAuthoredValue(mixed $value): mixed
	{
		if (is_string($value))
		{
			return LandingMentionTransformer::neutralizeSourceMarkers($value);
		}

		if (is_array($value))
		{
			return array_map(static fn(mixed $item): mixed => self::neutralizeAuthoredValue($item), $value);
		}

		return $value;
	}

	/**
	 * Blocks of a batch of pages, grouped by page, with the file links of every block of the batch
	 * read in one go. A converter asks about the files of the one block it converts
	 * ({@see FileReader::filesForBlock}), so a batch of a hundred pages means about a thousand reads
	 * of b_landing_file unless they are answered ahead of the conversion.
	 *
	 * @param int[] $pageIds
	 * @return array<int, array> pageId -> blocks
	 */
	private function readBlocksByPage(array $pageIds): array
	{
		$blocks = $this->blockReader->listForPages($pageIds);
		$this->fileReader->preloadForBlocks(array_column($blocks, 'ID'));

		return $this->groupBlocksByPage($blocks);
	}

	/**
	 * @param array $blocks rows from BlockReader::listForPages (already ordered)
	 * @return array<int, array> pageId -> blocks
	 */
	private function groupBlocksByPage(array $blocks): array
	{
		$grouped = [];
		foreach ($blocks as $block)
		{
			$grouped[$block['LID']][] = $block;
		}

		return $grouped;
	}

	/**
	 * Body of a section document whose folder has no index page. Unlike a title, it may stay
	 * empty: a section with no body of its own reads fine as a bare heading of its children.
	 */
	private function renderFolderText(): string
	{
		return (string)Loc::getMessage('NOTE_IMPORT_LANDING_FOLDER_PLACEHOLDER');
	}

	/**
	 * Puts the site menu, rendered as a markdown table of contents, in front of the home page
	 * content - that is, at the top of the knowledge base description. The menu is the author's
	 * navigation of the base, so the home page is where a reader looks for it; the first document
	 * of the base, where the table of contents used to end up, is an arbitrary section or page.
	 *
	 * Its links stay public landing addresses and are rewritten by the same link pass as the rest
	 * of the content on the fill step.
	 *
	 * @param array<int, array{title:string, pageId:int, href:string, depth:int}> $menu
	 */
	private function prependMenu(string $markdown, array $menu, string $siteCode): string
	{
		// Titles and addresses of the items are authored, and the menu carries no marker of its own at
		// this point - its links are still public landing addresses - so all the schemes are neutralised.
		$menuMd = LandingMentionTransformer::neutralizeSourceMarkers(
			$this->menuConverter->convert($menu, $siteCode),
		);

		return $menuMd !== '' ? $menuMd . "\n\n" . $markdown : $markdown;
	}

	/**
	 * Rewrites landing in-body links whose href is a landing binding scheme into a real portal URL:
	 *   - `#landing<id>` / `page:#landing<id>`      -> link marker carrying the page id, with the
	 *     public /knowledge/<siteCode>/<pageCode>/ address as its fallback
	 *   - `user:#user<id>` (button "открыть профиль") -> the mention token `@{user:<id>}` (TPL-02)
	 *   - `form:#crmForm{Inline|Popup}<id>` (CRM-form action / inline embed) -> the form's public URL
	 *   - `file:#diskFile<id>` / `#diskFile<id>` (a file of Disk attached to the page) -> the Disk
	 *     download URL of that file, which Disk gates by the rights of whoever follows it
	 *   - `block:#block<id>` (jump to a block of a landing page) -> plain text: a note document is
	 *     one whole, its blocks carry no addressable ids, so this scheme has no receiver at all
	 *
	 * HtmlToMarkdown emits these hrefs verbatim, so without this pass they would never reach
	 * {@see \Bitrix\Note\Internal\Service\Import\Transformer\LandingMentionTransformer}, which turns
	 * a marker into /note/document/<id>/ during fillContent. Form / Disk URLs are absolute portal
	 * paths the note editor keeps as ordinary links.
	 *
	 * A profile is the one target note has a shape of its own for: the editor draws a mention of a
	 * user, name and avatar included ([P7.T3], TPL-02). The label of the link is dropped with it -
	 * the editor renders the name of the user, so a wording of the page such as "наш руководитель"
	 * cannot be kept alongside it.
	 *
	 * Whatever cannot become a valid URL is downgraded to its plain link text: an unresolvable
	 * `#landing<id>` (deleted / cross-site), a missing/inactive user, a form CRM cannot resolve (or
	 * CRM absent), a file of Disk the initiator may not read, a jump to a block, or any bare
	 * `[text](#anchor)` fragment jump becomes just `text`.
	 * This is mandatory - note's editor sanitizes hrefs (install/js/note/editor/src/utils/url.js) and
	 * REJECTS `#...`/`block:#...`/`user:#...`/`form:#...` outright, after which safe-parse-markdown drops the
	 * link mark and the text with it. Emitting readable text instead keeps the words visible. Only
	 * these binding schemes are touched; already-valid absolute links (/knowledge/, /note/, external)
	 * pass through untouched.
	 *
	 * @param int|null $homePageId home page of the base, {@see indexPageId}
	 */
	private function resolveBodyInternalLinks(
		string $markdown,
		int $siteId,
		string $siteCode,
		?int $homePageId = null,
	): string
	{
		// Match the WHOLE link (label included) so unresolved ones can be downgraded to
		// plain text rather than leaking a binding href. The negative lookbehind keeps image
		// syntax (`![alt](...)`) out of scope. `file:` is in the prefix list for the Disk scheme
		// only: the `#` right after it keeps a raw `file:///...` href out, as landing's own
		// Sanitizer does. Case-insensitive, because landing matches its own binding hrefs that way
		// - a scheme in another case is a link landing serves, so it must not reach the editor
		// unresolved either.
		$pattern = '~(?<!!)\[(?P<label>[^\]\n]+)\]\((?P<href>(?:page:|block:|user:|form:|file:)?#[^)\s]*)\)~i';
		if (!preg_match_all($pattern, $markdown, $matches))
		{
			return $markdown;
		}

		$landingHref = '~^(?:page:)?#landing(?P<lid>\d+)$~';
		$userHref = '~^user:#user(?P<uid>\d+)$~i';
		$formHref = '~^form:#crmForm(?:Inline|Popup)(?P<fid>\d+)$~i';
		// The form landing itself matches: \Bitrix\Landing\Connector\Disk::FILE_MASK_HREF.
		$diskHref = '~^(?:file:)?#diskFile(?P<oid>\d+)$~i';

		$landingIds = [];
		$userIds = [];
		$formIds = [];
		$diskObjectIds = [];
		foreach ($matches['href'] as $href)
		{
			if (preg_match($landingHref, $href, $m))
			{
				$landingIds[] = (int)$m['lid'];
			}
			elseif (preg_match($userHref, $href, $m))
			{
				$userIds[] = (int)$m['uid'];
			}
			elseif (preg_match($formHref, $href, $m))
			{
				$formIds[] = (int)$m['fid'];
			}
			elseif (preg_match($diskHref, $href, $m))
			{
				$diskObjectIds[] = (int)$m['oid'];
			}
		}

		$pages = $this->pageReader->getByIds($landingIds);
		$users = $this->filterExistingUserIds($userIds);
		$formUrls = $this->resolveCrmFormUrls($formIds);
		$diskUrls = $this->resolveDiskFileUrls($diskObjectIds);
		$sectionIdsByIndexPage = $this->sectionIdsByIndexPage($this->foldersForSite($siteId), $homePageId);

		$result = preg_replace_callback(
			$pattern,
			function (array $m) use (
				$pages,
				$users,
				$formUrls,
				$diskUrls,
				$sectionIdsByIndexPage,
				$siteId,
				$siteCode,
				$landingHref,
				$userHref,
				$formHref,
				$diskHref
			): string {
				$label = $m['label'];
				$href = $m['href'];

				if (preg_match($landingHref, $href, $lm))
				{
					$lid = (int)$lm['lid'];
					$page = $pages[$lid] ?? null;
					if ($page !== null && $page['SITE_ID'] === $siteId)
					{
						$pageCode = ($page['CODE'] !== null && $page['CODE'] !== '')
							? $page['CODE']
							: (string)$lid;

						// The binding already names the element, so the marker is built straight
						// from its id - no address has to be resolved back into one.
						return '[' . $label . '](' . LandingMentionTransformer::linkMarker(
							self::elementAnchor($lid, (bool)($page['IS_FOLDER'] ?? false), $sectionIdsByIndexPage),
							'/knowledge/' . $siteCode . '/' . $pageCode . '/',
						) . ')';
					}

					return $label;
				}

				if (preg_match($userHref, $href, $um))
				{
					$uid = (int)$um['uid'];

					return isset($users[$uid])
						? '@{' . MentionType::User->value . ':' . $uid . '}'
						: $label;
				}

				if (preg_match($formHref, $href, $fm))
				{
					$fid = (int)$fm['fid'];

					return isset($formUrls[$fid])
						? '[' . $label . '](' . $formUrls[$fid] . ')'
						: $label;
				}

				if (preg_match($diskHref, $href, $dm))
				{
					$oid = (int)$dm['oid'];

					return isset($diskUrls[$oid]) ? '[' . $label . '](' . $diskUrls[$oid] . ')' : $label;
				}

				// A jump to a block, a bare `#fragment` jump or any other unresolved binding: keep
				// readable text only - never let a `#`/`block:#`/`user:#`/`form:#` href reach the editor.
				return $label;
			},
			$markdown,
		);

		return $result ?? $markdown;
	}

	/**
	 * Anchor of the element a `#landing<id>` binding names. Three kinds of row can be behind one:
	 * an ordinary page, a section of a base still on landing's old folder model (sections live in
	 * b_landing there, and a binding can name one), and the index page of a section, which is
	 * converted as the BODY of that section and therefore answers with the section's anchor.
	 *
	 * @param array<int, int> $sectionIdsByIndexPage {@see sectionIdsByIndexPage}
	 */
	private static function elementAnchor(int $rowId, bool $isFolder, array $sectionIdsByIndexPage): string
	{
		if (isset($sectionIdsByIndexPage[$rowId]))
		{
			return LandingId::sectionAnchor($sectionIdsByIndexPage[$rowId]);
		}

		return $isFolder ? LandingId::sectionAnchor($rowId) : LandingId::pageAnchor($rowId);
	}

	/**
	 * Download URL of every file of Disk a page links to, for the files the INITIATOR of the
	 * conversion may read. Q-1 of the plan is settled this way: the link is carried over, the file
	 * is not copied - a copy would multiply the storage of the portal and detach the link from the
	 * original, while the URL keeps pointing at the one file, with Disk checking every reader's
	 * rights on its own.
	 *
	 * The right of the INITIATOR is what decides whether the link survives at all, and it is asked
	 * for by id: the steps run in a background job, so the current user of the process is nobody in
	 * particular. A file the initiator cannot read is not carried over - the link is downgraded to
	 * its label ({@see resolveBodyInternalLinks}), because a converted document must not hand out an
	 * address its own author had no access to.
	 *
	 * Nothing here may abort the conversion: an absent Disk module, a deleted object, a folder id in
	 * place of a file or a throw from Disk all end the same way - no URL, so the link becomes text.
	 *
	 * A page carries as many of these links as its author attached files, and the object behind each
	 * of them used to be read on its own. The whole set is read in one query instead
	 * ({@see loadDiskFiles}), and the security context - what the right of the initiator is decided
	 * by - is built once per storage, which is the level Disk keeps rights at anyway.
	 *
	 * @param int[] $objectIds Disk object ids (b_disk_object), not core file ids
	 * @return array<int, string> object id => download URL
	 */
	private function resolveDiskFileUrls(array $objectIds): array
	{
		$objectIds = array_values(array_unique(array_filter($objectIds, static fn(int $id): bool => $id > 0)));
		if ($objectIds === [] || !Loader::includeModule('disk'))
		{
			return [];
		}

		$files = $this->loadDiskFiles($objectIds);
		$securityContexts = [];
		$urls = [];
		foreach ($objectIds as $objectId)
		{
			$file = $files[$objectId] ?? null;
			if ($file === null)
			{
				continue;
			}

			try
			{
				$storageId = (int)$file->getStorageId();
				if (!array_key_exists($storageId, $securityContexts))
				{
					$securityContexts[$storageId] = $file->getStorage()?->getSecurityContext($this->userId);
				}

				$securityContext = $securityContexts[$storageId];
				if ($securityContext === null || !$file->canRead($securityContext))
				{
					continue;
				}

				$urls[$objectId] = \Bitrix\Disk\Driver::getInstance()
					->getUrlManager()
					->getUrlForDownloadFile($file)
				;
			}
			catch (\Throwable $e)
			{
				ImportLogger::logError("resolveDiskFileUrls failed for object {$objectId}: " . $e->getMessage());
			}
		}

		return $urls;
	}

	/**
	 * The objects of Disk a batch of links names, read in one query and keyed by object id.
	 *
	 * A folder is left out by the TYPE of the row: an object of Disk is a file or a folder, and only a
	 * file has something to download. An id that names no such row is simply absent from the answer,
	 * and the caller carries no link over for it - as it did for a model it could not load.
	 *
	 * @param int[] $objectIds
	 * @return array<int, \Bitrix\Disk\File>
	 */
	private function loadDiskFiles(array $objectIds): array
	{
		try
		{
			$models = \Bitrix\Disk\File::getModelList([
				'filter' => [
					'@ID' => $objectIds,
					'=TYPE' => \Bitrix\Disk\Internals\ObjectTable::TYPE_FILE,
				],
			]);
		}
		catch (\Throwable $e)
		{
			ImportLogger::logError('resolveDiskFileUrls failed to read the files of the batch: ' . $e->getMessage());

			return [];
		}

		$files = [];
		foreach ($models as $file)
		{
			$files[(int)$file->getId()] = $file;
		}

		return $files;
	}

	/**
	 * Filters landing profile-button user ids down to the ones that actually exist and are active
	 * on this portal, so a "открыть профиль" link to a deleted/fired user degrades to plain text
	 * instead of a mention of nobody, which the editor would draw as an empty chip.
	 *
	 * @param int[] $ids
	 * @return array<int, true> set keyed by existing user id
	 */
	private function filterExistingUserIds(array $ids): array
	{
		$ids = array_values(array_unique(array_filter($ids, static fn(int $id): bool => $id > 0)));
		if ($ids === [])
		{
			return [];
		}

		$existing = [];
		$rows = \Bitrix\Main\UserTable::getList([
			'select' => ['ID'],
			'filter' => ['@ID' => $ids, '=ACTIVE' => 'Y'],
		]);
		foreach ($rows as $row)
		{
			$existing[(int)$row['ID']] = true;
		}

		return $existing;
	}

	/**
	 * Resolves landing CRM web-form ids to their public URL. Only active forms on this portal
	 * resolve; an unknown/inactive form - or an absent CRM module - yields no entry, so the link
	 * degrades to plain text rather than pointing at a form the reader cannot open.
	 *
	 * @param int[] $ids
	 * @return array<int, string> formId => public URL
	 */
	private function resolveCrmFormUrls(array $ids): array
	{
		$ids = array_values(array_unique(array_filter($ids, static fn(int $id): bool => $id > 0)));
		if ($ids === [] || !Loader::includeModule('crm'))
		{
			return [];
		}

		$urls = [];
		$rows = \Bitrix\Crm\WebForm\Internals\FormTable::getList([
			'select' => ['ID', 'CODE', 'SECURITY_CODE'],
			'filter' => ['@ID' => $ids, '=ACTIVE' => 'Y'],
		]);
		foreach ($rows as $row)
		{
			$url = \Bitrix\Crm\WebForm\Script::getPublicUrl([
				'ID' => $row['ID'],
				'CODE' => $row['CODE'],
				'SECURITY_CODE' => $row['SECURITY_CODE'],
			]);
			if (is_string($url) && $url !== '')
			{
				$urls[(int)$row['ID']] = $url;
			}
		}

		return $urls;
	}

	/**
	 * Brings every public landing address left in the finished text to the id of the element it
	 * points at and embeds that id as a link marker, which the content step turns into a link to
	 * the converted document ({@see LandingMentionTransformer}). Two kinds of link arrive here:
	 * the table of contents built from the site menu, and an address an author typed by hand -
	 * binding hrefs are already handled by {@see resolveBodyInternalLinks}.
	 *
	 * Bringing an address to an id belongs on this side: it needs landing's own rows and its
	 * addressing rules. The base being converted is indexed from what its readers returned for
	 * this very step; a link to another knowledge base goes through the same readers.
	 *
	 * An address that names no element is left exactly as it was: landing keeps serving that
	 * route, so the reader keeps a working link instead of a dead one.
	 */
	private function resolvePublicAddressLinks(
		string $markdown,
		int $siteId,
		string $siteCode,
		?int $indexPageId,
	): string
	{
		if (!str_contains($markdown, '/knowledge/'))
		{
			return $markdown;
		}

		$this->addressIndex($siteCode, $siteId, $indexPageId);

		$result = preg_replace_callback(
			self::PUBLIC_ADDRESS_PATTERN,
			function (array $m): string {
				$anchor = $this->resolveAddressAnchor($m['path']);
				if ($anchor === null || $anchor === '')
				{
					return $m[0];
				}

				return '[' . $m['label'] . '](' . LandingMentionTransformer::linkMarker($anchor, $m['url']) . ')';
			},
			$markdown,
		);

		return $result ?? $markdown;
	}

	/**
	 * Anchor of the element a public landing path points at, or null when the path names none.
	 * The first segment is the site code; a path of one segment is the base itself.
	 */
	private function resolveAddressAnchor(string $path): ?string
	{
		$segments = array_values(array_filter(
			explode('/', $path),
			static fn(string $segment): bool => $segment !== '',
		));
		if ($segments === [])
		{
			return null;
		}

		$index = $this->addressIndex((string)array_shift($segments));
		if ($index === null)
		{
			return null;
		}

		if ($segments === [])
		{
			return LandingId::collectionAnchor($index['siteId']);
		}

		$anchor = $index['byPath'][mb_strtolower(implode('/', $segments))] ?? null;
		if ($anchor === null)
		{
			// A flattened address - the table of contents builds one for every page, folder or
			// not - names its target by code alone, which is unique inside a base as a rule.
			$anchor = $index['byCode'][mb_strtolower((string)end($segments))] ?? null;
		}

		return $anchor;
	}

	/**
	 * Path/code -> element anchor index of one knowledge base, built once per base and kept for the
	 * whole step: the pages of a batch link to the same few bases over and over.
	 *
	 * Anchors and not ids, because the two kinds of element are numbered independently: a section
	 * carries its folder id and a page its b_landing id, and only the anchor says which of them a
	 * number is ({@see LandingId}).
	 *
	 * `byPath` holds the full folder path of every element and resolves a complete address
	 * exactly. `byCode` holds bare codes for flattened addresses; a page wins over a section of
	 * the same code (landing reads the last segment of an address as a page) and a lower id wins
	 * over a higher one, so the result never depends on row order.
	 *
	 * The index page of a section is indexed under the anchor of the SECTION, at both its own
	 * address and its bare code: the page is converted as the body of that section, so an address
	 * that used to open it has to arrive at the section document.
	 *
	 * $siteId and $indexPageId are passed for the base being converted, whose site row the
	 * caller already holds; for any other base both are resolved here.
	 *
	 * @return array{siteId:int, byPath:array<string,string>, byCode:array<string,string>}|null
	 *         null when the code names no knowledge base of this portal
	 */
	private function addressIndex(string $siteCode, ?int $siteId = null, ?int $indexPageId = null): ?array
	{
		$siteCode = LandingId::normalizeSiteCode($siteCode);
		if (array_key_exists($siteCode, $this->addressIndexes))
		{
			return $this->addressIndexes[$siteCode];
		}

		$this->addressIndexes[$siteCode] = null;
		if ($siteCode === '')
		{
			return null;
		}

		if ($siteId === null)
		{
			$siteId = $this->siteReader->findSiteIdByCode($siteCode);
			$site = $siteId !== null ? $this->siteReader->getSiteById($siteId) : null;
			if ($site === null)
			{
				return null;
			}

			$indexPageId = $this->indexPageId($site);
		}

		$folders = $this->foldersForSite($siteId);
		$folderPaths = $this->folderPaths($folders);

		$byPath = [];
		$sectionAnchorByCode = [];
		foreach ($folders as $folder)
		{
			$folderId = (int)$folder['ID'];
			$anchor = LandingId::sectionAnchor($folderId);
			$path = $folderPaths[$folderId] ?? '';
			if ($path !== '')
			{
				$byPath[$path] ??= $anchor;
			}
			$sectionAnchorByCode[mb_strtolower($this->rowCode($folder))] ??= $anchor;
		}

		$sectionIdsByIndexPage = $this->sectionIdsByIndexPage($folders, $indexPageId);

		$pageAnchorByCode = [];
		foreach ($this->pageReader->listForSite($siteId, $indexPageId) as $page)
		{
			$pageId = (int)$page['ID'];
			// The listing returns leaf pages only, so no row here is a section of the old model.
			$anchor = self::elementAnchor($pageId, false, $sectionIdsByIndexPage);
			$code = mb_strtolower($this->rowCode($page));
			$folderId = (int)($page['FOLDER_ID'] ?? 0);
			// A page whose folder is not part of this listing keeps its bare code only.
			$folderPath = $folderId > 0 ? ($folderPaths[$folderId] ?? null) : '';
			if ($folderPath !== null)
			{
				$byPath[$folderPath === '' ? $code : $folderPath . '/' . $code] ??= $anchor;
			}
			$pageAnchorByCode[$code] ??= $anchor;
		}

		$this->addressIndexes[$siteCode] = [
			'siteId' => $siteId,
			'byPath' => $byPath,
			'byCode' => array_replace($sectionAnchorByCode, $pageAnchorByCode),
		];

		return $this->addressIndexes[$siteCode];
	}

	/**
	 * Full public path of every section ("guides", "guides/advanced"), lower-cased for matching.
	 * Parents come from {@see resolveFolderParents}, so a loop in b_landing.FOLDER_ID cannot send
	 * this walk into recursion either.
	 *
	 * @param array<int, array{ID:int, CODE:?string, FOLDER_ID:?int}> $folders
	 * @return array<int, string> folder id => path
	 */
	private function folderPaths(array $folders): array
	{
		$codes = $this->folderCodesById($folders);
		$parents = $this->resolveFolderParents($folders, $codes);

		$paths = [];
		foreach (array_keys($codes) as $folderId)
		{
			$segments = [];
			$currentId = $folderId;
			while ($currentId !== self::TREE_ROOT_ID && isset($codes[$currentId]))
			{
				array_unshift($segments, mb_strtolower($codes[$currentId]));
				$currentId = $parents[$currentId] ?? self::TREE_ROOT_ID;
			}

			$paths[$folderId] = implode('/', $segments);
		}

		return $paths;
	}

	private function resolvePortalName(): string
	{
		$name = (string)\COption::GetOptionString('main', 'site_name', '');

		return $name !== '' ? $name : 'Bitrix24';
	}
}

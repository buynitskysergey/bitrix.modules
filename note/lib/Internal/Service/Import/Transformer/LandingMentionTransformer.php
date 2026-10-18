<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Import\Transformer;

use Bitrix\Note\Internal\Service\Import\Source\Landing\LandingId;

/**
 * Resolves the landing link markers the landing source emits (MAP-01):
 *   [label](landing-link://<anchor><landing address>)
 *
 * The anchor is the id of the source element ({@see LandingId}, TPL-01) - `p<pageId>` for a page,
 * `f<folderId>` for a section, `s<siteId>` for the base itself - and the landing address that
 * follows it is the public address the link had, fragment included.
 *
 * Both halves are needed. The anchor is what ImportMap is searched by: it names exactly one
 * source element, whereas the address is ambiguous (two same-named pages of different folders
 * share it) and does not survive the URL_ID column. The address is the fallback: when the
 * target of the link was not converted, the marker degrades back to it, and landing keeps
 * serving that route - the reader never sees a broken link. Bringing an address to an anchor
 * is the source's job, because that needs landing's own rows and addressing rules.
 *
 * Fragments (`#anchor`) inside the target are preserved verbatim, but note's editor derives
 * heading anchors from heading TEXT (slugify) and supports no explicit anchor ids - so a
 * carried-over landing block anchor (`#bNN`) will not match any heading and the editor's link
 * resolver drops the fragment silently. Migrating such fragments to the new slug scheme is
 * intentionally out of scope here (see HeaderConverter).
 */
class LandingMentionTransformer extends MentionTransformer
{
	private const MARKER_SCHEME = 'landing-link://';

	// The `externalId` group is the anchor: named so the inherited id collector picks it up.
	// The `url` group swallows the rest of the href - the landing address with its fragment -
	// which is the fallback for a target that was not converted.
	private const MARKER_PATTERN =
		'~\[(?P<label>[^\]\n]+)\]\(landing-link://(?P<externalId>[a-z][0-9]+)(?P<url>/[^\s)]*)\)~';

	/**
	 * Builds the marker the source embeds instead of a landing address it resolved to an
	 * element. Kept here, next to the pattern that reads it, so the two cannot drift apart.
	 */
	public static function linkMarker(string $anchor, string $landingUrl): string
	{
		return self::MARKER_SCHEME . $anchor . $landingUrl;
	}

	/**
	 * Renders a literal marker of the source body inert. Page content is authored, so a page
	 * could carry a hand-written marker pointing at an arbitrary anchor; only markers the
	 * source itself emits may reach the lookup ({@see \Bitrix\Note\Internal\Service\Import\Source\Wiki\WikiMarkupConverter}
	 * does the same for its own schemes). The text stays readable but is no longer a marker.
	 *
	 * The pass over already converted markdown, hence the link scheme alone: the attachment markers in
	 * it are the ones the converters have just emitted, and they are meant to be followed. What a page
	 * itself was written with is answered before the conversion, by {@see neutralizeSourceMarkers}.
	 */
	public static function neutralizeLinkMarkers(string $markdown): string
	{
		return preg_replace('~landing-link://~i', 'landing-link-//', $markdown) ?? $markdown;
	}

	/**
	 * Renders every marker of the landing source inert: the link markers of this class and the
	 * attachment schemes of {@see \Bitrix\Note\Internal\Service\Import\Source\LandingSource}.
	 *
	 * The pass over the authored side of a base, where a marker can be nothing but hand-written - none
	 * of the schemes exists in landing, they are all note's own way of naming what a converted link
	 * points at. So each of them is neutralised, and not only the one naming an element of the base: a
	 * page written with `landing-file://<id>` names a file of the portal by a number of its own choosing,
	 * and the download step would follow that number as readily as one a converter put there.
	 */
	public static function neutralizeSourceMarkers(string $text): string
	{
		return preg_replace('~landing-(link|file|ext)://~i', 'landing-$1-//', $text) ?? $text;
	}

	protected function getPattern(): string
	{
		return self::MARKER_PATTERN;
	}

	/**
	 * Every landing mention identifier is an anchor, which lives in ImportMap.URL_ID rather
	 * than in EXTERNAL_ID - without this the batch preload would look the anchors up as
	 * external keys only and cache a miss for every link.
	 */
	protected function extractUrlId(string $raw): ?string
	{
		return $raw;
	}

	public function transform(string $markdown): MentionTransformResult
	{
		$unresolvedIds = [];

		$transformed = preg_replace_callback(
			self::MARKER_PATTERN,
			function (array $m) use (&$unresolvedIds): string {
				$label = $m['label'];
				$anchor = $m['externalId'];
				$landingUrl = $m['url'];

				$internalUrl = str_starts_with($anchor, LandingId::COLLECTION_ANCHOR_PREFIX)
					? $this->resolveCollectionUrl($anchor)
					: $this->resolveDocumentUrl($anchor, $landingUrl)
				;

				if ($internalUrl === null)
				{
					$unresolvedIds[] = $anchor;

					return '[' . $label . '](' . $landingUrl . ')';
				}

				return '[' . $label . '](' . $internalUrl . ')';
			},
			$markdown,
		);

		return new MentionTransformResult($transformed ?? $markdown, $unresolvedIds);
	}

	private function resolveDocumentUrl(string $anchor, string $landingUrl): ?string
	{
		$documentId = $this->lookupDocumentId($anchor);
		if ($documentId === null)
		{
			return null;
		}

		$fragmentAt = strpos($landingUrl, '#');
		$fragment = $fragmentAt !== false ? substr($landingUrl, $fragmentAt) : '';

		return '/note/document/' . $documentId . '/' . $fragment;
	}

	private function resolveCollectionUrl(string $anchor): ?string
	{
		$collectionId = $this->lookupCollectionId($anchor);

		return $collectionId !== null ? '/note/workspace/' . $collectionId . '/' : null;
	}

	/**
	 * The base hooks build a fallback external link out of the identifier alone. A landing
	 * marker carries its own fallback address, so {@see transform} never needs one - plain
	 * text is all these can honestly produce.
	 */
	protected function buildExternalDocumentLink(string $label, string $externalId): string
	{
		return $label;
	}

	protected function buildExternalCollectionLink(string $label, string $externalId): string
	{
		return $label;
	}
}

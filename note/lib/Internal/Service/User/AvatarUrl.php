<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\User;

use Bitrix\Main\Web\Uri;
use CFile;

/**
 * Square avatar URL for a user photo, ready to be handed to a browser.
 *
 * The single place that resizes a personal photo for this module: history authors, document-card
 * authors, live presence and user mentions all go through here, so an avatar never differs between
 * them by accident.
 */
final class AvatarUrl
{
	/**
	 * One size for every avatar in the module. The largest one drawn is 28 CSS px (the presence
	 * stack), so a 40px source was already soft on a HiDPI screen and visibly mushy at 3x. Same
	 * value the core uses for userpics. The resize is disk-cached, so a single shared size also
	 * means one cached file per user instead of one per call site.
	 */
	public const SIZE = 100;

	public static function forFile(int $fileId, int $size = self::SIZE): ?string
	{
		if ($fileId <= 0)
		{
			return null;
		}

		// EXACT, not PROPORTIONAL: an avatar is always drawn inside a circle, so it needs a real
		// square. PROPORTIONAL fits the photo into the box instead — a 4:3 photo comes back as
		// 100x75, and the circle then has to blow those 75px up to its full diameter, which is what
		// made presence avatars look chewed up. Worse, PROPORTIONAL never upscales: for a photo
		// already smaller than the box it hands back the original, so a 42px userpic was being
		// stretched over 28 CSS px on every screen. EXACT center-crops to a true 100x100 — the same
		// crop the circle shows anyway, and the convention the core uses for userpics (see
		// im\Integration\UI\EntitySelector\Helper\User and socialnetwork's PhotoService).
		//
		// Per-file resize call; Bitrix has no batch resize API. The result is disk-cached after the
		// first generation and this only runs for users who actually set a photo.
		$resized = CFile::ResizeImageGet(
			$fileId,
			['width' => $size, 'height' => $size],
			BX_RESIZE_IMAGE_EXACT,
			false,
		);

		$src = $resized['src'] ?? null;
		if (!is_string($src) || $src === '')
		{
			return null;
		}

		// CFile builds the path from the raw file name, so anything the browser reads as an escape
		// sequence breaks it: a photo stored as "IMG%2B.JPG.png" is requested as "IMG+.JPG.png" and
		// 404s. Encoding the path exactly once fixes that (and spaces along with it) — same thing
		// \Bitrix\Disk\Ui\Avatar does before putting an avatar into an SVG href.
		return Uri::urnEncode($src);
	}
}

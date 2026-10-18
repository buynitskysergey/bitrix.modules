<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Integration\Disk;

/**
 * A Disk folder the viewer is allowed to see, described in the module's own terms: no Disk model
 * leaves {@see DiskFolderGateway}.
 *
 * The id is always the original folder's, never a symlink's: a mention token is a permanent record
 * in a shared text, while a symlink is one person's shortcut and can be dropped without touching
 * the folder.
 */
final class DiskFolderItem
{
	public function __construct(
		public readonly int $id,
		public readonly string $name,
		public readonly string $url,
		public readonly string $storageName,
	) {
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\Attachment;

/**
 * The type of an attachment is shown by the product file icons (ui.icons.disk), the same ones disk
 * and the task checklist use. The pack names its icons after formats rather than extensions, so
 * extensions of one format share an icon; an extension the pack has no icon for — a Figma file,
 * say — and a file without an extension both fall back to the neutral one.
 *
 * The row of the list renders its chips on the server and the attachments window builds its rows in
 * the browser from a server answer, so the map lives here and both surfaces read the same one.
 */
final class FileIcon
{
	public const FALLBACK = 'empty';

	private const ICONS = [
		'txt' => 'txt',
		'log' => 'txt',
		'rtf' => 'txt',
		'doc' => 'doc',
		'docx' => 'doc',
		'odt' => 'odt',
		'xls' => 'xls',
		'xlsx' => 'xls',
		'csv' => 'xls',
		'ods' => 'ods',
		'ppt' => 'ppt',
		'pptx' => 'pptx',
		'odp' => 'odp',
		'pdf' => 'pdf',
		'php' => 'php',
		'zip' => 'zip',
		'rar' => 'rar',
		'7z' => 'rar',
		'gz' => 'rar',
		'tar' => 'rar',
		'jpg' => 'img',
		'jpeg' => 'img',
		'png' => 'img',
		'gif' => 'img',
		'bmp' => 'img',
		'webp' => 'img',
		'heic' => 'img',
		'svg' => 'img',
		'mov' => 'mov',
		'mp4' => 'mp4',
		'avi' => 'mp4',
		'mkv' => 'mp4',
		'webm' => 'mp4',
	];

	/**
	 * @return string the ui-icon-file-* modifier of the pack
	 */
	public static function resolve(string $extension): string
	{
		return self::ICONS[mb_strtolower(trim($extension))] ?? self::FALLBACK;
	}

	/**
	 * Every icon of the pack this module can ask for. An icon the pack does not declare draws
	 * nothing at all, so the set is what a test checks the map against.
	 *
	 * @return string[]
	 */
	public static function usedIcons(): array
	{
		return array_values(array_unique([self::FALLBACK, ...array_values(self::ICONS)]));
	}
}

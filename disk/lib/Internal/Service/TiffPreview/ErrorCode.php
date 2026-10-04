<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\TiffPreview;

final class ErrorCode
{
	public const IMAGICK_UNAVAILABLE = 'imagick_unavailable';
	public const TIFF_UNSUPPORTED = 'tiff_unsupported';
	public const FILE_TOO_LARGE = 'file_too_large';
	public const IMAGE_TOO_LARGE = 'image_too_large';
	public const TIMEOUT = 'timeout';
	public const CONVERSION_FAILED = 'conversion_failed';
}

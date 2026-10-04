<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\TiffPreview;

final class PreviewStatus
{
	public const READY = 'ready';
	public const PREPARING = 'preparing';
	public const ERROR = 'error';
}

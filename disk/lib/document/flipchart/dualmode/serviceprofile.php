<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Flipchart\DualMode;

/**
 * Which board service instance serves an object: the vendor one (Old) or the new one (New).
 *
 * A profile is always recomputed per request; it is never stored on the document session.
 */
enum ServiceProfile: string
{
	case Old = 'old';
	case New = 'new';
}

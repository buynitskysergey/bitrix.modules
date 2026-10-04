<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Flipchart\DualMode;

/**
 * State of a pilot project record.
 *
 * Only Pending and Active are ever written to the option. Blocked is produced by the parser for a
 * record whose group id is readable but whose state is not, and it forbids opening that project.
 */
enum PilotProjectState: string
{
	case Pending = 'pending';
	case Active = 'active';
	case Blocked = 'blocked';
}

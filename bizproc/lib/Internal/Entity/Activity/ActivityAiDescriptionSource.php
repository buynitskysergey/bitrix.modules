<?php

namespace Bitrix\Bizproc\Internal\Entity\Activity;

enum ActivityAiDescriptionSource: string
{
	case Manual = 'manual';
	case Auto = 'auto';
	case Hybrid = 'hybrid';
}

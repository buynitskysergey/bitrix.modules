<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Entity\History;

enum SourceType: string
{
	case Application = 'application';
	case AiAssistant = 'ai_assistant';
}

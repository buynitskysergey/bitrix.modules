<?php

namespace Bitrix\Bizproc\Api\Enum\Template;

enum TemplatePublicationType: int // values are persisted in b_bp_workflow_template_change and cannot be renumbered
{
	case Common = 1;
}

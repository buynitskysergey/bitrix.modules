<?php

namespace Bitrix\Crm\Copilot\CallAssessment\Grid\CallList\Row\Assembler;

use Bitrix\Crm\Copilot\CallAssessment\Grid\CallList\Row\Assembler\Field\AssessmentFieldAssembler;
use Bitrix\Crm\Copilot\CallAssessment\Grid\CallList\Row\Assembler\Field\CallDateFieldAssembler;
use Bitrix\Crm\Copilot\CallAssessment\Grid\CallList\Row\Assembler\Field\CallThemeFieldAssembler;
use Bitrix\Crm\Copilot\CallAssessment\Grid\CallList\Row\Assembler\Field\ClientFieldAssembler;
use Bitrix\Crm\Copilot\CallAssessment\Grid\CallList\Row\Assembler\Field\ManagerFieldAssembler;
use Bitrix\Crm\Copilot\CallAssessment\Grid\CallList\Row\Assembler\Field\ScriptFieldAssembler;
use Bitrix\Main\Grid\Row\RowAssembler;

final class CallListRowAssembler extends RowAssembler
{
	protected function prepareFieldAssemblers(): array
	{
		return [
			new CallThemeFieldAssembler(['CALL_THEME']),
			new ManagerFieldAssembler(['MANAGER']),
			new AssessmentFieldAssembler(['ASSESSMENT']),
			new ClientFieldAssembler(['CLIENT']),
			new CallDateFieldAssembler(['CALL_DATE']),
			new ScriptFieldAssembler(['SCRIPT']),
		];
	}
}

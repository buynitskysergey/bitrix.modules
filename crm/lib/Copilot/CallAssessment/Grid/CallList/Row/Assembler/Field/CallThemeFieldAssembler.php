<?php

namespace Bitrix\Crm\Copilot\CallAssessment\Grid\CallList\Row\Assembler\Field;

use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Main\Grid\Row\FieldAssembler;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\UI\Extension;
use Bitrix\Main\Web\Json;

final class CallThemeFieldAssembler extends FieldAssembler
{
	public function prepareRows(array $rowList): array
	{
		Loc::loadMessages(__FILE__);
		Extension::load(AIManager::isCallScoringV2Enabled() ? ['crm.router'] : ['crm.ai.call']);

		return parent::prepareRows($rowList);
	}

	protected function prepareRow(array $row): array
	{
		$row['columns'] ??= [];
		$data = $row['data'] ?? [];

		foreach ($this->getColumnIds() as $columnId)
		{
			$row['columns'][$columnId] = $this->renderTheme($data);
		}

		return $row;
	}

	private function renderTheme(array $data): string
	{
		$text = htmlspecialcharsbx($this->resolveText($data));
		$activityId = (int)($data['ACTIVITY_ID'] ?? 0);
		$ownerTypeId = (int)($data['OWNER_TYPE_ID'] ?? 0);
		$ownerId = (int)($data['OWNER_ID'] ?? 0);

		if ($activityId <= 0 || $ownerTypeId <= 0 || $ownerId <= 0)
		{
			return $text;
		}

		$payload = Json::encode([
			'activityId' => $activityId,
			'ownerTypeId' => $ownerTypeId,
			'ownerId' => $ownerId,
		]);
		if (AIManager::isCallScoringV2Enabled())
		{
			$onclick = 'BX.Crm.Router.Instance.openAiReportDrawer("call-assessment", ' . $payload . '); return false;';
		}
		else
		{
			$onclick = 'new BX.Crm.AI.Call.CallQuality(' . $payload . ').open(); return false;';
		}

		return '<a class="crm-copilot-call-assessment-call-list__theme" href="#"'
			. " onclick='" . $onclick . "'>" . $text . '</a>';
	}

	private function resolveText(array $data): string
	{
		$theme = trim($data['CALL_THEME'] ?? '');
		if ($theme !== '')
		{
			return $theme;
		}

		$subject = trim($data['ACTIVITY_SUBJECT'] ?? '');
		if ($subject !== '')
		{
			return $subject;
		}

		if (empty($data['ACTIVITY_ID']) || ($data['ACTIVITY_START'] ?? null) === null)
		{
			return Loc::getMessage('CRM_COPILOT_CALL_ASSESSMENT_CALL_LIST_GRID_THEME_DELETED');
		}

		return '—';
	}
}

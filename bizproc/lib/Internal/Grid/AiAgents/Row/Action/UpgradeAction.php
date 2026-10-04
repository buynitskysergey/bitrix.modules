<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Grid\AiAgents\Row\Action;

use Bitrix\Bizproc\Internal\Grid\AiAgents\AiAgentsActionType;
use Bitrix\Main\Config\Option;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

class UpgradeAction extends JsGridAction
{
	// Dark launch: the upgrade action is hidden until this module flag is turned on.
	private const FEATURE_OPTION = 'ai_agent_upgrade_enabled';

	public static function getId(): string
	{
		return AiAgentsActionType::UPGRADE->value;
	}

	public function processRequest(HttpRequest $request): ?Result
	{
		return null;
	}

	protected function getText(): string
	{
		return Loc::getMessage('BIZPROC_AI_AGENTS_GRID_ACTION_UPGRADE') ?? '';
	}

	/**
	 * @param array{ID: string} $rawFields
	 */
	public function getControl(array $rawFields): ?array
	{
		$templateId = filter_var($rawFields['ID'] ?? '', FILTER_VALIDATE_INT, [
			'options' => [
				'min_range' => 0,
			],
		]);

		if (!$templateId)
		{
			return null;
		}

		if (!$this->isEnabled($rawFields))
		{
			return null;
		}

		return parent::getControl($rawFields);
	}

	protected function isEnabled(array $rawFields): bool
	{
		$isFeatureEnabled = Option::get('bizproc', self::FEATURE_OPTION, 'N') === 'Y';

		$isNotSystemTemplate = !$this->isSystemTemplate($rawFields);

		// Upgrade is admin-only; the controller enforces the same guard, this
		// visibility check is only indicative.
		$isCurrentUserAdmin = $this->isUserAdmin();

		// DTO-01: availability is computed server-side, do not recalculate here.
		$hasNewVersion = (bool)($rawFields['hasNewVersion'] ?? false);

		return
			$isFeatureEnabled
			&& $isNotSystemTemplate
			&& $isCurrentUserAdmin
			&& $hasNewVersion
		;
	}

	protected function getActionParams(array $rawFields): array
	{
		return [
			'templateId' => $rawFields['ID'] ?? '',
			'currentVersionLabel' => $rawFields['currentVersionLabel'] ?? '',
			'newVersionLabel' => $rawFields['newVersionLabel'] ?? '',
			'isCustomized' => (bool)($rawFields['isCustomized'] ?? false),
		];
	}
}

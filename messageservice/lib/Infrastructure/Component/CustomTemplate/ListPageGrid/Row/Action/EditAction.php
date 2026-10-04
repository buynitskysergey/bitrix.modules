<?php

declare(strict_types=1);

namespace Bitrix\MessageService\Infrastructure\Component\CustomTemplate\ListPageGrid\Row\Action;

use Bitrix\Main\Grid\Row\Action\BaseAction;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\MessageService\Infrastructure\Component\CustomTemplate\ListPageGrid\Settings\CustomTemplateGridSettings;

final class EditAction extends BaseAction
{
	public function __construct(private readonly CustomTemplateGridSettings $settings)
	{
	}

	public static function getId(): ?string
	{
		return 'edit';
	}

	public function processRequest(HttpRequest $request): ?Result
	{
		return null;
	}

	protected function getText(): string
	{
		return (string)Loc::getMessage('MSGSVC_CT_GRID_ACTION_EDIT');
	}

	public function getControl(array $rawFields): ?array
	{
		$id = (int)($rawFields['ID'] ?? 0);
		if ($id <= 0)
		{
			return null;
		}

		// Editing is the primary row intent (the title link mirrors it), so it also
		// runs as the row's default double-click action.
		$this->default = true;
		$this->onclick = self::buildOnclick($this->settings, $rawFields);

		return parent::getControl($rawFields);
	}

	/**
	 * Shared with the title cell assembler: the grid title link must trigger exactly
	 * the same edit flow as this row action. All string payload fields are JS-escaped;
	 * the caller owns the escaping of the surrounding output context (HTML attribute).
	 */
	public static function buildOnclick(CustomTemplateGridSettings $settings, array $rawFields): string
	{
		$id = (int)($rawFields['ID'] ?? 0);
		$title = (string)($rawFields['TITLE'] ?? '');
		$body = (string)($rawFields['BODY'] ?? '');
		$scene = (string)($rawFields['SCENE'] ?? $settings->getScene());
		$targetId = (string)($rawFields['TARGET_ID'] ?? $settings->getTargetId());

		return sprintf(
			"BX.Event.EventEmitter.emit('BX.MessageService.CustomTemplate.List:onEditRequested', { gridId: '%s', sceneId: '%s', targetId: '%s', templateId: %d, title: '%s', body: '%s' })",
			\CUtil::JSEscape($settings->getID()),
			\CUtil::JSEscape($scene),
			\CUtil::JSEscape($targetId),
			$id,
			\CUtil::JSEscape($title),
			\CUtil::JSEscape($body),
		);
	}
}

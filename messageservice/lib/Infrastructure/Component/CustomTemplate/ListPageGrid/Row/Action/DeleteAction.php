<?php

declare(strict_types=1);

namespace Bitrix\MessageService\Infrastructure\Component\CustomTemplate\ListPageGrid\Row\Action;

use Bitrix\Main\Grid\Row\Action\BaseAction;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\MessageService\Infrastructure\Component\CustomTemplate\ListPageGrid\Settings\CustomTemplateGridSettings;

/**
 * Per-row "Delete" action. The deletion confirmation + AJAX call are handled
 * on the frontend by the generic list component script (requestDelete).
 */
final class DeleteAction extends BaseAction
{
	public function __construct(private readonly CustomTemplateGridSettings $settings)
	{
	}

	public static function getId(): ?string
	{
		return 'delete';
	}

	public function processRequest(HttpRequest $request): ?Result
	{
		return null;
	}

	protected function getText(): string
	{
		return (string)Loc::getMessage('MSGSVC_CT_GRID_ACTION_DELETE');
	}

	public function getControl(array $rawFields): ?array
	{
		$id = (int)($rawFields['ID'] ?? 0);
		if ($id <= 0)
		{
			return null;
		}
		$title = (string)($rawFields['TITLE'] ?? '');
		$subject = (string)($rawFields['CONTEXT_LABEL'] ?? '');

		$this->onclick = sprintf(
			"BX.MessageService.CustomTemplate.List.requestDelete({ gridId: '%s', templateId: %d, title: '%s', subject: '%s' })",
			\CUtil::JSEscape($this->settings->getID()),
			$id,
			\CUtil::JSEscape($title),
			\CUtil::JSEscape($subject),
		);

		return parent::getControl($rawFields);
	}
}

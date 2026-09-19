<?php

namespace Bitrix\Sign\Ui\MyDocumentsGrid\ActionCellTemplateFactory;

use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Localization\Loc;
use Bitrix\Sign\Contract\Grid\MyDocuments\ActionCellTemplate;

class RefusedDocumentTemplate implements ActionCellTemplate
{
	use ActionDateTrait;

	/**
	 * @param bool $muted Drops the "stopped" cell background so another state shown
	 *        in the same cell owns it; the refused text itself stays untouched.
	 */
	public function __construct(
		private readonly ?DateTime $refusedDate = null,
		private readonly bool $muted = false,
	)
	{}

	public function render(): string
	{
		$message = Loc::getMessage('SIGN_B2E_MY_DOCUMENTS_REFUSED');
		$formattedDate = self::getFormattedDate($this->refusedDate);
		$backgroundClass = $this->muted ? '' : ' sign-grid-download-background-stopped';

		return <<<HTML
			<div class="sign-grid-action-signed-info$backgroundClass">
				<span class="sign-grid-action-stopped-text">
					$message
				</span>
				<span class="sign-grid-action-date" title="$this->refusedDate">
					$formattedDate
				</span>
			</div>
		HTML;
	}
}
<?php

namespace Bitrix\Sign\Ui\MyDocumentsGrid\ActionCellTemplateFactory;

use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Localization\Loc;
use Bitrix\Sign\Contract\Grid\MyDocuments\ActionCellTemplate;

class SignedDocumentTemplate implements ActionCellTemplate
{
	use ActionDateTrait;

	/**
	 * @param bool $muted Drops the "done" cell background so another state shown
	 *        in the same cell owns it; the signed text itself stays untouched.
	 */
	public function __construct(
		private readonly ?DateTime $signDate,
		private readonly bool $muted = false,
	)
	{}

	public function render(): string
	{
		$formattedDate = self::getFormattedDate($this->signDate);
		$message = Loc::getMessage('SIGN_B2E_MY_DOCUMENTS_SIGNED');
		$backgroundClass = $this->muted ? '' : ' sign-grid-download-background-done';

		return <<<HTML
			<div class="sign-grid-action-signed-info$backgroundClass">
				<span class="sign-grid-action-signed-text">
					$message
				</span>
				<span class="sign-grid-action-date" title="$this->signDate">
					$formattedDate
				</span>
			</div>
		HTML;
	}
}
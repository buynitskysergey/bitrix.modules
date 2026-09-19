<?php

namespace Bitrix\Sign\Ui\MyDocumentsGrid\ActionCellTemplateFactory;

use Bitrix\Main\Grid\Cell\Label\Color;
use Bitrix\Main\Localization\Loc;
use Bitrix\Sign\Contract\Grid\MyDocuments\ActionCellTemplate;

/**
 * Annulment mark shown next to the completed action state of the cell: the
 * document stays signed, so the label is added on top of the signed/refused
 * state instead of replacing it. The label is red to stand out against the
 * signed state it sits under, while the marker class on the wrapper (as in the
 * sibling state templates) drives the neutral cell background and takes over
 * from the state background of those templates.
 */
class AnnulledLabelTemplate implements ActionCellTemplate
{
	public function render(): string
	{
		$colorClass = htmlspecialcharsbx(Color::DANGER);
		$message = htmlspecialcharsbx((string)Loc::getMessage('SIGN_B2E_MY_DOCUMENTS_ANNULLED'));

		return <<<HTML
			<div class="sign-grid-action-annulled sign-grid-download-background-annulled">
				<div
					class="ui-label ui-label-fill $colorClass"
					data-test-id="sign-my-documents-list__status-annulled"
				>
					<div class="ui-label-inner">
						$message
					</div>
				</div>
			</div>
		HTML;
	}
}

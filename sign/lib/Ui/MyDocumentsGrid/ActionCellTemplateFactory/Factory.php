<?php

namespace Bitrix\Sign\Ui\MyDocumentsGrid\ActionCellTemplateFactory;

use Bitrix\Sign\Item\MyDocumentsGrid\Row;
use Bitrix\Sign\Type\MyDocumentsGrid\Action;
use Bitrix\Sign\Contract\Grid\MyDocuments\ActionCellTemplate;

class Factory
{
	public function create(
		Row $row,
		?string $textForActionColumn,
		bool $isAnnulMarkEnabled = false
	): ActionCellTemplate
	{
		// Only a completed signer member can be annulled, so the mark always joins
		// a finished state and never an active signing action.
		$isAnnulled = $isAnnulMarkEnabled && $row->document->isAnnulled;
		$templates = $this->createStateTemplates($row, $textForActionColumn, $isAnnulled);

		if ($isAnnulled)
		{
			$templates = $this->withAnnulledLabel($templates);
		}

		return new CompositeTemplate($templates);
	}

	/**
	 * @return ActionCellTemplate[]
	 */
	private function createStateTemplates(
		Row $row,
		?string $textForActionColumn,
		bool $isAnnulled
	): array
	{
		$action = $row->action;
		$document = $row->document;
		$myMember = $row->myMemberInProcess;
		$downloadFileLink = $row->file->url ?? null;
		$isActionDownloadOrView = in_array($action, [Action::DOWNLOAD, Action::VIEW]);
		$initiatedByEmployee = $document->isInitiatedByEmployee();
		$initiatedByCompany = $document->isInitiatedByCompany();
		$isDocumentStatusStoppedForEmployeeScenario = $initiatedByEmployee && $document->isDocumentStopped();
		$actionDate = $row->document->editDate
			?? $row->document->approvedDate
			?? $row->document->cancelledDate
			?? $row->document->signDate
			?? null
		;

		if (($initiatedByEmployee && $isActionDownloadOrView) || ($initiatedByCompany && $myMember->isSigner()))
		{
			if ($action === Action::DOWNLOAD && $myMember->isSigner())
			{
				if ($isDocumentStatusStoppedForEmployeeScenario && $downloadFileLink !== null)
				{
					return [
						new RefusedDocumentTemplate($actionDate, $isAnnulled),
						new DownloadLinkTemplate(
							$downloadFileLink,
							$textForActionColumn,
						)
					];
				}

				if ($myMember->isDone())
				{
					return [
						new SignedDocumentTemplate($document->signDate, $isAnnulled),
						new DownloadLinkTemplate(
							$downloadFileLink,
							$textForActionColumn,
						)
					];
				}
			}

			if ($downloadFileLink !== null && $action === Action::VIEW)
			{
				return [
					new ViewCellTemplate(
						$document->sendDate,
						$downloadFileLink,
						$textForActionColumn,
					)
				];
			}

			return [
				new CompletedActionTextTemplate(
					$row,
					$actionDate,
				)
			];
		}

		return [
			new DefaultCompletedActionTextTemplate(
				$textForActionColumn,
				$actionDate,
			)
		];
	}

	/**
	 * The mark belongs right under the state it comments on and above the download
	 * link that closes the cell.
	 *
	 * @param ActionCellTemplate[] $templates
	 * @return ActionCellTemplate[]
	 */
	private function withAnnulledLabel(array $templates): array
	{
		$label = new AnnulledLabelTemplate();
		$lastTemplate = end($templates);
		if ($lastTemplate instanceof DownloadLinkTemplate)
		{
			array_splice($templates, -1, 0, [$label]);

			return $templates;
		}

		$templates[] = $label;

		return $templates;
	}
}

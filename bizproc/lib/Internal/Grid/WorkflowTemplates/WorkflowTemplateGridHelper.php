<?php

namespace Bitrix\Bizproc\Internal\Grid\WorkflowTemplates;

use Bitrix\Bizproc\Internal\Config\PilotPublicationFeature;
use Bitrix\Bizproc\Internal\Repository\WorkflowTemplate\PilotVersionRepository;
use Bitrix\Bizproc\Internal\Service\Pilot\PilotPresence;
use Bitrix\Bizproc\UI\UserView;
use Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplate;
use Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplate_Collection;
use Bitrix\Main\EO_User;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Web\Uri;
use Bitrix\UI\Buttons\AirButtonStyle;
use Bitrix\UI\Buttons\Button;
use Bitrix\UI\Buttons\Color;
use Bitrix\UI\Buttons\LinkTarget;
use Bitrix\UI\Buttons\Size;
use Bitrix\UI\Buttons\Tag;
use Bitrix\UI\Public\System\Label;
use CBPViewHelper;

final class WorkflowTemplateGridHelper
{
	public function __construct(
		private readonly PilotVersionRepository $pilotRepository = new PilotVersionRepository(),
		private readonly PilotPresence $pilotPresence = new PilotPresence(),
	)
	{
	}

	public function createGridData(EO_WorkflowTemplate_Collection $collection): array
	{
		$pilotTemplateIds = $this->findPilotTemplateIds($collection);
		$data = [];

		foreach ($collection as $template)
		{
			/** @var EO_User $editor */
			$editor = $template->getUpdatedUser() ?? null;
			/** @var EO_User $creator */
			$creator = $template->getCreatedUser() ?? $template->getUser();
			/** @var EO_WorkflowTemplate $template */
			$data[] = [
				'ID' => $template->getId(),
				'NAME' => $this->createNameCell($template, isset($pilotTemplateIds[$template->getId()])),
				'ACTIONS' => $this->createActionCell($template),
				'MODIFIED' => CBPViewHelper::formatDateTime($template->getModified()),
				'EDITOR' => $editor != null ? $this->createUserCell($editor) : null,
				'CREATOR' => $creator != null ? $this->createUserCell($creator) : null,
			];
		}

		return $data;
	}

	private function createNameCell(EO_WorkflowTemplate $template, bool $hasPilot): array
	{
		return [
			'templateId' => $template->getId(),
			'name' => $template->getName(),
			'description' => $template->getDescription(),
			'pilotLabel' => $hasPilot ? $this->createPilotLabel() : null,
		];
	}

	/**
	 * The mark of a running pilot. The audience is not shown here: the list tells that the template lives
	 * in a pilot version, and who it acts on is answered by the editor.
	 */
	private function createPilotLabel(): string
	{
		$label = new Label\Label([
			'value' => (string)Loc::getMessage('BIZPROC_TEMPLATE_GRID_PILOT_LABEL'),
			'style' => Label\Style::TINTED,
			'size' => Label\Size::SM,
		]);

		return $label->render();
	}

	/**
	 * The templates of the whole page a pilot acts on, asked in a single query.
	 *
	 * Two gates stand before that query and both cost nothing: with the feature off the mark is not shown at
	 * all, and on a portal without a single pilot there is nothing to look for - the counter of the
	 * pilots is a module option the kernel has already loaded.
	 *
	 * @return array<int, true> keyed by template id
	 */
	private function findPilotTemplateIds(EO_WorkflowTemplate_Collection $collection): array
	{
		if (!PilotPublicationFeature::isEnabled() || !$this->pilotPresence->hasAnyPilot())
		{
			return [];
		}

		$templateIds = [];
		foreach ($collection as $template)
		{
			$templateIds[] = (int)$template->getId();
		}

		return array_fill_keys($this->pilotRepository->findTemplateIdsWithPilot($templateIds), true);
	}

	private function createActionCell(EO_WorkflowTemplate $template): string
	{
		$actionButton = new Button([
			'tag' => Tag::LINK,
			'color' => Color::PRIMARY,
			'size' => Size::SMALL,
			'className' => 'ui-text-underline-none bizproc-template-processes-grid-edit-link',
			'text' => Loc::getMessage('BIZPROC_TEMPLATE_PROCESSES_CHANGE_BUTTON'),
			'link' => "/bizprocdesigner/editor/?ID={$template->getId()}",
			'air' => true,
			'style' => AirButtonStyle::FILLED,
			'target' => LinkTarget::LINK_TARGET_BLANK,
		]);

		return $actionButton->render();
	}

	private function createUserCell(EO_User $user): array
	{
		$userView = new UserView($user);
		$avatar = $userView->getUserAvatar();
		$emptyAvatar = (empty($avatar) ? 'empty' : '');
		$avatarStyle = (empty($avatar) ? '' : ' style="background-image: url(\'' . Uri::urnEncode($avatar) . '\')"');
		$fullName = $userView->getFullName();

		return [
			'visible' => $emptyAvatar,
			'style' => $avatarStyle,
			'userId' => $userView->getUserId(),
			'fullName' => $fullName,
		];
	}
}

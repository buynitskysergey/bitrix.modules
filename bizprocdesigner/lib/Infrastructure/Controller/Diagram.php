<?php

namespace Bitrix\BizprocDesigner\Infrastructure\Controller;

use Bitrix\Bizproc\Api;
use Bitrix\Bizproc\Api\Data\WorkflowTemplateHistoryService\TemplateVersion;
use Bitrix\Bizproc\Api\Enum\ErrorMessage;
use Bitrix\Bizproc\Internal\Service\AiAgentGrid\Version\TemplateRevisionService;
use Bitrix\Bizproc\Public\Command\WorkflowTemplate\UpdateWorkflowTemplate\UpdateWorkflowTemplateCommand;
use Bitrix\Bizproc\Public\Service\LastValues\LastValuesService;
use Bitrix\Bizproc\Public\Service\SetupTemplate\SetupTemplateService;
use Bitrix\Bizproc\Public\Service\TemplateAccessService;
use Bitrix\Bizproc\Public\Service\WorkflowTemplate\Dto\PilotEditorState;
use Bitrix\Bizproc\Public\Service\WorkflowTemplate\Dto\PilotPublicationRequest;
use Bitrix\Bizproc\Public\Service\WorkflowTemplate\PilotEditorStateService;
use Bitrix\Bizproc\Public\Service\WorkflowTemplate\PilotPublicationService;
use Bitrix\Bizproc\UI\UserView;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable;
use Bitrix\Bizproc\Workflow\Template\WorkflowTemplateDraftTable;
use Bitrix\Bizproc\Workflow\Template\Converter\NodesToTemplate;
use Bitrix\Bizproc\Workflow\Template\Converter\TemplateToNodes;
use Bitrix\Bizproc\Workflow\Template\Converter\SequentialToNodeWorkflow;
use Bitrix\BizprocDesigner\Infrastructure\Enum\StartTrigger;
use Bitrix\BizprocDesigner\Internal\Config\Feature;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\AgentWebhookService;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\DocumentAccessService;
use Bitrix\BizprocDesigner\Internal\Service\Container;
use Bitrix\Main\Access\AccessCode;
use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\DI\Exception\CircularDependencyException;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Engine\JsonController;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\Result;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;

class Diagram extends JsonController
{
	private const PUBLISH_MODE_MAIN = 'main';
	private const PUBLISH_MODE_USER = 'user';

	private const CONFIRMATION_SETTINGS_FREEZE = 'settingsFreeze';
	private const CONFIRMATION_EMPTY_CONSTANTS = 'emptyConstants';
	private const CONFIRMATION_DRAFT_OVERWRITE = 'draftOverwrite';
	private const CONFIRMATION_NO_COMMON_VERSION = 'noCommonVersion';
	private const CONFIRMATION_PILOT_STOP = 'pilotStop';

	private const ERROR_CONFIRMATION_REQUIRED = 'CONFIRMATION_REQUIRED';
	private const ERROR_PILOT_CONSTANTS_EMPTY = 'PILOT_CONSTANTS_EMPTY';

	/** Fields of the template the pilot version is made of; the rest of the row is shared metadata. */
	private const EXECUTABLE_FIELDS = ['TEMPLATE', 'PARAMETERS', 'VARIABLES', 'CONSTANTS'];

	/** Fields the scheme on the canvas declares, so they follow the scheme the canvas was filled from. */
	private const SCHEME_OWNED_META_FIELDS = ['CONSTANTS', 'VARIABLES', 'PARAMETERS'];

	private ?TemplateAccessService $accessService = null;

	/**
	 * @throws LoaderException
	 */
	protected function init(): void
	{
		parent::init();

		Loader::requireModule('bizproc');
	}

	private function accessService(): TemplateAccessService
	{
		return $this->accessService ??= new TemplateAccessService();
	}

	public function getAction(
		int $templateId = 0,
		?array $documentType = null,
		?string $startTrigger = null,
	): ?array
	{
		$validatedStartTrigger = $this->validateStartTrigger($startTrigger);

		$data = $this->getTemplateData($templateId, $documentType, $validatedStartTrigger);

		if ($data)
		{
			$companyName = \Bitrix\Main\Config\Option::get('bitrix24', 'site_title');
			$data['companyName'] = $companyName;
		}

		return $data;
	}

	/**
	 * Values of the last finished run of the template, keyed by the inspector expression. Missing data
	 * is an empty map, not an error: the data block must open even when there is nothing to show.
	 *
	 * @return array{values: array<string, array>|\stdClass}|null
	 */
	public function getLastRunValuesAction(int $templateId): ?array
	{
		$tpl = $this->getTplForAccessCheck($templateId);
		$user = new \CBPWorkflowTemplateUser(\CBPWorkflowTemplateUser::CurrentUser);

		if ($tpl === null)
		{
			// Same anti-oracle guard as getTemplateData: a missing record must not reveal its
			// (non-)existence to a non-admin caller.
			if (!$user->isAdmin())
			{
				$this->addError(ErrorMessage::ACCESS_DENIED->getCodedError());

				return null;
			}

			$this->addError(ErrorMessage::TEMPLATE_NOT_FOUND->getCodedError(['#ID#' => $templateId]));

			return null;
		}

		// Reading a run of a Nodes template is bounded by the same ACL as opening it; anything else keeps
		// the legacy document-type gate. Without this the inspector would leak real document values to a
		// user who may not open the template at all.
		$canRead = $tpl->getType() === Api\Enum\Template\WorkflowTemplateType::Nodes->value
			? $this->accessService()->canEdit($templateId, $user->getId(), $tpl->getDocumentComplexType())
			: $this->canWriteTemplate($tpl, $user);

		if (!$canRead)
		{
			$this->addError(ErrorMessage::ACCESS_DENIED->getCodedError());

			return null;
		}

		$values = (new LastValuesService())->getValues($templateId, (int)$user->getId());

		return [
			// the map must stay a JSON object for the client even when it is empty
			'values' => $values === [] ? new \stdClass() : $values,
		];
	}

	/**
	 * Published versions of the template, freshest first. Rights are checked by the history service on
	 * every call, so the action only carries the coded errors of the service to the client.
	 */
	public function getVersionsAction(int $templateId): ?array
	{
		$response = (new Api\Service\WorkflowTemplateHistoryService())->getVersions(
			new Api\Request\WorkflowTemplateHistoryService\GetVersionsRequest(
				$templateId,
				(int)CurrentUser::get()->getId(),
			),
		);

		if (!$response->isSuccess())
		{
			$this->addErrors($response->getErrors());

			return null;
		}

		return ['versions' => array_map(self::convertVersion(...), $response->getVersions())];
	}

	/**
	 * The snapshot of a version travels to the graph through the very converter the regular load uses,
	 * otherwise viewing a version would diverge from opening the template.
	 */
	public function getVersionAction(int $templateId, int $versionId): ?array
	{
		$response = (new Api\Service\WorkflowTemplateHistoryService())->getVersion(
			new Api\Request\WorkflowTemplateHistoryService\GetVersionRequest(
				$templateId,
				$versionId,
				(int)CurrentUser::get()->getId(),
			),
		);

		if (!$response->isSuccess())
		{
			$this->addErrors($response->getErrors());

			return null;
		}

		// The graph comes from the snapshot and the rest of the surface is read-only, so the body of the
		// published template has no reader here and never travels to the client.
		$tpl = $this->getTpl($templateId, ['ID', 'NAME', 'DESCRIPTION', 'MODULE_ID', 'ENTITY', 'DOCUMENT_TYPE']);
		if ($tpl === null)
		{
			$this->addError(ErrorMessage::TEMPLATE_NOT_FOUND->getCodedError(['#ID#' => $templateId]));

			return null;
		}

		$snapshot = $response->getTemplateData();
		[$blocks, $connections] = (new TemplateToNodes(
			$snapshot['TEMPLATE'] ?? [],
			Feature::instance()->areComplexNodeConnectionsAvailable(),
		))->convert();

		// The name and the description belong to the version as much as its graph does, and a restore puts
		// them back from the snapshot, so the view shows them from there too and falls back to the live row.
		$snapshotMeta = array_intersect_key(
			$snapshot,
			array_flip(['CONSTANTS', 'VARIABLES', 'PARAMETERS', 'NAME', 'DESCRIPTION']),
		);

		return [
			'version' => self::convertVersion($response->getVersion()),
			'template' => array_merge($tpl->collectValues(), $snapshotMeta),
			'blocks' => $blocks,
			'connections' => $connections,
		];
	}

	/**
	 * The editor must switch to the returned draft: autosave writing into the previous one would
	 * overwrite the backup copy left behind by the restore.
	 */
	public function restoreVersionAction(int $templateId, int $versionId): ?array
	{
		$response = (new Api\Service\WorkflowTemplateHistoryService())->restoreVersion(
			new Api\Request\WorkflowTemplateHistoryService\RestoreVersionRequest(
				$templateId,
				$versionId,
				(int)CurrentUser::get()->getId(),
			),
		);

		if (!$response->isSuccess())
		{
			$this->addErrors($response->getErrors());

			return null;
		}

		return ['draftId' => $response->getDraftId()];
	}

	/**
	 * A null draftId means the template is left without drafts and the editor returns to the published
	 * configuration.
	 */
	public function undoRestoreAction(int $templateId): ?array
	{
		$response = (new Api\Service\WorkflowTemplateHistoryService())
			->undoRestore($templateId, (int)CurrentUser::get()->getId())
		;

		if (!$response->isSuccess())
		{
			$this->addErrors($response->getErrors());

			return null;
		}

		return ['draftId' => $response->getDraftId()];
	}

	public function discardRestoreBackupAction(int $templateId): ?array
	{
		$response = (new Api\Service\WorkflowTemplateHistoryService())
			->discardRestoreBackup($templateId, (int)CurrentUser::get()->getId())
		;

		if (!$response->isSuccess())
		{
			$this->addErrors($response->getErrors());

			return null;
		}

		return [];
	}

	private static function convertVersion(TemplateVersion $version): array
	{
		return [
			'id' => $version->id,
			'versionNumber' => $version->versionNumber,
			'publicationType' => $version->publicationType,
			'createdTimestamp' => $version->createdTimestamp,
			'authorId' => $version->authorId,
			'isCurrent' => $version->isCurrent,
		];
	}

	public function publicateAction(): ?array
	{
		$diagramData = $this->prepareDiagramData();

		if ($diagramData === null)
		{
			return null;
		}

		$templateId = $diagramData['templateId'];
		$tpl = $diagramData['tpl'];
		$user = $diagramData['user'];

		if ($templateId > 0 && $tpl === null)
		{
			// Same anti-oracle guard as getTemplateData: a missing record must not reveal its
			// (non-)existence to a non-admin CreateWorkflow author, who otherwise passes the
			// document-type check in prepareDiagramData.
			if (!$user->isAdmin())
			{
				$this->addError(ErrorMessage::ACCESS_DENIED->getCodedError());

				return null;
			}

			$this->addError(ErrorMessage::TEMPLATE_NOT_FOUND->getCodedError(['#ID#' => $templateId]));

			return null;
		}

		$isLegacyType = $tpl !== null
			&& $tpl->getType() !== Api\Enum\Template\WorkflowTemplateType::Nodes->value;

		// Publish = activation of the live Nodes template; a non-Nodes row is outside the template ACL and
		// keeps the legacy document-type gate. Both authorize against the document type of the template
		// itself, and both run before the type check, so a template the user may not touch always answers
		// with a uniform ACCESS_DENIED (no existence/type oracle, no cross-type overwrite).
		$canPublish = $isLegacyType
			? $this->canWriteTemplate($tpl, $user)
			: $this->accessService()->canPublish($templateId, $user->getId(), $diagramData['documentType']);

		// Publishing with no template id creates one, and a scope check answers "yes" for an id that does not
		// exist yet, so it cannot stand in for the CREATE toggler the regular create path requires.
		if ($templateId === 0)
		{
			$canPublish = $canPublish
				&& $this->accessService()->canCreate($user->getId(), $diagramData['documentType']);
		}

		if (!$canPublish)
		{
			$this->addError(ErrorMessage::ACCESS_DENIED->getCodedError());

			return null;
		}

		if ($isLegacyType)
		{
			$this->addError(
				new Error(Loc::getMessage('BIZPROCDESIGNER_CONTROLLER_DIAGRAM_ERROR_TEMPLATE_TYPE')),
			);

			return null;
		}

		// A publication to an audience is a write path of its own and never saves the live row: the common
		// version stays as it is, and the pilot version is stored beside it.
		if ($diagramData['publishMode'] === self::PUBLISH_MODE_USER)
		{
			return $this->publishPilot($templateId, $diagramData, $user);
		}

		$pilotState = $this->pilotState($templateId);
		if (
			$pilotState->hasPilot
			|| $diagramData['pilotId'] > 0
			|| $diagramData['revision'] !== ''
			|| self::isConfirmed($diagramData['confirmations'], self::CONFIRMATION_PILOT_STOP)
		)
		{
			return $this->publishPilotToEveryone($templateId, $diagramData, $user);
		}

		$published = $this->saveTemplate(
			$templateId,
			$this->normalizeSetupConstantsInFields($diagramData['fields']),
			$user,
		);

		if ($published === null)
		{
			return null;
		}

		$published['pilot'] = null;

		return $published;
	}

	/**
	 * Promotes the exact pilot version the publisher confirmed. The identity and revision come from the
	 * pilot state shown by the editor, so a replaced pilot cannot be removed by an old tab.
	 */
	private function publishPilotToEveryone(
		int $templateId,
		array $diagramData,
		\CBPWorkflowTemplateUser $user,
	): ?array
	{
		if (!self::isConfirmed($diagramData['confirmations'], self::CONFIRMATION_PILOT_STOP))
		{
			$this->addError(
				new Error(
					Loc::getMessage('BIZPROCDESIGNER_CONTROLLER_DIAGRAM_PILOT_STOP_CONFIRMATION'),
					self::ERROR_CONFIRMATION_REQUIRED,
				),
			);

			return ['required' => [self::CONFIRMATION_PILOT_STOP]];
		}

		$published = (new PilotPublicationService())->publishToEveryone(
			$templateId,
			$user->getId(),
			$diagramData['pilotId'],
			$diagramData['revision'],
		);
		if (!$published->isSuccess())
		{
			return $this->refusePilotOperation($published);
		}

		return ['templateId' => $templateId, 'pilot' => null];
	}

	public function publicateDraftAction(): ?array
	{
		$diagramData = $this->prepareDiagramData();

		if ($diagramData === null)
		{
			return null;
		}

		$templateId = $diagramData['templateId'];
		$tpl = $diagramData['tpl'];
		$user = $diagramData['user'];

		if ($templateId > 0 && $tpl === null)
		{
			// Same anti-oracle guard as getTemplateData: a missing record must not reveal its
			// (non-)existence to a non-admin CreateWorkflow author, who otherwise passes the
			// document-type check in prepareDiagramData.
			if (!$user->isAdmin())
			{
				$this->addError(ErrorMessage::ACCESS_DENIED->getCodedError());

				return null;
			}

			$this->addError(ErrorMessage::TEMPLATE_NOT_FOUND->getCodedError(['#ID#' => $templateId]));

			return null;
		}

		$isLegacyType = $tpl !== null
			&& $tpl->getType() !== Api\Enum\Template\WorkflowTemplateType::Nodes->value;

		// Draft save = editing; strictly weaker than publish. Same layering as publicateAction: the ACL for
		// Nodes, the legacy document-type gate for the rest, both before the type check.
		$canEdit = $isLegacyType
			? $this->canWriteTemplate($tpl, $user)
			: $this->accessService()->canEdit($templateId, $user->getId(), $diagramData['documentType']);

		// Publishing with no template id creates one, and a scope check answers "yes" for an id that does not
		// exist yet, so it cannot stand in for the CREATE toggler the regular create path requires.
		if ($templateId === 0)
		{
			$canEdit = $canEdit
				&& $this->accessService()->canCreate($user->getId(), $diagramData['documentType']);
		}

		if (!$canEdit)
		{
			$this->addError(ErrorMessage::ACCESS_DENIED->getCodedError());

			return null;
		}

		if ($isLegacyType)
		{
			return ['templateDraftId' => 0];
		}

		return $this->saveTemplateDraft(
			$templateId,
			$this->normalizeSetupConstantsInFields($diagramData['fields']),
			$user,
			$diagramData['draftId'],
		);
	}

	public function updateTemplateAction(int $templateId, array $data): ?array
	{
		$tpl = $templateId > 0 ? $this->getTpl($templateId) : null;

		if ($tpl && $tpl->getType() === Api\Enum\Template\WorkflowTemplateType::Nodes->value)
		{
			// Mutating the live Nodes row counts as publish for Nodes templates.
			if (!$this->accessService()->canPublish($templateId, null, $tpl->getDocumentComplexType()))
			{
				$this->addError(ErrorMessage::ACCESS_DENIED->getCodedError());

				return null;
			}
		}
		else
		{
			// Non-Nodes templates keep the legacy admin gate.
			$user = new \CBPWorkflowTemplateUser(\CBPWorkflowTemplateUser::CurrentUser);
			if (!$user->isAdmin())
			{
				$this->addError(ErrorMessage::ACCESS_DENIED->getCodedError());

				return null;
			}
		}

		// The editor only edits user-facing metadata. Service fields (TYPE, SYSTEM_CODE, DOCUMENT_TYPE) must
		// never be writable here: a PUBLISH holder could otherwise move the row out of the Nodes ACL.
		$data = array_intersect_key($data, array_flip(['NAME', 'DESCRIPTION']));
		$currentValues = $tpl?->collectValues() ?? [];
		foreach ($data as $field => $value)
		{
			if (($currentValues[$field] ?? null) === $value)
			{
				unset($data[$field]);
			}
		}
		if (!$data)
		{
			return [];
		}

		$modified = new DateTime();
		$currentModified = $currentValues['MODIFIED'] ?? null;
		if ($currentModified instanceof DateTime && $modified->getTimestamp() <= $currentModified->getTimestamp())
		{
			$modified = DateTime::createFromTimestamp($currentModified->getTimestamp() + 1);
		}
		$data['MODIFIED'] = $modified;

		$result = (new UpdateWorkflowTemplateCommand($templateId, $data))->run();
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $result->getData();
	}

	/**
	 * The audience of the live pilot as the publisher has to see it: an element that has been deleted or has
	 * become unavailable is marked instead of being dropped, so it can be recognized and replaced.
	 *
	 * @return array{audience: list<array>, publishedBy: int|null, publishedByName: string|null,
	 *     publishedAt: string|null}|null
	 */
	public function getPilotAudienceAction(int $templateId): ?array
	{
		$user = $this->authorizePilotTemplate($templateId);
		if ($user === null)
		{
			return null;
		}

		$audience = (new PilotPublicationService())->getAudience($templateId, $user->getId());
		if (!$audience->isSuccess())
		{
			return $this->refusePilotOperation($audience);
		}

		$state = $this->pilotState($templateId);

		return [
			'audience' => array_map(
				static fn(array $item): array => self::describeAudienceItem($item),
				$audience->getData()['items'] ?? [],
			),
			'publishedBy' => $state->publishedBy,
			'publishedByName' => self::formatPilotAuthorById($state->publishedBy),
			'publishedAt' => $state->publishedAt?->format(\DateTimeInterface::ATOM),
		];
	}

	/**
	 * Replaces the audience of the live pilot. The scheme is not published again and the canvas does not
	 * change: who sees the version is not what the version is.
	 *
	 * @param string[] $audience
	 */
	public function changePilotAudienceAction(int $templateId, int $pilotId, array $audience): ?array
	{
		$user = $this->authorizePilotTemplate($templateId);
		if ($user === null)
		{
			return null;
		}

		$changed = (new PilotPublicationService())
			->changeAudience($templateId, self::normalizeAudience($audience), $user->getId(), $pilotId)
		;

		if (!$changed->isSuccess())
		{
			return $this->refusePilotOperation($changed);
		}

		return [
			'pilot' => $this->buildPilotState(
				$this->pilotState($templateId),
				canPublish: true,
				isCanvasPilot: !$this->hasTemplateDraft($templateId),
			),
		];
	}

	/**
	 * Stops the pilot. Every consequence the publisher has to know about is reported at once: the answer
	 * replaces the whole set of the confirmations on the client, so a consequence named a round trip later
	 * would never be confirmed.
	 *
	 * @param string[] $confirmations
	 * @return array{draftSaved: bool}|array{required: string[]}|null
	 */
	public function stopPilotAction(int $templateId, int $pilotId, array $confirmations = []): ?array
	{
		$user = $this->authorizePilotTemplate($templateId);
		if ($user === null)
		{
			return null;
		}

		if (!(new PilotEditorStateService())->hasPilot($templateId))
		{
			$this->addError(
				new Error(
					Loc::getMessage('BIZPROCDESIGNER_CONTROLLER_DIAGRAM_PILOT_NOT_FOUND'),
					PilotPublicationService::ERROR_PILOT_NOT_FOUND,
				),
			);

			return null;
		}

		$required = $this->findRequiredStopConfirmations($templateId, $confirmations);
		if ($required)
		{
			$this->addError(
				new Error(
					Loc::getMessage('BIZPROCDESIGNER_CONTROLLER_DIAGRAM_PILOT_STOP_CONFIRMATION'),
					self::ERROR_CONFIRMATION_REQUIRED,
				),
			);

			return ['required' => $required];
		}

		$stopped = (new PilotPublicationService())->stopPilot(
			$templateId,
			$user->getId(),
			$pilotId,
			self::isConfirmed($confirmations, self::CONFIRMATION_DRAFT_OVERWRITE),
		);

		if (!$stopped->isSuccess())
		{
			return $this->refusePilotOperation($stopped);
		}

		return ['draftSaved' => (bool)($stopped->getData()['schemeSavedAsDraft'] ?? false)];
	}

	/**
	 * The publication to an audience, reached once the ordinary publish gate has passed. The feature is
	 * checked before anything is asked of the publisher: a tab opened before the feature was switched off
	 * must be refused, not led through a confirmation into a publication.
	 *
	 * @param array{fields: array, audience: string[], confirmations: string[]} $diagramData
	 */
	private function publishPilot(int $templateId, array $diagramData, \CBPWorkflowTemplateUser $user): ?array
	{
		if (!Feature::instance()->isPilotPublicationAvailable())
		{
			$this->addError(ErrorMessage::FEATURE_DISABLED->getCodedError());

			return null;
		}

		if (!$diagramData['audience'])
		{
			$this->addError(
				new Error(
					Loc::getMessage('BIZPROCDESIGNER_CONTROLLER_DIAGRAM_PILOT_AUDIENCE_EMPTY'),
					PilotPublicationService::ERROR_AUDIENCE_EMPTY,
				),
			);

			return null;
		}

		$confirmations = $diagramData['confirmations'];

		// while the template has a common version the publication freezes its settings until the pilot is
		// over, and the publisher is told that before it happens
		if (
			$this->hasCommonVersion($templateId)
			&& !self::isConfirmed($confirmations, self::CONFIRMATION_SETTINGS_FREEZE)
		)
		{
			$this->addError(
				new Error(
					Loc::getMessage('BIZPROCDESIGNER_CONTROLLER_DIAGRAM_PILOT_SETTINGS_FREEZE_CONFIRMATION'),
					self::ERROR_CONFIRMATION_REQUIRED,
				),
			);

			return ['required' => [self::CONFIRMATION_SETTINGS_FREEZE]];
		}

		$published = (new PilotPublicationService())->publish(new PilotPublicationRequest(
			templateId: $templateId,
			executableFields: self::executableFieldsOf($diagramData['fields']),
			accessCodes: $diagramData['audience'],
			publisherId: $user->getId(),
			confirmedEmptySettings: self::isConfirmed($confirmations, self::CONFIRMATION_EMPTY_CONSTANTS),
		));

		if (!$published->isSuccess())
		{
			// the blocks of the scheme a settings refusal points at travel in the body, the way an ordinary
			// publication reports its own errors
			return $this->refusePilotOperation($published, $published->getData());
		}

		return [
			'templateId' => $templateId,
			'pilot' => $this->buildPilotState(
				$this->pilotState($templateId),
				canPublish: true,
				// the publication deletes the draft, so the pilot version is what the canvas shows next
				isCanvasPilot: true,
			),
		];
	}

	/**
	 * The boundary every operation over a live pilot passes: the feature is on, the template exists and the
	 * caller may publish it. A caller who may not edit the template is answered exactly as for a template
	 * that does not exist, so the operation cannot be used to probe a foreign one.
	 */
	private function authorizePilotTemplate(int $templateId): ?\CBPWorkflowTemplateUser
	{
		$user = new \CBPWorkflowTemplateUser(\CBPWorkflowTemplateUser::CurrentUser);

		// the service refuses a write of its own, but reading the audience it answers successfully, so the
		// gate of the feature stands here for all three operations alike
		if (!Feature::instance()->isPilotPublicationAvailable())
		{
			$this->addError(ErrorMessage::FEATURE_DISABLED->getCodedError());

			return null;
		}

		$tpl = $this->getTplForAccessCheck($templateId);
		if ($tpl === null)
		{
			// Same anti-oracle guard as getTemplateData: a missing record must not reveal its
			// (non-)existence to a caller who may not have seen it anyway.
			if (!$user->isAdmin())
			{
				$this->addError(ErrorMessage::ACCESS_DENIED->getCodedError());

				return null;
			}

			$this->addError(ErrorMessage::TEMPLATE_NOT_FOUND->getCodedError(['#ID#' => $templateId]));

			return null;
		}

		if (!$this->canPublishTemplate($tpl, $user))
		{
			$this->addError(ErrorMessage::ACCESS_DENIED->getCodedError());

			return null;
		}

		return $user;
	}

	/**
	 * The same split publicateAction() makes: the template ACL for the templates of the new editor and the
	 * legacy document-type right for every other one. No right of its own is introduced for the pilot.
	 */
	private function canPublishTemplate(
		\Bitrix\Bizproc\Workflow\Template\Tpl $tpl,
		\CBPWorkflowTemplateUser $user,
	): bool
	{
		return $tpl->getType() === Api\Enum\Template\WorkflowTemplateType::Nodes->value
			? $this->accessService()->canPublish((int)$tpl->getId(), $user->getId(), $tpl->getDocumentComplexType())
			: $this->canWriteTemplate($tpl, $user)
		;
	}

	/**
	 * The state of the pilot of the template as the module of the processes reports it. While the feature
	 * is off every field of it is empty even when a pilot is stored: a stored pilot is then absent
	 * everywhere the editor looks - on the canvas, in the state it shows and in the operations it offers
	 */
	private function pilotState(int $templateId): PilotEditorState
	{
		return (new PilotEditorStateService())->getEditorState($templateId);
	}

	/**
	 * The state of the pilot as the editor shows it.
	 *
	 * @param bool $canPublish the author, the date and the size of the audience belong to the right of
	 * publication and to nothing weaker
	 * @return array{hasPilot: bool, pilotId: int|null, revision?: string, isCanvasPilot: bool,
	 *     publishedBy: int|null, publishedByName: string|null,
	 *     publishedAt: string|null, audienceCount: int|null, hasCommonVersion: bool, settingsFrozen: bool}
	 */
	private function buildPilotState(PilotEditorState $state, bool $canPublish, bool $isCanvasPilot): array
	{
		$isVisibleToPublisher = $state->hasPilot && $canPublish;

		$pilot = [
			'hasPilot' => $state->hasPilot,
			'pilotId' => $isVisibleToPublisher ? $state->pilotId : null,
			'isCanvasPilot' => $state->hasPilot && $isCanvasPilot,
			'publishedBy' => $isVisibleToPublisher ? $state->publishedBy : null,
			'publishedByName' => $isVisibleToPublisher ? self::formatPilotAuthorById($state->publishedBy) : null,
			'publishedAt' => $isVisibleToPublisher ? $state->publishedAt?->format(\DateTimeInterface::ATOM) : null,
			'audienceCount' => $isVisibleToPublisher ? $state->audienceCount : null,
			'hasCommonVersion' => $state->hasCommonVersion,
			'settingsFrozen' => $state->settingsFrozen,
		];

		if ($isVisibleToPublisher && $state->executableFields !== null)
		{
			$scheme = $state->executableFields['TEMPLATE'] ?? [];
			$pilot['revision'] = (new TemplateRevisionService())
				->calculateRevision(is_array($scheme) ? $scheme : [])
			;
		}

		return $pilot;
	}

	/**
	 * What the publisher has to confirm before the pilot is stopped. Both consequences are asked about in
	 * one answer, because the client replaces the whole set of the confirmations with the one named here.
	 *
	 * @param string[] $confirmations
	 * @return string[]
	 */
	private function findRequiredStopConfirmations(int $templateId, array $confirmations): array
	{
		$required = [];

		// with no common version the template stops being available for a manual start to everyone, the
		// former participants of the pilot included
		if (
			!$this->hasCommonVersion($templateId)
			&& !self::isConfirmed($confirmations, self::CONFIRMATION_NO_COMMON_VERSION)
		)
		{
			$required[] = self::CONFIRMATION_NO_COMMON_VERSION;
		}

		// a draft of the template is never overwritten: the pilot scheme is deleted instead, and that is
		// what the publisher confirms
		if (
			$this->hasTemplateDraft($templateId)
			&& !self::isConfirmed($confirmations, self::CONFIRMATION_DRAFT_OVERWRITE)
		)
		{
			$required[] = self::CONFIRMATION_DRAFT_OVERWRITE;
		}

		return $required;
	}

	/**
	 * Turns a refusal of the pilot service into the refusal of the editor API: the codes of the service are
	 * mapped onto the codes of the contract, a refusal that may be confirmed becomes CONFIRMATION_REQUIRED
	 * carrying the codes to confirm, and everything the service attached to the error is kept as it is.
	 *
	 * @param array $data what travels in the body of the refusal beside the errors
	 */
	private function refusePilotOperation(Result $result, array $data = []): ?array
	{
		$required = [];

		foreach ($result->getErrors() as $error)
		{
			$code = (string)$error->getCode();
			$confirmation = self::confirmationOfRefusal($code);

			if ($confirmation !== null)
			{
				$required[] = $confirmation;
				$this->addError(
					new Error($error->getMessage(), self::ERROR_CONFIRMATION_REQUIRED, $error->getCustomData()),
				);

				continue;
			}

			$contractCode = self::contractErrorCode($code);
			$this->addError(
				$contractCode === $code
					? $error
					: new Error($error->getMessage(), $contractCode, $error->getCustomData()),
			);
		}

		if ($required)
		{
			$data['required'] = array_values(array_unique($required));
		}

		return $data ?: null;
	}

	/**
	 * The confirmation a refusal asks for, and null for a refusal that cannot be confirmed away.
	 */
	private static function confirmationOfRefusal(string $code): ?string
	{
		return match ($code)
		{
			PilotPublicationService::ERROR_SETTINGS_FILL_AFTER_PUBLICATION => self::CONFIRMATION_EMPTY_CONSTANTS,
			PilotPublicationService::ERROR_DRAFT_EXISTS => self::CONFIRMATION_DRAFT_OVERWRITE,
			default => null,
		};
	}

	/**
	 * Only the codes of the service that differ from the contract of the editor are listed. TEMPLATE_NOT_FOUND
	 * reveals nothing here: it is reachable only for a caller who has just passed the publication gate on
	 * that very template, and it is what tells the editor the template was deleted mid-session.
	 */
	private static function contractErrorCode(string $code): string
	{
		return match ($code)
		{
			PilotPublicationService::ERROR_TEMPLATE_NOT_FOUND => ErrorMessage::TEMPLATE_NOT_FOUND->value,
			PilotPublicationService::ERROR_ACCESS_DENIED => ErrorMessage::ACCESS_DENIED->value,
			PilotPublicationService::ERROR_FEATURE_DISABLED => ErrorMessage::FEATURE_DISABLED->value,
			PilotPublicationService::ERROR_SETTINGS_NOT_FILLED => self::ERROR_PILOT_CONSTANTS_EMPTY,
			default => $code,
		};
	}

	/**
	 * @param array{accessCode: string, name: string, isAvailable: bool} $item
	 * @return array{accessCode: string, entityType: string, entityId: int, title: string, available: bool}
	 */
	private static function describeAudienceItem(array $item): array
	{
		$accessCode = (string)$item['accessCode'];
		$parsed = new AccessCode($accessCode);

		return [
			'accessCode' => $accessCode,
			'entityType' => $parsed->getEntityType(),
			'entityId' => $parsed->getEntityId(),
			'title' => (string)($item['name'] ?? ''),
			'available' => (bool)($item['isAvailable'] ?? false),
		];
	}

	/**
	 * The name of the publisher as the portal writes names: the format is a setting of the site, so it is
	 * asked of the platform instead of being assembled from the fields. A user who is no longer on the
	 * portal has no name to show, and the answer keeps only the id.
	 */
	private static function formatPilotAuthorById(?int $userId): ?string
	{
		return $userId === null ? null : UserView::createFromId($userId)?->getFullName();
	}

	/**
	 * @param string[] $confirmations
	 */
	private static function isConfirmed(array $confirmations, string $confirmation): bool
	{
		return in_array($confirmation, $confirmations, true);
	}

	private function hasCommonVersion(int $templateId): bool
	{
		return (new PilotEditorStateService())->hasCommonVersion($templateId);
	}

	private function hasTemplateDraft(int $templateId): bool
	{
		$draft = WorkflowTemplateDraftTable::query()
			->setSelect(['ID'])
			->where('TEMPLATE_ID', $templateId)
			->setLimit(1)
			->fetch()
		;

		return $draft !== false;
	}

	/**
	 * @return array{TEMPLATE: array, PARAMETERS: array, VARIABLES: array, CONSTANTS: array}
	 */
	private static function executableFieldsOf(array $fields): array
	{
		$executableFields = [];
		foreach (self::EXECUTABLE_FIELDS as $field)
		{
			$executableFields[$field] = (array)($fields[$field] ?? []);
		}

		return $executableFields;
	}

	/**
	 * @param string[] $audience
	 * @return string[]
	 */
	private static function normalizeAudience(array $audience): array
	{
		$accessCodes = [];
		foreach ($audience as $accessCode)
		{
			$accessCode = trim((string)$accessCode);
			if ($accessCode !== '')
			{
				$accessCodes[$accessCode] = $accessCode;
			}
		}

		return array_values($accessCodes);
	}

	/**
	 * @param string[] $confirmations
	 * @return string[]
	 */
	private static function normalizeConfirmations(array $confirmations): array
	{
		return array_values(array_unique(array_map('strval', $confirmations)));
	}

	/**
	 * Null for a mode the contract does not define. An unknown mode is not taken for the default one:
	 * publishing to the whole company what was meant for five people is exactly what the mode exists to
	 * prevent, so a request that cannot be understood publishes nothing at all.
	 */
	private static function validatePublishMode(mixed $publishMode): ?string
	{
		if ($publishMode === null || $publishMode === '')
		{
			return self::PUBLISH_MODE_MAIN;
		}

		return in_array($publishMode, [self::PUBLISH_MODE_MAIN, self::PUBLISH_MODE_USER], true)
			? $publishMode
			: null
		;
	}

	/**
	 * @throws LoaderException
	 * @throws ArgumentException
	 * @throws CircularDependencyException
	 * @throws ObjectNotFoundException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function connectAgentAction(int $templateId): ?array
	{
		$userId = $this->authorizeAgentTemplate($templateId);
		if ($userId === null)
		{
			return null;
		}

		$webhookResult = (new AgentWebhookService())->ensureWebhookUrl($userId, $templateId);

		if (!$webhookResult->isSuccess())
		{
			$this->addErrors($webhookResult->getErrors());

			return null;
		}

		$data = $webhookResult->getData();

		return $this->buildConnectResponse(
			$data['url'],
			['connected' => $data['connected'], 'expiresAt' => $data['expiresAt']],
		);
	}

	/**
	 * @throws LoaderException
	 * @throws ArgumentException
	 * @throws CircularDependencyException
	 * @throws ObjectNotFoundException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function revokeAgentAction(int $templateId): ?array
	{
		$userId = $this->authorizeAgentTemplate($templateId);
		if ($userId === null)
		{
			return null;
		}

		$revokeResult = (new AgentWebhookService())->revoke($userId, $templateId);

		if (!$revokeResult->isSuccess())
		{
			$this->addErrors($revokeResult->getErrors());

			return null;
		}

		return [
			'connected' => false,
			'expiresAt' => null,
		];
	}

	/**
	 * @throws LoaderException
	 * @throws ArgumentException
	 * @throws CircularDependencyException
	 * @throws ObjectNotFoundException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function regenerateAgentAction(int $templateId): ?array
	{
		$userId = $this->authorizeAgentTemplate($templateId);
		if ($userId === null)
		{
			return null;
		}

		$regenerateResult = (new AgentWebhookService())->regenerate($userId, $templateId);

		if (!$regenerateResult->isSuccess())
		{
			$this->addErrors($regenerateResult->getErrors());

			return null;
		}

		$data = $regenerateResult->getData();

		return $this->buildConnectResponse(
			$data['url'],
			['connected' => $data['connected'], 'expiresAt' => $data['expiresAt']],
		);
	}

	/**
	 * @throws LoaderException
	 * @throws ArgumentException
	 * @throws CircularDependencyException
	 * @throws ObjectNotFoundException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function agentStatusAction(int $templateId): ?array
	{
		$userId = $this->authorizeAgentTemplate($templateId);
		if ($userId === null)
		{
			return null;
		}

		return (new AgentWebhookService())->getStatus($userId, $templateId);
	}

	/**
	 * Shared access boundary for every agent-token action: refuses when the external AI agent feature
	 * is not accessible (rollout flag off, or AI tariff / region unavailable), then resolves the
	 * template for the current user and checks manage rights through the type-aware document guard
	 * (a real document type requires CreateWorkflow; the WORKFLOW pseudo type requires admin, or the
	 * owner of a started launched copy). Returns the userId on success, or null after registering the
	 * matching error.
	 *
	 * @throws ArgumentException
	 * @throws CircularDependencyException
	 * @throws ObjectNotFoundException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function authorizeAgentTemplate(int $templateId): ?int
	{
		if (!Feature::instance()->isExternalAiAgentAccessible())
		{
			$this->addError(ErrorMessage::ACCESS_DENIED->getError());

			return null;
		}

		$userId = (int)CurrentUser::get()->getId();
		$identifier = Container::getAgentWorkflowResolverService()->resolveByTemplateId($templateId, $userId);

		if ($identifier === null)
		{
			$this->addError(ErrorMessage::TEMPLATE_NOT_FOUND->getError(['#ID#' => $templateId]));

			return null;
		}

		$isUserAdmin = (new \CBPWorkflowTemplateUser(\CBPWorkflowTemplateUser::CurrentUser))->isAdmin();
		if (!(new DocumentAccessService())->canManageDocument($userId, $identifier->documentDescription, $templateId, $isUserAdmin))
		{
			$this->addError(ErrorMessage::ACCESS_DENIED->getError());

			return null;
		}

		return $userId;
	}

	/**
	 * Builds the connect/regenerate response payload from the base webhook URL and the token status.
	 * The /rest/api/ prefix is added here: the controller is the single owner of it; the
	 * webhook URL from the service has /rest/<userId>/<token>.
	 *
	 * The url carries no template identifier: agent.connect answers for the template its token is bound
	 * to and takes no argument at all.
	 *
	 * @param array{connected: bool, expiresAt: int|null} $status
	 *
	 * @return array{connectUrl: string, commands: array{claude: string, codex: string, universal: string}, expiresAt: int|null, connected: bool}
	 */
	private function buildConnectResponse(string $baseUrl, array $status): array
	{
		$base = rtrim($baseUrl, '/');
		$v3Base = preg_replace('#/rest/#', '/rest/api/', $base, 1);
		$connectUrl = $v3Base . '/bizprocdesigner.agent.connect';

		return [
			'connectUrl' => $connectUrl,
			'commands' => [
				'claude' => Loc::getMessage('BIZPROCDESIGNER_CONTROLLER_DIAGRAM_CONNECT_AGENT_CLAUDE', ['#URL#' => $connectUrl]),
				'codex' => Loc::getMessage('BIZPROCDESIGNER_CONTROLLER_DIAGRAM_CONNECT_AGENT_CODEX', ['#URL#' => $connectUrl]),
				'universal' => Loc::getMessage('BIZPROCDESIGNER_CONTROLLER_DIAGRAM_CONNECT_AGENT_UNIVERSAL', ['#URL#' => $connectUrl]),
			],
			'expiresAt' => $status['expiresAt'],
			'connected' => $status['connected'],
		];
	}

	private function getTemplateData(int $id, ?array $newDocumentType, ?string $startTrigger = null): ?array
	{
		$tpl = $id > 0 ? $this->getTpl($id) : null;
		$user = new \CBPWorkflowTemplateUser(\CBPWorkflowTemplateUser::CurrentUser);

		if ($id > 0 && $tpl === null)
		{
			// A missing record has no valid document type to authorize against, so it is gated by the
			// document-type-independent admin check. Existence must not leak to non-admins: they get a
			// uniform ACCESS_DENIED just like for a foreign template.
			if (!$user->isAdmin())
			{
				$this->addError(ErrorMessage::ACCESS_DENIED->getCodedError());

				return null;
			}

			$this->addError(ErrorMessage::TEMPLATE_NOT_FOUND->getCodedError(['#ID#' => $id]));

			return null;
		}

		$documentType = $tpl ? $tpl->getDocumentComplexType() : $newDocumentType;

		// A freshly created template is always Nodes ({@see createEmptyTemplate}); only Nodes are governed
		// by the new ACL. Existing non-Nodes templates stay on the legacy document-type gate.
		$isNodes = $tpl
			? $tpl->getType() === Api\Enum\Template\WorkflowTemplateType::Nodes->value
			: true;

		if ($isNodes)
		{
			// Nodes templates belong to the template ACL end to end: the legacy document-type gate never
			// participates, neither for an existing row nor for one about to be created.
			$canWrite = $documentType && (
				$id > 0
					? $this->accessService()->canEdit($id, $user->getId(), $documentType)
					: $this->accessService()->canCreate($user->getId(), $documentType)
			);
		}
		else
		{
			$canWrite = $documentType && \CBPDocument::CanUserOperateDocumentType(
				\CBPCanUserOperateOperation::CreateWorkflow,
				$user->getId(),
				$documentType,
			);
		}

		if (!$canWrite)
		{
			$this->addError(ErrorMessage::ACCESS_DENIED->getCodedError());

			return null;
		}

		if ($id === 0 && $documentType)
		{
			$tpl = $this->createEmptyTemplate($documentType, $startTrigger);
		}

		if (!$tpl)
		{
			$this->addError(ErrorMessage::TEMPLATE_NOT_FOUND->getCodedError(['#ID#' => $id]));

			return null;
		}

		$draftId = 0;
		$draftSavedAt = null;

		if (
			$tpl->getType() !== Api\Enum\Template\WorkflowTemplateType::Nodes->value
			&& !defined('\Bitrix\Bizproc\Dev\ENV')
		)
		{
			$this->addError(
				new Error(Loc::getMessage('BIZPROCDESIGNER_CONTROLLER_DIAGRAM_ERROR_TEMPLATE_TYPE')),
			);

			return null;
		}

		$templateId = (int)$tpl->getId();
		$pilotState = $this->pilotState($templateId);

		$draftRow = WorkflowTemplateDraftTable::getLatestDraftWithDataByTemplateId($templateId);
		if ($draftRow)
		{
			$draftId = (int)$draftRow['ID'];
			// CREATED is rewritten on every draft update, so it is the last draft save time.
			$draftSavedAt = $draftRow['CREATED']?->getTimestamp();
			$schemeOverlay = $draftRow['TEMPLATE_DATA'];
		}
		else
		{
			// The acting scheme of a template running a pilot is its pilot version: the publisher edits what
			// the audience of the pilot really runs. The layer of comparison below stays the common
			// version, so the difference from what everyone else runs stays visible.
			$schemeOverlay = $pilotState->executableFields ?? [];
		}

		$tplData = $schemeOverlay['TEMPLATE'] ?? $tpl->getTemplate();
		$trackOn = (int)\Bitrix\Main\Config\Option::get('bizproc', 'tpl_track_on_' . $id, 0);

		$complexNodeConnectionsAvailable = Feature::instance()->areComplexNodeConnectionsAvailable();
		[$blocks, $connections] = (new TemplateToNodes($tplData, $complexNodeConnectionsAvailable))->convert();
		[$publishedBlocks, $publishedConnection] =
			(new TemplateToNodes($tpl->getTemplate(), $complexNodeConnectionsAvailable))->convert()
		;

		// The settings the scheme on the canvas declares follow that scheme: a pilot version may declare a
		// parameter the live row does not know about, and republishing it from the editor would drop it.
		$templateMeta = array_merge($tpl->collectValues(), ['TRACK_ON' => $trackOn]);
		$schemeOwnedMetaFields = self::SCHEME_OWNED_META_FIELDS;
		if ($draftRow && WorkflowTemplateDraftTable::hasRestoredMetadata($draftRow))
		{
			// A restored version brings back the name and description of its own time: without them
			// the editor keeps showing the published ones and the next publication drops them.
			$schemeOwnedMetaFields[] = 'NAME';
			$schemeOwnedMetaFields[] = 'DESCRIPTION';
		}

		foreach ($schemeOwnedMetaFields as $field)
		{
			if (isset($schemeOverlay[$field]))
			{
				$templateMeta[$field] = $schemeOverlay[$field];
			}
		}

		$canPublish = $this->accessService()->canPublish($tpl->getId(), $user->getId(), $documentType);

		return [
			'template' => $templateMeta,
			'templateId' => $tpl->getId(),
			'canPublish' => $canPublish,
			'draftId' => $draftId,
			'canUndoRestore' => WorkflowTemplateDraftTable::isRestoredDraft($draftRow),
			'draftSavedAt' => $draftSavedAt,
			'documentType' => $documentType,
			'documentTypeSigned' => \CBPDocument::signDocumentType($documentType),
			'blocks' => $blocks,
			'connections' => $connections,
			'publishedBlocks' => $publishedBlocks,
			'publishedConnection' => $publishedConnection,
			'pilot' => $this->buildPilotState(
				$pilotState,
				$canPublish,
				isCanvasPilot: $draftRow === null,
			),
		];
	}

	/**
	 * The row of a template the canvas has just been opened for. It is created with the stub of the
	 * converter and not with an empty scheme, so "this template has never been published for everyone" is
	 * recorded explicitly: nothing here is a publication, and the scheme alone cannot tell the two apart.
	 *
	 * @param array $documentType
	 * @param string|null $startTrigger
	 * @return \Bitrix\Bizproc\Workflow\Template\Tpl|null
	 */
	private function createEmptyTemplate(array $documentType, ?string $startTrigger = null): ?\Bitrix\Bizproc\Workflow\Template\Tpl
	{
		$tpl = $this->getDefaultTemplateFields($startTrigger);
		$user = new \CBPWorkflowTemplateUser(\CBPWorkflowTemplateUser::CurrentUser);
		$fields = $this->prepareFields($tpl, $documentType);
		$request = new Api\Request\WorkflowTemplateService\SaveTemplateRequest(
			templateId: 0,
			parameters: [],
			fields: $fields,
			user: $user,
			checkAccess: false,
		);
		$templateService = new Api\Service\WorkflowTemplateService();
		$result = $templateService->saveTemplate($request, withoutCommonVersion: true);

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $this->getTpl($result->getTemplateId());
	}

	private function getDefaultTemplateFields(?string $startTrigger = null): array
	{
		$template = [];

		$converter = new SequentialToNodeWorkflow([]);
		if ($startTrigger)
		{
			$converter->setStartTrigger($startTrigger);
		}
		$template['TEMPLATE'] = $converter->convert();

		$template['NAME'] = Loc::getMessage('BIZPROCDESIGNER_CONTROLLER_DIAGRAM_TEMPLATE_DEFAULT_TITLE');
		$template['AUTO_EXECUTE'] = \CBPDocumentEventType::None;
		$template['DESCRIPTION'] = '';
		$template['PARAMETERS'] = [];
		$template['VARIABLES'] = [];
		$template['CONSTANTS'] = [];
		$template['TEMPLATE_SETTINGS'] = [];

		return $template;
	}

	private function prepareFields(array $template, array $documentType): array
	{
		return [
			'TEMPLATE' => $template['TEMPLATE'],
			'DOCUMENT_TYPE' => $documentType,
			'NAME' => $template['NAME'],
			'DESCRIPTION' => $template['DESCRIPTION'],
			'PARAMETERS' => $template['PARAMETERS'],
			'VARIABLES' => $template['VARIABLES'],
			'CONSTANTS' => $template['CONSTANTS'],
			'TEMPLATE_SETTINGS' => $template['TEMPLATE_SETTINGS'],
			'AUTO_EXECUTE' => $template['AUTO_EXECUTE'],
		];
	}

	private function normalizeSetupConstantsInFields(array $fields): array
	{
		$fields['CONSTANTS'] = (new SetupTemplateService())->normalizeConstantsByTemplate(
			is_array($fields['TEMPLATE'] ?? null) ? $fields['TEMPLATE'] : [],
			is_array($fields['CONSTANTS'] ?? null) ? $fields['CONSTANTS'] : [],
		);

		return $fields;
	}

	/**
	 * Parses the request into diagram data. Only a not-yet-created template is authorized here, by the
	 * legacy document-type gate; for an existing one publicate/publicateDraft apply the per-operation gate
	 * (publish vs edit) to the loaded record.
	 *
	 * @return array{templateId: int, fields: array, user: \CBPWorkflowTemplateUser, draftId: int,
	 *     documentType: array, tpl: ?\Bitrix\Bizproc\Workflow\Template\Tpl, publishMode: string,
	 *     audience: string[], confirmations: string[], pilotId: int, revision: string}|null
	 */
	private function prepareDiagramData(): ?array
	{
		$user = new \CBPWorkflowTemplateUser(\CBPWorkflowTemplateUser::CurrentUser);

		$json = Application::getInstance()->getContext()->getRequest()->getJsonList();
		$templateId = (int)$json->get('templateId');
		$documentType = \CBPDocument::unSignDocumentType($json->get('documentTypeSigned'));

		$tpl = $templateId > 0 ? $this->getTpl($templateId) : null;

		if ($tpl)
		{
			// The document type of a live template belongs to its row, not to the request. A valid signature
			// only proves the type exists, not that this template may be moved onto it: otherwise a PUBLISH
			// holder could rebind the template to a foreign entity and autostart it there. Who may write the
			// row is decided per operation by the caller.
			$documentType = $tpl->getDocumentComplexType();
			$canWrite = !empty($documentType);
		}
		else
		{
			// This controller only ever creates Nodes templates, and those are governed by the template ACL
			// alone — never by the legacy document-type gate. The caller applies it (CREATE for a new row).
			$canWrite = !empty($documentType);
		}

		if (!$canWrite)
		{
			$this->addError(ErrorMessage::ACCESS_DENIED->getCodedError());

			return null;
		}

		$blocks = (array)$json->get('blocks');
		$connections = (array)$json->get('connections');
		$converter = new NodesToTemplate($blocks, $connections);

		$template = $json->get('template');
		$template['TEMPLATE'] = $converter->convert();
		$fields = $this->prepareFields($template, $documentType);

		$draftId = (int)$json->get('draftId');

		// The mode of the publication and its audience are read here but never enter the fields of the
		// template: the fields carry a strict allowlist, and the audience is an entity of its own, not a
		// column of the row.
		$publishMode = self::validatePublishMode($json->get('publishMode'));
		if ($publishMode === null)
		{
			$this->addError(ErrorMessage::INVALID_PARAM_ARG->getCodedError([
				'#PARAM#' => 'publishMode',
				'#VALUE#' => (string)$json->get('publishMode'),
			]));

			return null;
		}

		return [
			'templateId' => $templateId,
			'fields' => $fields,
			'user' => $user,
			'draftId' => $draftId,
			'documentType' => $documentType,
			'tpl' => $tpl,
			'publishMode' => $publishMode,
			'audience' => self::normalizeAudience((array)$json->get('audience')),
			'confirmations' => self::normalizeConfirmations((array)$json->get('confirmations')),
			'pilotId' => (int)$json->get('pilotId'),
			'revision' => (string)$json->get('revision'),
		];
	}

	private function canWriteTemplate(
		\Bitrix\Bizproc\Workflow\Template\Tpl $tpl,
		\CBPWorkflowTemplateUser $user,
	): bool
	{
		$documentType = $tpl->getDocumentComplexType();

		return (bool)$documentType && \CBPDocument::CanUserOperateDocumentType(
			\CBPCanUserOperateOperation::CreateWorkflow,
			$user->getId(),
			$documentType,
		);
	}

	private function saveTemplate(
		int $templateId,
		array $fields,
		\CBPWorkflowTemplateUser $user,
	): ?array
	{
		$templateService = new Api\Service\WorkflowTemplateService();
		$request = new Api\Request\WorkflowTemplateService\SaveTemplateRequest(
			$templateId,
			[],
			$fields,
			$user,
			false,
		);
		$result = $templateService->saveTemplate($request);

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());
		}

		return $result->getData();
	}

	private function saveTemplateDraft(
		int $templateId,
		array $fields,
		\CBPWorkflowTemplateUser $user,
		int $draftId,
	): array
	{
		$request = new Api\Request\WorkflowTemplateService\SaveTemplateDraftRequest(
			$templateId,
			[],
			$fields,
			$user,
			false,
			$draftId,
		);

		$templateService = new Api\Service\WorkflowTemplateService();
		$result = $templateService->saveTemplateDraft($request);

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());
		}

		return $result->getData();
	}

	/**
	 * @param int $id
	 * @param array $select fields to read; a caller that does not show the graph of the published template
	 * asks for its own narrow set instead of the whole record
	 * @return \Bitrix\Bizproc\Workflow\Template\Tpl
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	private function getTpl(int $id, array $select = ['*', 'TEMPLATE_SETTINGS']): ?\Bitrix\Bizproc\Workflow\Template\Tpl
	{
		$tpl = WorkflowTemplateTable::query()
			->where('ID', $id)
			->whereNull('SYSTEM_CODE')
			->setSelect($select)
			->setLimit(1)
			->exec()
			->fetchObject()
		;

		return $tpl;
	}

	/**
	 * The same record and the same system-template filter as getTpl(), but only the fields the access
	 * gate asks about: opening a read-only surface must not pull the serialized template body and its
	 * drafts.
	 *
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	private function getTplForAccessCheck(int $id): ?\Bitrix\Bizproc\Workflow\Template\Tpl
	{
		return $this->getTpl($id, ['ID', 'TYPE', 'MODULE_ID', 'ENTITY', 'DOCUMENT_TYPE']);
	}

	private function validateStartTrigger(?string $startTrigger): ?string
	{
		if (is_null($startTrigger))
		{
			return null;
		}

		return StartTrigger::tryFrom($startTrigger)?->value;
	}
}

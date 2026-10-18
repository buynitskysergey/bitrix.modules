<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\DocumentHeader;

use Bitrix\Disk\Configuration;
use Bitrix\Disk\document\SharingControlType;
use Bitrix\Disk\Document\BoardsHandler;
use Bitrix\Disk\Document\DocumentHandler;
use Bitrix\Disk\Document\DocumentHandlersManager;
use Bitrix\Disk\Document\LocalDocumentController;
use Bitrix\Disk\Document\Models;
use Bitrix\Disk\Document\OnlyOffice\OnlyOfficeHandler;
use Bitrix\Disk\Document\Vibeoffice\VibeofficeHandler;
use Bitrix\Disk\Driver;
use Bitrix\Disk\ExternalLink;
use Bitrix\Disk\File;
use Bitrix\Disk\Integration\Baas\BaasSessionBoostService;
use Bitrix\Disk\Integration\Bitrix24Manager;
use Bitrix\Disk\Internal\Access\UnifiedLink\UnifiedLinkAccessLevel;
use Bitrix\Disk\Internal\Enum\DocumentHeaderElement;
use Bitrix\Disk\Internal\Service\UnifiedLink\UnifiedLinkAccessService;
use Bitrix\Disk\Security\SecurityContext;
use Bitrix\Disk\UrlManager;
use Bitrix\Disk\User;
use Bitrix\Intranet\Util;
use Bitrix\Main\Application;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;

/**
 * Single place deciding which elements the header of a concrete opening consists of (TBL-01, ALG-01).
 *
 * Neither cached nor caching: the composition depends on the rights of the current user and on the
 * mode the object was opened in.
 */
class HeaderCompositionService
{
	public const ACCESS_TYPE_INTERNAL = 'internal';
	public const ACCESS_TYPE_UNIFIED = 'unified';

	public const ERROR_OBJECT_NOT_FOUND = 'OBJECT_NOT_FOUND';
	public const ERROR_ACCESS_DENIED = 'ACCESS_DENIED';

	/**
	 * The boost service is asked for and not handed over: its constructor resolves the services of BaaS and
	 * the tariff group, while a board and the presence action never ask about the Boost element at all.
	 */
	public function __construct(
		private readonly UnifiedLinkAccessService $unifiedLinkAccessService,
		private readonly UrlManager $urlManager,
		private ?BaasSessionBoostService $sessionBoostService = null,
	)
	{
	}

	/**
	 * @param string $accessType self::ACCESS_TYPE_INTERNAL | self::ACCESS_TYPE_UNIFIED
	 * @return Result data key `composition` holds {@see HeaderCompositionDto}
	 */
	public function compose(Models\DocumentSession $documentSession, int $userId, string $accessType): Result
	{
		return $this->refuseInsteadOfThrowing(
			fn(): Result => $this->describeOpening($documentSession, $userId, $accessType),
		);
	}

	private function describeOpening(
		Models\DocumentSession $documentSession,
		int $userId,
		string $accessType,
	): Result
	{
		$result = new Result();

		$file = $documentSession->getObject();
		if (!$file)
		{
			return $result->addError($this->getObjectNotFoundError());
		}

		$securityContext = $this->getSecurityContext($file, $userId);
		$rights = $this->resolveRights($documentSession, $file, $userId, $accessType, $securityContext);
		if (!$rights->canRead())
		{
			return $result->addError($this->getAccessDeniedError());
		}

		$isBoard = $this->isBoard($documentSession);
		$isIntranetUser = $this->isIntranetUser($userId);
		$isPortalEmployee = $this->isPortalEmployee($userId, $isIntranetUser);
		$sharingControlType = $isPortalEmployee
			? $this->resolveSharingControlType($documentSession, $securityContext, $isBoard)
			: null
		;
		// A symbolic link resolves its real object to answer this, so it is asked once for the whole
		// description and handed over to everything that needs it.
		$uniqueCode = $this->getUniqueCode($file);

		$elements = $this->listElements(
			documentSession: $documentSession,
			rights: $rights,
			isBoard: $isBoard,
			isUnifiedAccess: $accessType === self::ACCESS_TYPE_UNIFIED,
			isIntranetUser: $isIntranetUser,
			isPortalEmployee: $isPortalEmployee,
			canEditSession: fn(): bool => $this->canEditSession(
				$documentSession,
				$securityContext,
				$rights,
				$accessType,
			),
			isExtensionEditable: fn(): bool => $this->isExtensionEditableByTheEngineOfTheOpening(
				$documentSession,
				$file,
			),
			hasSharingControl: $sharingControlType !== null,
			supportsUnifiedLink: $uniqueCode !== '',
		);

		return $result->setData([
			'composition' => $this->buildDto(
				$elements,
				$documentSession,
				$file,
				$accessType,
				$isBoard,
				$sharingControlType,
				$uniqueCode,
			),
		]);
	}

	/**
	 * The single question the presence action has for the header: may this user read the opening at all,
	 * and does the opening carry a roster. Both answers are the ones {@see compose()} gives, and the
	 * refusals are the same, but nothing else of the description is built on the way - no elements, no
	 * editors, no sharing control and no link.
	 *
	 * @param string $accessType self::ACCESS_TYPE_INTERNAL | self::ACCESS_TYPE_UNIFIED
	 * @return Result data key `presenceEnabled`
	 */
	public function hasPresence(Models\DocumentSession $documentSession, int $userId, string $accessType): Result
	{
		return $this->refuseInsteadOfThrowing(
			fn(): Result => $this->answerPresence($documentSession, $userId, $accessType),
		);
	}

	private function answerPresence(
		Models\DocumentSession $documentSession,
		int $userId,
		string $accessType,
	): Result
	{
		$result = new Result();

		$file = $documentSession->getObject();
		if (!$file)
		{
			return $result->addError($this->getObjectNotFoundError());
		}

		$rights = $this->resolveRights(
			$documentSession,
			$file,
			$userId,
			$accessType,
			$this->getSecurityContext($file, $userId),
		);
		if (!$rights->canRead())
		{
			return $result->addError($this->getAccessDeniedError());
		}

		return $result->setData([
			'presenceEnabled' => $this->isPresenceEnabled($this->isBoard($documentSession)),
		]);
	}

	/**
	 * A session opened through an external link is authorized by that link, not by the object rights of a
	 * portal user. A session row with an unreadable CONTEXT names no link and stays internal.
	 */
	public function resolveAccessType(Models\DocumentSession $documentSession): string
	{
		return $this->getSessionContext($documentSession)?->getExternalLinkId() === null
			? self::ACCESS_TYPE_INTERNAL
			: self::ACCESS_TYPE_UNIFIED
		;
	}

	/**
	 * The same description for the page which opens the object, so the header draws itself from the page
	 * it arrived with instead of asking for the description afterwards. How the opening is authorized is
	 * resolved here and not asked of the page: the page and the action must describe one opening alike,
	 * and a page which decided that on its own would sooner or later decide otherwise.
	 *
	 * A refusal is not raised to the page: the page keeps opening the object, and the header falls back
	 * to the action of the controller, which answers with the reason.
	 */
	public function composeForPage(Models\DocumentSession $documentSession, int $userId): ?array
	{
		try
		{
			$result = $this->compose($documentSession, $userId, $this->resolveAccessType($documentSession));
		}
		catch (\Throwable $e)
		{
			$this->logUnforeseenFailure($e);

			return null;
		}

		return $result->isSuccess() ? $result->getData()['composition']->toArray() : null;
	}

	/**
	 * Composition matrix TBL-01. The order of {@see DocumentHeaderElement::cases()} is the render order.
	 *
	 * The two questions the transition to editing costs are handed over unanswered: a board, a version and
	 * a session opened for editing decide that row without them, while the right of the session builds the
	 * context of the opening and dereferences the attached object and the external link behind it.
	 *
	 * @param \Closure(): bool $canEditSession
	 * @param \Closure(): bool $isExtensionEditable
	 * @return DocumentHeaderElement[]
	 */
	private function listElements(
		Models\DocumentSession $documentSession,
		UnifiedLinkAccessLevel $rights,
		bool $isBoard,
		bool $isUnifiedAccess,
		bool $isIntranetUser,
		bool $isPortalEmployee,
		\Closure $canEditSession,
		\Closure $isExtensionEditable,
		bool $hasSharingControl,
		bool $supportsUnifiedLink,
	): array
	{
		$elements = [];
		foreach (DocumentHeaderElement::cases() as $element)
		{
			$isIncluded = match ($element)
			{
				DocumentHeaderElement::Logo,
				DocumentHeaderElement::Name => true,

				// The rename asks for the right on the object itself (API-03), which a guest of a link
				// never has, so an opening by link keeps no rename to answer with ACCESS_DENIED. A
				// version is refused by the same action for a reason of its own - it is a frozen
				// state of the object and carries no name to change (@see RenameService::rename()).
				DocumentHeaderElement::Rename => !$isUnifiedAccess
					&& !$documentSession->isVersion()
					&& $rights->canEdit(),

				// The document is told the state of its own saving, while the board carries the same
				// element as a permanent label of its mode: a board is written by the server on the
				// webhook of its platform, so no confirmation of a save ever reaches the page.
				DocumentHeaderElement::SaveStatus => true,

				// The type of the user is one check, while the availability of the purchase resolves the
				// services of BaaS and the tariff group - so a guest and an outside visitor are told apart
				// before the portal is asked anything.
				DocumentHeaderElement::Boost => !$isBoard
					&& $isIntranetUser
					&& $this->getSessionBoostService()->isAvailable(),

				// A version is refused editing by the shell it is opened in the very same way the rename
				// is refused it: the frozen state carries no editing of its own, and the button of such an
				// opening would lead nowhere at all. An extension the engine of the opening cannot edit -
				// a pdf of the viewer above the rest - is refused by that shell in the same place
				// (@see isExtensionEditableByTheEngineOfTheOpening()).
				DocumentHeaderElement::Edit => !$isBoard
					&& !$documentSession->isVersion()
					&& $documentSession->isView()
					&& $isExtensionEditable()
					&& $canEditSession(),

				// An opening by a public link keeps the roster too: the visitor is shown
				// who else has the document open. It is the live channel of the roster that such an
				// opening never gets, not the roster itself.
				DocumentHeaderElement::Presence => $this->isPresenceEnabled($isBoard),

				// The board is asked the same question as the document. Its previous shell also drew the
				// button on the page of the unique link of the object, and that page is not told apart
				// from an ordinary one by the session: both reuse a single internal session row.
				DocumentHeaderElement::Access => $hasSharingControl,

				DocumentHeaderElement::CopyLink => $isPortalEmployee && $supportsUnifiedLink,
			};

			if ($isIncluded)
			{
				$elements[] = $element;
			}
		}

		return $elements;
	}

	/**
	 * @param DocumentHeaderElement[] $elements
	 */
	private function buildDto(
		array $elements,
		Models\DocumentSession $documentSession,
		File $file,
		string $accessType,
		bool $isBoard,
		?SharingControlType $sharingControlType,
		string $uniqueCode,
	): HeaderCompositionDto
	{
		$isView = $documentSession->isView();
		$hasElement = static fn(DocumentHeaderElement $element): bool => in_array($element, $elements, true);

		return new HeaderCompositionDto(
			elements: array_column($elements, 'value'),
			objectType: $isBoard
				? HeaderCompositionDto::OBJECT_TYPE_BOARD
				: HeaderCompositionDto::OBJECT_TYPE_DOCUMENT,
			mode: $isView ? HeaderCompositionDto::MODE_VIEW : HeaderCompositionDto::MODE_EDIT,
			objectId: (int)$file->getId(),
			objectName: (string)$file->getName(),
			objectUniqueCode: $uniqueCode,
			objectExtension: (string)$file->getExtension(),
			unifiedLinkUrl: $hasElement(DocumentHeaderElement::CopyLink)
				? $this->getUnifiedLinkUrl($documentSession, $file)
				: '',
			accessControlType: $hasElement(DocumentHeaderElement::Access)
				? (string)$sharingControlType?->value
				: '',
			// The menu of the editors is drawn by the transition to editing and by nothing else, so an
			// opening which keeps no transition is asked for no list either. An opening by link keeps the
			// bare edit button of the previous shells: the choice of another editor asks for the rights on
			// the object, which a guest of a link never has.
			editors: ($hasElement(DocumentHeaderElement::Edit) && $accessType !== self::ACCESS_TYPE_UNIFIED)
				? $this->listEditors()
				: [],
			presenceEnabled: $hasElement(DocumentHeaderElement::Presence),
			boostContainerRequired: $hasElement(DocumentHeaderElement::Boost),
		);
	}

	/**
	 * The rights of an opening, asked for the same way by the whole description and by the narrow question
	 * of {@see hasPresence()}, so the two never answer a refusal differently.
	 */
	private function resolveRights(
		Models\DocumentSession $documentSession,
		File $file,
		int $userId,
		string $accessType,
		?SecurityContext $securityContext,
	): UnifiedLinkAccessLevel
	{
		if ($accessType === self::ACCESS_TYPE_UNIFIED)
		{
			return $this->unifiedLinkAccessService->check(
				$file,
				$this->getSessionContext($documentSession)?->getAttachedObject(),
				$userId,
			);
		}

		return $this->getInternalRights($documentSession, $file, $securityContext);
	}

	/**
	 * One instance per request, shared with every other consumer of the locator.
	 */
	private function getSessionBoostService(): BaasSessionBoostService
	{
		return $this->sessionBoostService ??= ServiceLocator::getInstance()->get(BaasSessionBoostService::class);
	}

	private function isBoard(Models\DocumentSession $documentSession): bool
	{
		return $documentSession->getService() === Models\DocumentService::FlipChart;
	}

	/**
	 * The single decision behind the Presence element, so the composition matrix and the narrow answer of
	 * {@see hasPresence()} cannot drift apart. A board confirms no roster of its own.
	 */
	private function isPresenceEnabled(bool $isBoard): bool
	{
		return !$isBoard;
	}

	/**
	 * Every answer of this service is one of the refusals of the actions it serves, an unforeseen one
	 * included: the page already degrades to no description at all {@see composeForPage()}, while an
	 * action would hand the browser an unhandled exception instead of a localized refusal, and the
	 * header of such an opening would then be drawn by nothing at all.
	 *
	 * The refusal is the answer to the user, not the whole reaction: a failure turned into "no access"
	 * and nothing else would leave such a report with no trace to diagnose it by.
	 *
	 * @param callable(): Result $describe
	 */
	private function refuseInsteadOfThrowing(callable $describe): Result
	{
		try
		{
			return $describe();
		}
		catch (\Throwable $e)
		{
			$this->logUnforeseenFailure($e);

			return (new Result())->addError($this->getAccessDeniedError());
		}
	}

	protected function logUnforeseenFailure(\Throwable $e): void
	{
		Application::getInstance()->getExceptionHandler()->writeToLog($e);
	}

	private function getObjectNotFoundError(): Error
	{
		return new Error('Document session has no object.', self::ERROR_OBJECT_NOT_FOUND);
	}

	private function getAccessDeniedError(): Error
	{
		return new Error('Read access to the object is denied.', self::ERROR_ACCESS_DENIED);
	}

	protected function getSecurityContext(File $file, int $userId): ?SecurityContext
	{
		if ($userId <= 0)
		{
			return null;
		}

		return $file->getStorage()?->getSecurityContext($userId);
	}

	/**
	 * Reading is asked of the session and not of the object alone: a document attached to a task, a chat
	 * or a deal is authorized by its attached object, so a reader with no rights in a foreign storage
	 * opens it lawfully and must get a header.
	 *
	 * Editing stays on the object: the rename behind that right asks the object itself, so a right
	 * coming from the attached object would draw a button that answers with a refusal. The opening for
	 * editing is decided separately by {@see canEditSession()}.
	 */
	private function getInternalRights(
		Models\DocumentSession $documentSession,
		File $file,
		?SecurityContext $securityContext,
	): UnifiedLinkAccessLevel
	{
		if (!$securityContext)
		{
			return UnifiedLinkAccessLevel::Denied;
		}

		if ($file->canUpdate($securityContext))
		{
			return UnifiedLinkAccessLevel::Edit;
		}

		return $this->canReadSession($documentSession, $securityContext)
			? UnifiedLinkAccessLevel::Read
			: UnifiedLinkAccessLevel::Denied
		;
	}

	/**
	 * The reading of an opening is decided by the session it was created for: it answers for the
	 * attached object and for the external link behind it, not only for the object in its storage.
	 */
	protected function canReadSession(
		Models\DocumentSession $documentSession,
		SecurityContext $securityContext,
	): bool
	{
		return $documentSession->canRead($securityContext);
	}

	/**
	 * Like reading above, the session also opens editing through the attached object or the external
	 * link it was created from, so the internal branch asks the session itself.
	 *
	 * The question is asked under a guard: the reading of an opening was already decided by the time
	 * it is put, and {@see Models\DocumentSession::canEdit()} builds the context of the session with
	 * no guard of its own. A reader whose CONTEXT no model can be built from keeps the description of
	 * the opening and loses only what that context would have opened, exactly like {@see
	 * getSessionContext()} loses the attached object and the external link of such a session.
	 */
	private function canEditSession(
		Models\DocumentSession $documentSession,
		?SecurityContext $securityContext,
		UnifiedLinkAccessLevel $rights,
		string $accessType,
	): bool
	{
		if ($accessType === self::ACCESS_TYPE_UNIFIED)
		{
			return $rights->canEdit() && $this->isEditAllowedByTheLinkOfTheOpening($documentSession);
		}

		if ($securityContext === null)
		{
			return false;
		}

		try
		{
			return $documentSession->canEdit($securityContext);
		}
		catch (\Throwable)
		{
			return false;
		}
	}

	/**
	 * The engine an opening was created by edits only the extensions it knows, and the viewer of both
	 * office platforms is given documents outside that list - a pdf above the rest. Both shells ask
	 * exactly this before they hand out an address and answer with an empty one otherwise (@see
	 * \CDiskFileEditorOnlyOfficeComponent::isEditAllowed()), so a header deciding it apart would draw a
	 * live button leading nowhere.
	 *
	 * Every engine is asked its own handler: the lists of the two office platforms coincide today, but
	 * they are declared apart and belong to platforms of their own.
	 */
	private function isExtensionEditableByTheEngineOfTheOpening(
		Models\DocumentSession $documentSession,
		File $file,
	): bool
	{
		$extension = (string)$file->getExtension();

		return match ($documentSession->getService())
		{
			Models\DocumentService::OnlyOffice => OnlyOfficeHandler::isEditable($extension),
			Models\DocumentService::Vibeoffice => VibeofficeHandler::isEditable($extension),
			Models\DocumentService::FlipChart => BoardsHandler::isEditable($extension),
			// A session row of an engine that is gone names no editor to ask.
			null => false,
		};
	}

	/**
	 * The link a page was opened by is the ceiling of that page: a link given out for viewing keeps no
	 * transition to editing, whatever rights on the object the visitor holds of their own. The chain of
	 * {@see UnifiedLinkAccessService::check()} answers the other question - it takes the maximum over its
	 * links, so an employee able to change the object is granted Edit even by a viewing link - and the
	 * shells of both office platforms already decide it the way below and hand the header an empty address
	 * otherwise (@see \CDiskFileEditorOnlyOfficeComponent::isEditAllowed()).
	 *
	 * The refusal is closed: only an opening naming a link is authorized as one at all
	 * {@see resolveAccessType()}, so a link which no longer answers is a ceiling that cannot be read,
	 * not the absence of a ceiling.
	 */
	private function isEditAllowedByTheLinkOfTheOpening(Models\DocumentSession $documentSession): bool
	{
		return (bool)$this->getExternalLink($documentSession)?->allowEdit();
	}

	protected function getExternalLink(Models\DocumentSession $documentSession): ?ExternalLink
	{
		return $this->getSessionContext($documentSession)?->getExternalLink();
	}

	private function resolveSharingControlType(
		Models\DocumentSession $documentSession,
		?SecurityContext $securityContext,
		bool $isBoard,
	): ?SharingControlType
	{
		if (!$securityContext || $this->isExternalLinkSession($documentSession))
		{
			return null;
		}

		if (!$this->isSharingFeatureEnabled($isBoard))
		{
			return SharingControlType::BlockedByFeature;
		}

		if ($documentSession->canChangeRights($securityContext))
		{
			return SharingControlType::WithChangeRights;
		}

		if ($documentSession->canShare($securityContext))
		{
			return SharingControlType::WithSharing;
		}

		return SharingControlType::WithoutEdit;
	}

	/**
	 * A board is given out by the link of the object alone, so the tariff which closes its access
	 * button is the one of board links and not the one of file sharing.
	 */
	protected function isSharingFeatureEnabled(bool $isBoard): bool
	{
		if ($isBoard)
		{
			return Bitrix24Manager::isFeatureEnabled('disk_board_external_link');
		}

		return Bitrix24Manager::isFeatureEnabled('disk_file_sharing')
			|| Bitrix24Manager::isFeatureEnabled('disk_onlyoffice_edit')
		;
	}

	private function isExternalLinkSession(Models\DocumentSession $documentSession): bool
	{
		return $this->getSessionContext($documentSession)?->getExternalLinkId() !== null;
	}

	/**
	 * A session row may carry an unreadable CONTEXT; the absence of a context is not a reason to
	 * refuse the whole description, so it degrades to "no attached object, no external link".
	 */
	private function getSessionContext(Models\DocumentSession $documentSession): ?Models\DocumentSessionContext
	{
		try
		{
			return $documentSession->getContext();
		}
		catch (\Throwable)
		{
			return null;
		}
	}

	protected function isIntranetUser(int $userId): bool
	{
		return $userId > 0 && Loader::includeModule('intranet') && Util::isIntranetUser($userId);
	}

	/**
	 * The intranet answer is handed over rather than asked again: the same description already asks it for
	 * the Boost row of the matrix, and {@see Util::isIntranetUser()} promises no cache of its own.
	 */
	protected function isPortalEmployee(int $userId, bool $isIntranetUser): bool
	{
		return $isIntranetUser || $this->isCollaber($userId);
	}

	protected function isCollaber(int $userId): bool
	{
		return $userId > 0 && (bool)User::loadById($userId)?->isCollaber();
	}

	private function getUniqueCode(File $file): string
	{
		return (string)$file->getRealObject()?->getUniqueCode();
	}

	private function getUnifiedLinkUrl(Models\DocumentSession $documentSession, File $file): string
	{
		$options = ['absolute' => true];

		$attachedObjectId = (int)$this->getSessionContext($documentSession)?->getAttachedObjectId();
		if ($attachedObjectId > 0)
		{
			$options['attachedId'] = $attachedObjectId;
		}

		return $this->urlManager->getUnifiedLink($file, $options);
	}

	/**
	 * @return array<int, array{code: string, name: string, isDefault: bool}>
	 */
	private function listEditors(): array
	{
		$handlersManager = Driver::getInstance()->getDocumentHandlersManager();
		$defaultCode = (string)$handlersManager->getDefaultServiceForCurrentUser()?->getCode();

		$editors = [];
		foreach ($this->listCloudHandlers($handlersManager) as $handler)
		{
			$editors[] = [
				'code' => $handler::getCode(),
				'name' => $handler::getName(),
				'isDefault' => $handler::getCode() === $defaultCode,
			];
		}

		$editors[] = [
			'code' => LocalDocumentController::getCode(),
			'name' => LocalDocumentController::getName(),
			'isDefault' => LocalDocumentController::getCode() === $defaultCode,
		];

		return $editors;
	}

	/**
	 * @return DocumentHandler[]
	 */
	private function listCloudHandlers(DocumentHandlersManager $handlersManager): array
	{
		if (!Configuration::canCreateFileByCloud())
		{
			return [];
		}

		return array_values($handlersManager->getHandlersForCreatingFile());
	}
}

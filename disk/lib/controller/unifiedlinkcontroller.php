<?php
declare(strict_types=1);

namespace Bitrix\Disk\Controller;

use Bitrix\Disk\BaseObject;
use Bitrix\Disk\Controller\ActionFilter\RequiredParameter;
use Bitrix\Disk\File;
use Bitrix\Disk\Internal\Service\UnifiedLink\BaseObjectResolver;
use Bitrix\Disk\Internal\Service\UnifiedLink\ExternalLinkContext;
use Bitrix\Disk\Internal\Service\UnifiedLink\Render\ModeSelection;
use Bitrix\Disk\Internal\Service\UnifiedLink\Render\UnifiedLinkRenderer;
use Bitrix\Disk\TypeFile;
use Bitrix\Main\ArgumentTypeException;
use Bitrix\Disk\Infrastructure\Controller\UnifiedLink\ActionFilter\{FileTypeControl, RedirectToCorrectPrefix};
use Bitrix\Disk\Infrastructure\Controller\UnifiedLink\ActionFilter\UnifiedLinkAccessLevelRouter;
use Bitrix\Disk\Infrastructure\Controller\UnifiedLink\Attributes\{RedirectToView, UrlGenerator};
use Bitrix\Disk\Infrastructure\Controller\UnifiedLink\Attributes\FileTypes;
use Bitrix\Disk\Infrastructure\Controller\UnifiedLink\Attributes\LevelAccess;
use Bitrix\Disk\Internal\Access\UnifiedLink\UnifiedLinkAccessLevel;
use Bitrix\Disk\Public\Service\UnifiedLink;
use Bitrix\Disk\UrlManager;
use Bitrix\Disk\AttachedObject;
use Bitrix\Disk\Version;
use Bitrix\Main\Engine\ActionFilter\HttpMethod;
use Bitrix\Main\Engine\AutoWire\BinderArgumentException;
use Bitrix\Main\Engine\AutoWire\ExactParameter;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\HttpResponse;
use Bitrix\Main\Request;
use Bitrix\Main\SystemException;

class UnifiedLinkController extends Controller
{
	/** The one action of the link which names no mode of its own and lets the server choose it. */
	private const ACTION_LETTING_THE_SERVER_CHOOSE = 'view';

	private RequiredParameter $requiredParameterFilter;
	private UnifiedLinkAccessLevelRouter $accessControlFilter;
	private FileTypeControl $fileTypeFilter;
	private RedirectToCorrectPrefix $redirectToCorrectPrefix;

	public function __construct(?Request $request = null)
	{
		$this->requiredParameterFilter = new RequiredParameter('service');
		$this->fileTypeFilter = new FileTypeControl($this);
		$this->accessControlFilter = new UnifiedLinkAccessLevelRouter($this);
		$this->redirectToCorrectPrefix = new RedirectToCorrectPrefix($this);

		parent::__construct($request);
	}

	protected function getDefaultPreFilters(): array
	{
		return [
			$this->requiredParameterFilter,
			new HttpMethod([HttpMethod::METHOD_GET]),
			$this->redirectToCorrectPrefix,
			$this->fileTypeFilter,
			$this->accessControlFilter,
		];
	}

	protected function getDefaultPostFilters(): array
	{
		return [
			$this->requiredParameterFilter,
			$this->redirectToCorrectPrefix,
			$this->fileTypeFilter,
			$this->accessControlFilter,
		];
	}

	/**
	 * @return ExactParameter[]
	 * @throws BinderArgumentException
	 */
	public function getAutoWiredParameters(): array
	{
		return [
			new ExactParameter(
				UnifiedLinkRenderer::class,
				'service',
				function ($className,  string $uniqueCode): ?UnifiedLinkRenderer {
					$baseObject = BaseObject::loadByUniqueCode($uniqueCode);
					if (!$baseObject)
					{
						return null;
					}
					$attachedId = (int)$this->request->get('attachedId');
					$versionId = (int)$this->request->get('versionId');
					// The renderer binds on its own for every caller; bound here as well because the signed
					// context gate and ExternalLinkContext::resolve() answer before the renderer exists.
					$attachedObject = BaseObjectResolver::resolveAttachedObject(
						$baseObject,
						AttachedObject::loadById($attachedId),
					);
					$version = BaseObjectResolver::resolveVersion(
						$baseObject,
						Version::loadById($versionId),
					);
					$externalLink = null;
					$externalLinkContext = $this->request->getQuery(ExternalLinkContext::getParameterName());

					if ($externalLinkContext !== null)
					{
						if (
							!$baseObject instanceof File
							|| !is_string($externalLinkContext)
							|| !$this->areRequestedObjectsBound(
								$attachedId,
								$versionId,
								$attachedObject,
								$version,
							)
						)
						{
							return null;
						}

						$externalLink = ExternalLinkContext::resolve(
							$externalLinkContext,
							$baseObject,
							$attachedId,
							$versionId,
						);
						if ($externalLink === null)
						{
							return null;
						}
					}

					$analytics = $this->request->getQuery('analytics') ?? [];
					$currentUser = $this->getCurrentUser();
					$isDeferred = $this->request->get('immediate_load') !== 'Y';

					return new UnifiedLinkRenderer(
						baseObject: $baseObject,
						attachedObject: $attachedObject,
						version: $version,
						analytics: $analytics,
						currentUser: $currentUser,
						deferred: $isDeferred,
						externalLink: $externalLink,
						modeSelection: $this->resolveModeSelection(),
					);
				},
			),
		];
	}

	/** Keep the requested mode for explicit actions and addresses carrying an explicit mode choice. */
	private function resolveModeSelection(): ModeSelection
	{
		return (
			$this->runsAnActionNamingItsOwnMode()
			|| $this->request->get(UnifiedLink\UrlGenerator::QUERY_PARAM_EXACT_MODE) !== null
		)
			? ModeSelection::KeepRequested
			: ModeSelection::Auto
		;
	}

	/**
	 * Whether the running action names a mode of its own. Outside a request there is no action to ask, and
	 * then the address alone answers for the mode.
	 */
	private function runsAnActionNamingItsOwnMode(): bool
	{
		try
		{
			$actionName = $this->getCurrentAction()->getName();
		}
		catch (SystemException)
		{
			return false;
		}

		return $actionName !== self::ACTION_LETTING_THE_SERVER_CHOOSE;
	}

	/**
	 * Tells whether the requested ids survived the binding to the file. The signed context was issued for
	 * these very ids, so an attachment or a revision dropped as belonging to another object refuses the
	 * handoff instead of quietly opening the file alone.
	 */
	private function areRequestedObjectsBound(
		int $attachedId,
		int $versionId,
		?AttachedObject $attachedObject,
		?Version $version,
	): bool
	{
		return ($attachedId <= 0 || $attachedObject !== null)
			&& ($versionId <= 0 || $version !== null)
		;
	}

	#[LevelAccess(UnifiedLinkAccessLevel::Read)]
	#[UrlGenerator([new UrlManager(), 'getUnifiedLink'])]
	#[FileTypes(
		TypeFile::IMAGE,
		TypeFile::VIDEO,
		TypeFile::DOCUMENT,
		TypeFile::ARCHIVE,
		TypeFile::SCRIPT,
		TypeFile::UNKNOWN,
		TypeFile::PDF,
		TypeFile::AUDIO,
		TypeFile::KNOWN,
		TypeFile::VECTOR_IMAGE,
		TypeFile::BOARD,
	)]
	public function viewAction(?UnifiedLinkRenderer $service): HttpResponse
	{
		return $this->createResponse($service, UnifiedLinkAccessLevel::Read);
	}

	#[LevelAccess(UnifiedLinkAccessLevel::Edit)]
	#[UrlGenerator([new UrlManager(), 'getUnifiedEditLink'])]
	#[FileTypes(TypeFile::DOCUMENT)]
	#[RedirectToView(
		TypeFile::IMAGE,
		TypeFile::VIDEO,
		TypeFile::ARCHIVE,
		TypeFile::SCRIPT,
		TypeFile::UNKNOWN,
		TypeFile::AUDIO,
		TypeFile::KNOWN,
		TypeFile::VECTOR_IMAGE,
		TypeFile::BOARD,
	)]
	public function editAction(?UnifiedLinkRenderer $service): HttpResponse
	{
		return $this->createResponse($service, UnifiedLinkAccessLevel::Edit);
	}

	/**
	 * @param UnifiedLinkRenderer|null $service
	 * @param UnifiedLinkAccessLevel $accessLevel
	 * @return HttpResponse
	 * @throws ArgumentTypeException
	 */
	private function createResponse(?UnifiedLinkRenderer $service, UnifiedLinkAccessLevel $accessLevel): HttpResponse
	{
		if ($service === null)
		{
			return (new HttpResponse())
				->setStatus(404)
				->setContent(UnifiedLinkRenderer::renderAccessDeniedPage())
			;
		}

		$result = $service->render($accessLevel);
		$redirectUrl = $result->getRedirectUrl();

		if(is_string($redirectUrl))
		{
			return $this->redirectTo($redirectUrl);
		}

		$component = $result->getComponent();
		if ($component !== null)
		{
			return $this->renderComponent($component, withSiteTemplate: false);
		}

		$response = (new HttpResponse())
			->setStatus($result->getStatus())
			->setContent($result->getContent())
		;

		foreach ($result->getHeaders() as $name => $value)
		{
			$response->addHeader($name, $value);
		}

		return $response;
	}
}

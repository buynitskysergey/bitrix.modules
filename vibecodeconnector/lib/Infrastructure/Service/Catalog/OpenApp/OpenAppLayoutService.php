<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Infrastructure\Service\Catalog\OpenApp;

use Bitrix\Main\Engine\Response\Redirect;
use Bitrix\Main\HttpResponse;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Web\Uri;
use Bitrix\Vibecodeconnector\Internal\Dto\Catalog\OpenApp\OpenAppPayload;
use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItem;
use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItemType;
use Bitrix\Vibecodeconnector\Internal\Exception\OpenAppException;
use Bitrix\Vibecodeconnector\Internal\Integration\Bitrix24\VibePlusPolicy;
use Bitrix\Vibecodeconnector\Internal\Integration\Main\AccessCodes;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\CatalogItemRepository;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\OpenApp\OpenAppLayoutRenderer;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\OpenApp\OpenAppPayloadBuilder;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\OpenApp\OpenAppSettings;

final class OpenAppLayoutService
{
	private const ERROR_DISABLED = 'OPEN_APP_DISABLED';
	private const ERROR_NOT_FOUND = 'ITEM_NOT_FOUND';
	private const ERROR_ACCESS_DENIED = 'ACCESS_DENIED';
	private const ERROR_BUILD_FAILED = 'OPEN_APP_BUILD_FAILED';
	private const ERROR_FEATURE_NOT_AVAILABLE = 'FEATURE_NOT_AVAILABLE_ON_CURRENT_PLAN';

	public function __construct(
		private readonly OpenAppSettings $settings = new OpenAppSettings(),
		private readonly CatalogItemRepository $itemRepository = new CatalogItemRepository(),
		private readonly OpenAppPayloadBuilder $payloadBuilder = new OpenAppPayloadBuilder(),
		private readonly OpenAppLayoutRenderer $renderer = new OpenAppLayoutRenderer(),
		private readonly AccessCodes $accessCodes = new AccessCodes(),
		private readonly VibePlusPolicy $vibePlusPolicy = new VibePlusPolicy(),
	) {
	}

	public function render(int $catalogItemId, int $userId): string
	{
		$payload = $this->resolvePayload($catalogItemId, $userId);
		if (is_string($payload))
		{
			return $this->renderer->renderError(...$this->errorParams($payload));
		}

		return $this->renderer->render($payload);
	}

	public function renderPage(int $catalogItemId, int $userId): string
	{
		$payload = $this->resolvePayload($catalogItemId, $userId);
		if (is_string($payload))
		{
			return $this->renderer->renderErrorPage(...$this->errorParams($payload));
		}

		return $this->renderer->renderPage($payload);
	}

	public function renderPageResponse(int $catalogItemId, int $userId): HttpResponse
	{
		$item = $this->resolveAccessibleItem($catalogItemId, $userId);
		if (is_string($item))
		{
			return $this->createHtmlResponse(
				$this->renderer->renderErrorPage(...$this->errorParams($item)),
			);
		}

		$viewUrl = $this->resolveViewUrl($item);
		if (
			!$this->settings->isOpenInIframeEnabled()
			|| trim((string)$item->getExternalId()) === ''
		)
		{
			if ($viewUrl !== null)
			{
				$response = new Redirect($viewUrl);
				$response->addHeader('Cache-Control', 'private, no-store');

				return $response;
			}

			return $this->createHtmlResponse(
				$this->renderer->renderErrorPage(...$this->errorParams(
					$this->settings->isOpenInIframeEnabled()
						? OpenAppException::CODE_EXTERNAL_ID_MISSING
						: self::ERROR_DISABLED,
				)),
			);
		}

		$payload = $this->buildPayload($item, $userId);
		if (is_string($payload))
		{
			return $this->createHtmlResponse(
				$this->renderer->renderErrorPage(...$this->errorParams($payload)),
			);
		}

		return $this->createHtmlResponse($this->renderer->renderPage($payload));
	}

	private function createHtmlResponse(string $content): HttpResponse
	{
		$response = new HttpResponse();
		$response->addHeader('Content-Type', 'text/html; charset=UTF-8');
		$response->addHeader('Cache-Control', 'private, no-store');
		$response->setContent($content);

		return $response;
	}

	/**
	 * Runs the shared validation/build path. Returns the ready payload on success
	 * or an error code (string) to render otherwise.
	 */
	private function resolvePayload(int $catalogItemId, int $userId): OpenAppPayload|string
	{
		if (!$this->settings->isOpenInIframeEnabled())
		{
			return self::ERROR_DISABLED;
		}

		$item = $this->resolveAccessibleItem($catalogItemId, $userId);
		if (is_string($item))
		{
			return $item;
		}

		return $this->buildPayload($item, $userId);
	}

	private function resolveAccessibleItem(int $catalogItemId, int $userId): CatalogItem|string
	{
		$item = $this->itemRepository->getById($catalogItemId);
		if ($item === null || $item->isDeactivated())
		{
			return self::ERROR_NOT_FOUND;
		}

		if ($item->getType() !== CatalogItemType::Application)
		{
			return self::ERROR_NOT_FOUND;
		}

		if ($userId <= 0)
		{
			return self::ERROR_ACCESS_DENIED;
		}

		if (!$this->itemRepository->isAccessibleToUser(
			$catalogItemId,
			$userId,
			$this->accessCodes->getUserCodes($userId),
		))
		{
			return self::ERROR_ACCESS_DENIED;
		}

		if ($this->vibePlusPolicy->getAvailability() === false)
		{
			return self::ERROR_FEATURE_NOT_AVAILABLE;
		}

		return $item;
	}

	private function buildPayload(CatalogItem $item, int $userId): OpenAppPayload|string
	{
		try
		{
			return $this->payloadBuilder->build($item, $userId);
		}
		catch (OpenAppException $exception)
		{
			return $exception->getErrorCode();
		}
		catch (\Throwable)
		{
			return self::ERROR_BUILD_FAILED;
		}
	}

	private function resolveViewUrl(CatalogItem $item): ?string
	{
		$viewUrl = trim((string)$item->getViewUrl());
		if (
			$viewUrl === ''
			|| str_contains($viewUrl, "\r")
			|| str_contains($viewUrl, "\n")
		)
		{
			return null;
		}

		if (str_starts_with($viewUrl, '/'))
		{
			return str_starts_with($viewUrl, '//') ? null : $viewUrl;
		}

		$uri = new Uri($viewUrl);
		if (
			!in_array($uri->getScheme(), ['http', 'https'], true)
			|| $uri->getHost() === ''
		)
		{
			return null;
		}

		return $viewUrl;
	}

	/**
	 * @return array{string, string, string} error code, title and message
	 */
	private function errorParams(string $code): array
	{
		return [
			$code,
			(string)Loc::getMessage('VIBECODECONNECTOR_OPEN_APP_ERROR_TITLE'),
			(string)(
				Loc::getMessage('VIBECODECONNECTOR_OPEN_APP_ERROR_' . $code)
				?: Loc::getMessage('VIBECODECONNECTOR_OPEN_APP_ERROR_DEFAULT')
			),
		];
	}
}

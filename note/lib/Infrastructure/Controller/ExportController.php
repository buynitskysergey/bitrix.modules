<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Controller;

use Bitrix\Main\Context;
use Bitrix\Main\Engine\ActionFilter\Authentication;
use Bitrix\Main\Engine\ActionFilter\CloseSession;
use Bitrix\Main\Engine\ActionFilter\Csrf;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\Response\File;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Security\Sign\BadSignatureException;
use Bitrix\Main\Security\Sign\TimeSigner;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Service\Export\DocumentZipExportService;
use Bitrix\Note\Public\Provider\Param\Document\DocumentLimits;
use Bitrix\Note\Public\Service\AccessService;

class ExportController extends Controller
{
	public const TOKEN_SALT = 'note.export.zip';

	protected function getDefaultPreFilters(): array
	{
		return array_merge(
			parent::getDefaultPreFilters(),
			[
				new ActionFilter\NoteAccess(),
			],
		);
	}

	public function configureActions(): array
	{
		return [
			'prepareZip' => [
				'+prefilters' => [
					new Csrf(),
					new Authentication(),
				],
			],
			'downloadZip' => [
				'-prefilters' => [
					Csrf::class,
				],
				'+prefilters' => [
					new Authentication(),
					new CloseSession(),
				],
			],
		];
	}

	public function prepareZipAction(int $documentId, string $content): ?array
	{
		$document = (new DocumentRepository())->getMetaById($documentId, ['ID', 'COLLECTION_ID', 'TITLE']);
		if ($document === null)
		{
			$this->addError(new Error(
				Loc::getMessage('NOTE_EXPORT_CONTROLLER_PREPARE_ERROR_DOCUMENT'),
				'NOTE_EXPORT_DOCUMENT_NOT_FOUND',
			));

			return null;
		}

		if (!$this->assertDocumentViewAccess($documentId, (int)$document->getCollectionId()))
		{
			return null;
		}

		if (strlen($content) > DocumentLimits::MAX_MARKDOWN_BYTES)
		{
			$this->addError(new Error(
				Loc::getMessage('NOTE_EXPORT_CONTROLLER_PREPARE_ERROR_CONTENT_TOO_LARGE'),
				'NOTE_EXPORT_CONTENT_TOO_LARGE',
			));

			return null;
		}

		$packageId = (new DocumentZipExportService())->build($documentId, $document->getTitle(), $content);
		if ($packageId === null)
		{
			$this->addError(new Error(
				Loc::getMessage('NOTE_EXPORT_CONTROLLER_PREPARE_ERROR_BUILD'),
				'NOTE_EXPORT_BUILD_FAILED',
			));

			return null;
		}

		$userId = (int)$this->getCurrentUser()->getId();
		$token = (new TimeSigner())->sign("{$userId}:{$packageId}", '+15 minutes', self::TOKEN_SALT);

		return ['token' => $token];
	}

	public function downloadZipAction(string $token): ?File
	{
		try
		{
			$unsignedValue = (new TimeSigner())->unsign($token, self::TOKEN_SALT);
		}
		catch (BadSignatureException $exception)
		{
			Context::getCurrent()->getResponse()->setStatus(410);
			$this->addError(new Error(
				Loc::getMessage('NOTE_EXPORT_CONTROLLER_DOWNLOAD_ERROR_TOKEN'),
				'NOTE_EXPORT_INVALID_TOKEN',
			));

			return null;
		}

		[$userId, $packageId] = array_pad(explode(':', $unsignedValue, 2), 2, '');
		if ((int)$userId !== (int)$this->getCurrentUser()->getId())
		{
			// 404, not 403: existence of another user's package is not disclosed.
			Context::getCurrent()->getResponse()->setStatus(404);
			$this->addError(new Error(
				Loc::getMessage('NOTE_EXPORT_CONTROLLER_DOWNLOAD_ERROR_PACKAGE'),
				'NOTE_EXPORT_PACKAGE_NOT_FOUND',
			));

			return null;
		}

		$package = (new DocumentZipExportService())->resolvePackage((string)$packageId);
		if ($package === null)
		{
			Context::getCurrent()->getResponse()->setStatus(404);
			$this->addError(new Error(
				Loc::getMessage('NOTE_EXPORT_CONTROLLER_DOWNLOAD_ERROR_PACKAGE'),
				'NOTE_EXPORT_PACKAGE_NOT_FOUND',
			));

			return null;
		}

		return (new File($package['path'], $package['displayName'], 'application/zip'))->showInline(false);
	}

	private function assertDocumentViewAccess(int $documentId, int $collectionId): bool
	{
		if (AccessService::canViewDocument($documentId, $collectionId))
		{
			return true;
		}

		$this->addError(new Error(
			Loc::getMessage('NOTE_EXPORT_CONTROLLER_PREPARE_ERROR_ACCESS'),
			'NOTE_EXPORT_ACCESS_DENIED',
		));

		return false;
	}
}

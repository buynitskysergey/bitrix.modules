<?php

namespace Bitrix\Sign\Controllers\V1\Document;

use Bitrix\Main;
use Bitrix\Main\ArgumentTypeException;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Main\Security\Sign\BadSignatureException;
use Bitrix\Main\Security\Sign\Signer;
use Bitrix\Sign\Operation\GetSignedB2eFileUrlForUiViewer;
use Bitrix\Sign\Operation\Member\MakeB2eSignedFileName;
use Bitrix\Sign\Service;
use Bitrix\Sign\Operation\GetSignedB2eFileUrl;
use Bitrix\Sign\Type\EntityFileCode;
use Bitrix\Sign\Type\EntityType;

class B2eSignedFile extends \Bitrix\Sign\Engine\Controller
{
	private const AJAX_PATH = '/bitrix/services/main/ajax.php';

	public function configureActions(): array
	{
		$actionsConfiguration = parent::configureActions();
		$actionsConfiguration['download']['-prefilters'] = [
			Main\Engine\ActionFilter\ContentType::class,
			Main\Engine\ActionFilter\Csrf::class,
		];
		$actionsConfiguration['getFileUrlForUiViewer']['-prefilters'] = [
			Main\Engine\ActionFilter\ContentType::class,
			Main\Engine\ActionFilter\Csrf::class,
		];

		return $actionsConfiguration;
	}

	/**
	 * The gate here is the url signature, not the company safe ACL: that ACL layer was removed by #254143,
	 * because its owner scope does not treat a signer as an owner of the document.
	 * Bringing the check back requires an explicit "current user is a member of this document" branch.
	 *
	 * @throws ArgumentTypeException
	 * @throws ObjectNotFoundException
	 * @throws BadSignatureException
	 */
	public function downloadAction(int $entityTypeId, int $entityId, string $sign, int $fileCode): Main\Engine\Response\BFile | array
	{
		if (!in_array($fileCode, EntityFileCode::getAll(), true))
		{
			return [];
		}

		$signer = new Signer();

		if ($signer->unsign($sign, GetSignedB2eFileUrl::B2eFileSalt) !== "$entityTypeId$entityId")
		{
			$this->addError(new Main\Error(
				'Entity not found',
				'SIGN_DOCUMENT_NOT_FOUND',
			));

			return [];
		}

		if ($entityTypeId === \Bitrix\Sign\Type\EntityType::MEMBER)
		{
			$member = Service\Container::instance()->getMemberRepository()->getById($entityId);
			if ($member === null)
			{
				$this->addError(new Main\Error(
					'Entity not found',
					'SIGN_DOCUMENT_NOT_FOUND',
				));

				return [];
			}
		}
		else
		{
			$this->addError(new Main\Error(
				'Wrong entity type',
				'SIGN_WRONG_ENTITY_TYPE',
			));

			return [];
		}

		$entity = Service\Container::instance()->getEntityFileRepository()->getOne(
			$entityTypeId,
			$entityId,
			$fileCode,
		);

		if (!$entity)
		{
			$this->addError(new Main\Error(
				'Entity not found',
				'SIGN_DOCUMENT_NOT_FOUND',
			));

			return [];
		}

		if ($entity->fileId <= 0)
		{
			$this->addError(new Main\Error(
				'Entity has no result file',
				'SIGN_ENTITY_NO_RESULT_FILE',
			));

			return [];
		}

		$result = (new MakeB2eSignedFileName($member, $entity))->launch();

		return Main\Engine\Response\BFile::createByFileId($entity->fileId, $result->fileName)
			->showInline(false)
		;
	}

	public function getFileUrlForUiViewerAction(string $sign, string $url): ?array
	{
		if (!str_starts_with($url, self::AJAX_PATH))
		{
			$this->addError(new Main\Error('Invalid download url'));
			return [];
		}

		$hashedUrl = hash('sha256', $url);

		$signer = new Signer();

		if ($signer->unsign($sign, GetSignedB2eFileUrlForUiViewer::B2eFileSalt) !== $hashedUrl)
		{
			$this->addError(new Main\Error(
				'Entity not found',
				'SIGN_DOCUMENT_NOT_FOUND',
			));

			return [];
		}

		if (!$this->isValidDownloadUrl($url))
		{
			$this->addError(new Main\Error('Invalid download url'));

			return [];
		}

		return [
			'data' => [
				'src' => $url,
			],
		];
	}

	private function isValidDownloadUrl(string $url): bool
	{
		$uri = new Main\Web\Uri($url);

		if (
			$uri->getScheme() !== '' ||
			$uri->getHost() !== '' ||
			$uri->getPath() !== self::AJAX_PATH
		)
		{
			return false;
		}

		$queryParams = [];
		parse_str($uri->getQuery(), $queryParams);

		if (
			($queryParams['action'] ?? null)
			!== 'sign.api_v1.Document.B2eSignedFile.download'
			|| !is_scalar($queryParams['entityTypeId'] ?? null)
			|| !is_scalar($queryParams['entityId'] ?? null)
			|| !is_scalar($queryParams['fileCode'] ?? null)
		)
		{
			return false;
		}

		$entityTypeId = filter_var(
			$queryParams['entityTypeId'],
			FILTER_VALIDATE_INT,
			['options' => ['min_range' => 1]],
		);

		$entityId = filter_var(
			$queryParams['entityId'],
			FILTER_VALIDATE_INT,
			['options' => ['min_range' => 1]],
		);

		$fileCode = filter_var(
			$queryParams['fileCode'],
			FILTER_VALIDATE_INT,
		);

		if (
			$entityTypeId !== EntityType::MEMBER
			|| $entityId === false
			|| $fileCode === false
			|| !in_array($fileCode, EntityFileCode::getAll(), true)
		)
		{
			return false;
		}

		return true;
	}
}

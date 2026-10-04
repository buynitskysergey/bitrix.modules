<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Flipchart;

use Bitrix\Disk\AttachedObject;
use Bitrix\Disk\Document\Flipchart\DualMode\BoardApiServiceFactory;
use Bitrix\Disk\Document\Flipchart\DualMode\ConfigurationException;
use Bitrix\Disk\Document\Flipchart\DualMode\PilotLog;
use Bitrix\Disk\Document\Flipchart\DualMode\ServiceProfile;
use Bitrix\Disk\Document\Flipchart\DualMode\ServiceProfileResolver;
use Bitrix\Disk\Document\Flipchart\Enum\BoardReadyStatus;
use Bitrix\Disk\Document\Flipchart\Messenger\DownloadBoardMessage;
use Bitrix\Disk\Document\Models\DocumentSession;
use Bitrix\Disk\File;
use Bitrix\Disk\Folder;
use Bitrix\Disk\TypeFile;
use Bitrix\Disk\User;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Localization\Loc;

class BoardService
{
	private const NEW_BOARD_MAX_SIZE = 500;

	protected DocumentSession $session;

	public function __construct(DocumentSession $session)
	{
		$this->session = $session;
	}

	public function closeSession(): bool
	{
		return $this->session->setAsNonActive();
	}

	public static function convertDocumentIdToExternal(int|string $documentId, int|string|null $versionId = null): string
	{
		if ($versionId)
		{
			$documentId .= '.' . $versionId;
		}

		$id = [
			Configuration::getDocumentIdSalt(),
			SITE_ID,
			$documentId,
		];
		$id = array_filter($id);

		return implode('-', $id);
	}

	public static function getDocumentIdFromExternal($documentId): string
	{
		return self::getDocumentIdAndVersionFromExternal($documentId)[0];
	}

	public static function getDocumentIdAndVersionFromExternal($documentId): array
	{
		$documentId = explode('-', $documentId);
		$documentId = array_pop($documentId);
		return [$documentId, $versionId] = explode('.', $documentId);
	}

	public static function getSiteIdFromExternal($documentId): string
	{
		$documentId = explode('-', $documentId);
		array_pop($documentId);
		return array_pop($documentId);
	}

	public function saveDocument($isNewBoard = false, int $attempt = 0): Error|bool
	{
		if (!$this->session->getObject())
		{
			return new Error('Could not find the file.');
		}

		$profile = ServiceProfileResolver::createFromOptions()->resolveForObject($this->session->getObject());
		if ($profile === null)
		{
			self::logUnresolvedProfile('saveDocument', (int)$this->session->getObject()->getId());

			return new Error('Board service profile is not resolved.');
		}

		$boardId = $this->session->getObject()->getId();
		$boardId = self::convertDocumentIdToExternal($boardId);

		try
		{
			$boardApiService = self::createApiService($profile);
		}
		catch (ConfigurationException $exception)
		{
			self::logUnconfiguredProfile('saveDocument', (int)$this->session->getObject()->getId(), $exception);

			return new Error('Board service profile is not configured.');
		}

		$boardStatus = $boardApiService->getBoardReadyStatus($boardId);
		switch ($boardStatus) {
			case BoardReadyStatus::ERROR:
			case BoardReadyStatus::FAILED:
				return new Error('Error preparing board to download');
				break;
			case BoardReadyStatus::IN_PROGRESS:
				(new DownloadBoardMessage($boardId, $this->session->getUserId(), $isNewBoard, $attempt))->schedule();
				return false;
				break;
		}
		$downloadResult = $boardApiService->downloadBoard(
			"/api/v1/flip/{$boardId}/download",
			isNewBoard: $isNewBoard,
			documentId: $boardId,
		);
		if (!$downloadResult->isSuccess())
		{
			return new Error('Could not download the file.');
		}

		$tmpFile = $downloadResult->getData()['file'];
		$tmpFileArray = \CFile::makeFileArray($tmpFile);

		// Dunno what is it
		$options = ['commentAttachedObjects' => false];
		if (!$this->session->getObject()->uploadVersion($tmpFileArray, $this->session->getUserId(), $options))
		{
			return new Error('Could not upload new version of the file.');
		}

		// $this->sendEventToParticipants('saved');
		return true;
	}

	public static function createNewDocument(User $user, Folder $folder, ?string $filename = null): Result
	{
		if (!$filename)
		{
			$filename = Loc::getMessage('DISK_BLANK_FILE_DATA_NEW_FILE_BOARD') . '.board';
		}

		$result = new Result();

		// The object does not exist yet, so the destination folder decides the instance: the blank
		// is requested before the file is created.
		$profile = ServiceProfileResolver::createFromOptions()->resolveForDestination($folder);
		if ($profile === null)
		{
			self::logUnresolvedProfile('createNewDocument', (int)$folder->getId());
			$result->addError(new Error('Board service profile is not resolved.'));

			return $result;
		}

		try
		{
			$boardApiService = self::createApiService($profile);
		}
		catch (ConfigurationException $exception)
		{
			self::logUnconfiguredProfile('createNewDocument', (int)$folder->getId(), $exception);
			$result->addError(new Error('Board service profile is not configured.'));

			return $result;
		}

		$downloadResult = $boardApiService->downloadBlank();
		if (!$downloadResult->isSuccess())
		{
			$result->addErrors($downloadResult->getErrors());

			return $result;
		}

		$tmpFile = $downloadResult->getData()['file'];
		$fileArray = \CFile::makeFileArray($tmpFile);
		if (!$fileArray)
		{
			$result->addError(new Error('Cannot create file'));

			return $result;
		}

		$fileArray['type'] = 'application/board';
		$fileArray['name'] = $filename;
		$file = $folder->uploadFile(
			$fileArray,
			[
				'NAME' => $filename,
				'CREATED_BY' => $user->getId(),
			],
			[],
			true,
		);

		if (!$file)
		{
			$result->addError(new Error('Cannot save file'));

			return $result;
		}

		$result->setData([
			'file' => $file,
		]);

		return $result;
	}

	public static function kickUsers(File $file, array $userIds): void
	{
		if ($file->getTypeFile() != TypeFile::FLIPCHART)
		{
			return;
		}

		$profile = ServiceProfileResolver::createFromOptions()->resolveForObject($file);
		if ($profile === null)
		{
			// Batch paths (folder-wide rights change) must skip the object rather than contact any
			// instance: touching the old one would break the invariant for a clean board.
			self::logUnresolvedProfile('kickUsers', (int)$file->getId());

			return;
		}

		// An active pilot whose address is not configured yet must skip the object as well: an exception
		// here would abort the folder-wide walk and leave the remaining boards with live sessions.
		try
		{
			$apiService = self::createApiService($profile);
			$apiService->kickUsers(static::convertDocumentIdToExternal($file->getId()), $userIds);
		}
		catch (ConfigurationException $exception)
		{
			self::logUnconfiguredProfile('kickUsers', (int)$file->getId(), $exception);
		}
	}

	public static function kickUnallowedUsers(array $sessions, File|AttachedObject $object): void
	{
		$userIds = [];
		$needToKickGuests = false;
		foreach ($sessions as $session)
		{
			$userId = $session->getUserId();
			if ($userId < 0)
			{
				$needToKickGuests = true;
				continue;
			}

			$userIds[] = $userId;
		}
		if ($object instanceof AttachedObject)
		{
			$object = $object->getFile();
		}
		static::kickUsers($object, $userIds);

		if ($needToKickGuests)
		{
			static::kickGuestsUsers($object);
		}
	}

	public static function kickGuestsUsers(File|AttachedObject $object): void
	{
		if ($object instanceof AttachedObject)
		{
			$object = $object->getFile();
		}
		$profile = ServiceProfileResolver::createFromOptions()->resolveForObject($object);
		if ($profile === null)
		{
			self::logUnresolvedProfile('kickGuestsUsers', (int)$object->getId());

			return;
		}

		$documentId = static::convertDocumentIdToExternal($object->getId());
		try
		{
			$userIds = self::createApiService($profile)->getActiveUsersByDocumentId($documentId);
		}
		catch (ConfigurationException $exception)
		{
			self::logUnconfiguredProfile('kickGuestsUsers', (int)$object->getId(), $exception);

			return;
		}

		if (!$userIds)
		{
			return;
		}

		$userIds = array_values(
			array_filter($userIds, static function ($userId) {
				return str_starts_with($userId, '~');
			}),
		);

		static::kickUsers($object, $userIds);
	}

	/**
	 * Determine whether to show the template selection modal for a newly created board.
	 * @param File $file
	 * @return bool
	 */
	public static function shouldShowTemplateModal(File $file): bool
	{
		$secondsSinceCreation = time() - $file->getCreateTime()->getTimestamp();
		$isRealObject = (int)$file->getRealObjectId() === (int)$file->getId();

		$currentUser = CurrentUser::get();
		$isCreatedByCurrentUser = (int)$file->getCreatedBy() === (int)$currentUser->getId();

		$isSmallNewBoard = $file->getSize() < self::NEW_BOARD_MAX_SIZE;

		return $secondsSinceCreation < 30
			&& $isCreatedByCurrentUser
			&& $isRealObject
			&& $isSmallNewBoard;
	}

	private static function createApiService(ServiceProfile $profile): BoardApiService
	{
		return ServiceLocator::getInstance()->get(BoardApiServiceFactory::class)->create($profile);
	}

	private static function logUnresolvedProfile(string $entryPoint, int $objectId): void
	{
		PilotLog::error(
			'Board service profile is not resolved: {entryPoint}, object {objectId}',
			[
				'entryPoint' => $entryPoint,
				'objectId' => $objectId,
			],
		);
	}

	private static function logUnconfiguredProfile(
		string $entryPoint,
		int $objectId,
		ConfigurationException $exception,
	): void
	{
		PilotLog::error(
			'Board service profile is not configured: {entryPoint}, object {objectId}, {reason}',
			[
				'entryPoint' => $entryPoint,
				'objectId' => $objectId,
				'reason' => $exception->getMessage(),
			],
		);
	}
}

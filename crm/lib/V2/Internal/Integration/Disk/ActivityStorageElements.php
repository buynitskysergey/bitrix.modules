<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\Disk;

use Bitrix\Crm\Integration\Disk\HiddenStorage;
use Bitrix\Crm\Integration\StorageFileType;
use Bitrix\Crm\Integration\StorageType;
use Bitrix\Disk\File;
use Bitrix\Disk\Folder;
use Bitrix\Disk\SpecificFolder;
use Bitrix\Disk\SystemUser;
use Bitrix\Main\Loader;
use CFile;

/**
 * The storage side of an activity: the files an activity leaves behind when it is deleted (LBS-1 section 5.3
 * step 8, `CCrmActivity::DoDeleteStorageElements()` at crm_activity.php:2321-2375).
 *
 * ONE METHOD, ONE STEP. The step of the delete pipeline that removes the files of an activity calls
 * {@see deleteElements()} once, exactly where legacy calls its counterpart. The gate of the step - the option
 * that keeps the files, and the activity moved to the recycle bin that raises it - stays in the pipeline,
 * because it is a condition of the operation rather than knowledge about a storage. The rows of
 * `b_crm_act_elem` are not here either: they are a table of CRM and belong to its repository.
 *
 * WHICH FILE MAY BE DELETED AT ALL. A file of the disk is removed only when it sits in a service folder of
 * CRM - the folder of email attachments, of call records, of the REST applications, the folder of uploaded
 * files or the hidden folder of activities - AND nothing else is attached to it. Everything else is left
 * alone: the same file may hang on a message, on a timeline record or on an entity of a different module,
 * and a file the layer cannot resolve is skipped instead of guessed about. A file of the core storage
 * (`StorageType::File`) carries no such structure and is deleted as it is.
 *
 * WHAT CROSSES THE BOUNDARY. Identifiers and the storage type of CRM ({@see StorageType}), never a file, a
 * folder or a storage of the disk. The answer is how many elements were removed - the number a test can read
 * and a step is free to ignore, as legacy ignores it.
 *
 * THE DISK IS AN OPTIONAL DEPENDENCY, and here it is a stricter statement than legacy makes: legacy calls
 * `Loader::includeModule('disk')` and drops the answer, so a portal without the module would fail on the
 * very next line. Reaching that line needs elements of a storage only the disk itself creates, which is why
 * the branch is unreachable rather than working - and why the guard below is a guard and not a change of
 * behaviour. THE CLASS IS NOT FINAL for that branch: {@see isDiskAvailable()} is the seam its test needs,
 * and it is the form the sample of this layer uses (`Internal\Integration\Sale\LocationDictionary`).
 *
 * @internal
 */
class ActivityStorageElements
{
	private const MODULE_ID = 'disk';

	/**
	 * Removes the files of an activity that is being deleted.
	 *
	 * @param int $storageTypeId storage the elements belong to, {@see StorageType}.
	 * @param int[] $storageElementIds elements of that storage, as the activity carries them.
	 * @return int the number of elements that were removed.
	 */
	public function deleteElements(int $storageTypeId, array $storageElementIds): int
	{
		$elementIds = self::positiveIdsOf($storageElementIds);
		if ($elementIds === [])
		{
			return 0;
		}

		if ($storageTypeId === StorageType::File)
		{
			return self::deleteFilesOfTheCore($elementIds);
		}

		if ($storageTypeId === StorageType::Disk)
		{
			return $this->deleteFilesOfTheDisk($elementIds);
		}

		// A storage of the WebDav era, or none at all: legacy removes nothing there and neither does this.
		return 0;
	}

	/**
	 * Whether the disk is installed.
	 */
	protected function isDiskAvailable(): bool
	{
		return Loader::includeModule(self::MODULE_ID);
	}

	/**
	 * @param int[] $elementIds
	 */
	private function deleteFilesOfTheDisk(array $elementIds): int
	{
		if (!$this->isDiskAvailable())
		{
			return 0;
		}

		$deleted = 0;
		foreach ($elementIds as $elementId)
		{
			$file = File::loadById($elementId);
			if ($file === null || !self::isDeletableWithTheActivity($file))
			{
				continue;
			}

			$file->delete(SystemUser::SYSTEM_USER_ID);
			++$deleted;
		}

		return $deleted;
	}

	private static function isDeletableWithTheActivity(File $file): bool
	{
		$folder = $file->getParent();
		if (!($folder instanceof Folder))
		{
			return false;
		}

		return self::isServiceFolder($folder) && (int)$file->countAttachedObjects() === 0;
	}

	private static function isServiceFolder(Folder $folder): bool
	{
		if (
			isset(self::serviceFolderXmlIds()[(string)$folder->getXmlId()])
			|| $folder->getCode() === SpecificFolder::CODE_FOR_UPLOADED_FILES
		)
		{
			return true;
		}

		$parentFolder = $folder->getParent();

		return $parentFolder !== null && $parentFolder->getCode() === HiddenStorage::FOLDER_CODE_ACTIVITY;
	}

	/**
	 * Folders of CRM whose files belong to the activity that put them there.
	 *
	 * @return array<string, true>
	 */
	private static function serviceFolderXmlIds(): array
	{
		return [
			StorageFileType::getFolderXmlID(StorageFileType::EmailAttachment) => true,
			StorageFileType::getFolderXmlID(StorageFileType::CallRecord) => true,
			StorageFileType::getFolderXmlID(StorageFileType::Rest) => true,
		];
	}

	/**
	 * @param int[] $elementIds
	 */
	private static function deleteFilesOfTheCore(array $elementIds): int
	{
		foreach ($elementIds as $elementId)
		{
			CFile::Delete($elementId);
		}

		return count($elementIds);
	}

	/**
	 * @param array<int|string, mixed> $storageElementIds
	 * @return int[]
	 */
	private static function positiveIdsOf(array $storageElementIds): array
	{
		$elementIds = [];
		foreach ($storageElementIds as $storageElementId)
		{
			$elementId = (int)$storageElementId;
			if ($elementId > 0)
			{
				$elementIds[] = $elementId;
			}
		}

		return array_values(array_unique($elementIds));
	}
}

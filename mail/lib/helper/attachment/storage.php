<?php

namespace Bitrix\Mail\Helper\Attachment;

use Bitrix\Main;
use Bitrix\Main\Localization\Loc;
use Bitrix\Mail;

class Storage
{
	public static function getName(): string
	{
		return (string)Loc::getMessage('MAIL_ATTACHMENT_STORAGE_NAME');
	}

	/**
	 * Returns attachments disk storage
	 *
	 * @return \Bitrix\Disk\Storage|false
	 */
	public static function getStorage()
	{
		static $storage;

		if (!is_null($storage))
		{
			return $storage;
		}

		$storage = false;

		if (!Main\Loader::includeModule('disk'))
		{
			return $storage;
		}

		$storageId = Main\Config\Option::get('mail', 'disk_attachment_storage_id', 0);
		if ($storageId > 0)
		{
			$storage = \Bitrix\Disk\Storage::loadById($storageId);
			if (!$storage || $storage->getModuleId() != 'mail')
			{
				$storage = false;
			}
		}

		if (!$storage)
		{
			$driver = \Bitrix\Disk\Driver::getInstance();

			$storage = $driver->addStorageIfNotExist(array(
				'NAME' => static::getName(),
				'USE_INTERNAL_RIGHTS' => false,
				'MODULE_ID' => 'mail',
				'ENTITY_TYPE' => Mail\Disk\ProxyType\Mail::className(),
				'ENTITY_ID' => 'mail',
			));
			if ($storage)
			{
				Main\Config\Option::set('mail', 'disk_attachment_storage_id', $storage->getId());
			}
			else
			{
				$storage = false;
			}
		}

		return $storage;
	}

	/**
	 * Returns disk url manager
	 *
	 * @return \Bitrix\Disk\UrlManager|false
	 */
	public static function getUrlManager()
	{
		static $urlManager;

		if (!is_null($urlManager))
		{
			return $urlManager;
		}

		$urlManager = false;

		if (!Main\Loader::includeModule('disk'))
		{
			return $urlManager;
		}

		$urlManager = \Bitrix\Disk\Driver::getInstance()->getUrlManager();

		return $urlManager;
	}

	/**
	 * Returns signed disk url to show the attachment file
	 *
	 * @param \Bitrix\Disk\File $object Disk object of the attachment.
	 * @param array $urlParams Extra url params, e.g. an access token.
	 * @return string|null Null when disk url manager is not available.
	 */
	public static function getFileUrl($object, array $urlParams = array())
	{
		$urlManager = static::getUrlManager();

		if (!$urlManager)
		{
			return null;
		}

		return (string)$urlManager->getUrlForShowFile($object, $urlParams);
	}

	/**
	 * Returns signed disk url to show the square preview of the attachment file
	 *
	 * @param \Bitrix\Disk\File $object Disk object of the attachment.
	 * @param int $size Preview side, applied as an exact size.
	 * @param array $urlParams Extra url params, e.g. an access token.
	 * @param bool|null $isImage Image flag of the object, e.g. from getImageFlagsByObjectId(); resolved
	 *   per object when null.
	 * @return string|null Null when the file is not an image or disk url manager is not available.
	 */
	public static function getFilePreviewUrl($object, $size, array $urlParams = array(), ?bool $isImage = null)
	{
		$urlManager = static::getUrlManager();

		if (!$urlManager || !($isImage ?? \Bitrix\Disk\TypeFile::isImage($object)))
		{
			return null;
		}

		return (string)$urlManager->getUrlForShowFile(
			$object,
			array_merge(
				array('width' => $size, 'height' => $size, 'exact' => 'Y'),
				$urlParams
			)
		);
	}

	/**
	 * Image flag of every given attachment object, by disk object id.
	 *
	 * \Bitrix\Disk\TypeFile::isImage() reads the file row of every object on its own, so a set of
	 * attachments costs a query per file. The same two disk primitives decide it here, only the file
	 * rows of the whole set are read at once.
	 *
	 * @param \Bitrix\Disk\File[] $objects Disk objects of the attachments.
	 * @return array<int, bool>
	 */
	public static function getImageFlagsByObjectId(array $objects): array
	{
		$flags = [];
		$fileIdsByObjectId = [];
		foreach ($objects as $object)
		{
			$objectId = (int)$object->getId();
			$flags[$objectId] = false;
			if (\Bitrix\Disk\TypeFile::getByFile($object) === \Bitrix\Disk\TypeFile::IMAGE)
			{
				$fileIdsByObjectId[$objectId] = (int)$object->getFileId();
			}
		}

		if ($fileIdsByObjectId === [])
		{
			return $flags;
		}

		$fileRows = [];
		$rows = Main\FileTable::query()
			->setSelect(['ID', 'ORIGINAL_NAME', 'CONTENT_TYPE', 'FILE_SIZE', 'WIDTH', 'HEIGHT'])
			->whereIn('ID', array_values(array_unique($fileIdsByObjectId)))
			->fetchAll()
		;
		foreach ($rows as $row)
		{
			$fileRows[(int)$row['ID']] = $row;
		}

		foreach ($fileIdsByObjectId as $objectId => $fileId)
		{
			$fileRow = $fileRows[$fileId] ?? null;
			$flags[$objectId] = $fileRow !== null && !\Bitrix\Disk\TypeFile::shouldTreatImageAsFile($fileRow);
		}

		return $flags;
	}

	/**
	 * Returns disk objects by file ID
	 *
	 * @param int $fileId File ID.
	 * @param int $limit Limit.
	 * @return array
	 */
	public static function getObjectsByFileId($fileId, $limit = 0)
	{
		$storage = static::getStorage();

		if (!$storage)
		{
			return array();
		}

		return \Bitrix\Disk\File::getModelList(array(
			'filter' => array(
				'=STORAGE_ID' => $storage->getId(),
				'=TYPE' => \Bitrix\Disk\Internals\ObjectTable::TYPE_FILE,
				'=FILE_ID' => $fileId,
			),
			'limit' => $limit,
		));
	}

	/**
	 * @param int[] $fileIds File IDs.
	 * @return \Bitrix\Disk\File[] Map of file ID to disk object.
	 */
	public static function getObjectsByFileIds(array $fileIds)
	{
		$storage = static::getStorage();

		if (!$storage)
		{
			return array();
		}

		$fileIds = array_values(array_unique(array_filter(array_map('intval', $fileIds))));

		if (empty($fileIds))
		{
			return array();
		}

		$objects = \Bitrix\Disk\File::getModelList(array(
			'filter' => array(
				'=STORAGE_ID' => $storage->getId(),
				'=TYPE' => \Bitrix\Disk\Internals\ObjectTable::TYPE_FILE,
				'@FILE_ID' => $fileIds,
			),
		));

		$objectsByFileId = array();

		foreach ($objects as $object)
		{
			$fileId = (int)$object->getFileId();
			if (!isset($objectsByFileId[$fileId]))
			{
				$objectsByFileId[$fileId] = $object;
			}
		}

		return $objectsByFileId;
	}

	/**
	 * Returns disk object by attachment file data (creates one if not exists)
	 *
	 * @param array $attachment Attachment file data.
	 * @param boolean $create Create object if not exists.
	 * @return \Bitrix\Disk\File|false|null
	 */
	public static function getObjectByAttachment(array $attachment, $create = false)
	{
		$list = static::getObjectsByFileId($attachment['FILE_ID'], 1);
		$object = reset($list);

		if (empty($object) && $create)
		{
			$object = static::registerAttachment($attachment);
		}

		return $object;
	}

	/**
	 * Creates disk object for attachment file
	 *
	 * @param array $attachment Attachment file data.
	 * @return \Bitrix\Disk\File|false|null
	 */
	public static function registerAttachment(array $attachment)
	{
		$storage = static::getStorage();

		if (!$storage)
		{
			return false;
		}

		$folder = $storage->getChild(array(
			'=NAME' => date('Y-m'),
			'=TYPE' => \Bitrix\Disk\Internals\FolderTable::TYPE,
		));

		if (!$folder)
		{
			$folder = $storage->addFolder(array(
				'NAME' => date('Y-m'),
				'CREATED_BY' => 1, // @TODO
			));
		}

		if (!$folder)
		{
			$folder = $storage;
		}

		return $folder->addFile(
			array(
				'NAME' => \Bitrix\Disk\Ui\Text::correctFilename($attachment['FILE_NAME']) ?: sprintf('%x', rand(0, 0xffffff)),
				'FILE_ID' => $attachment['FILE_ID'],
				'SIZE' => $attachment['FILE_SIZE'],
				'CREATED_BY' => 1, // @TODO
			),
			array(),
			true
		);
	}

	/**
	 * Deletes disk objects by file ID
	 *
	 * @param int $fileId File ID.
	 * @return void
	 */
	public static function unregisterAttachment($fileId)
	{
		foreach (static::getObjectsByFileId($fileId) as $item)
		{
			$item->delete(1); // @TODO
		}
	}

}

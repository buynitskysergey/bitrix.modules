<?php

namespace Bitrix\DocumentGenerator\Model;

use Bitrix\Main\Entity\DataManager;
use Bitrix\Main;
use Bitrix\Main\Diag\LoggerFactory;
use Bitrix\Main\ORM\Event;

abstract class FileModel extends DataManager
{
	protected static $fileFieldNames = [
		'FILE_ID',
	];
	protected static $filesToDelete = [];

	/**
	 * @param Event $event
	 * @return Main\Entity\EventResult
	 * @throws Main\ArgumentException
	 * @throws Main\ObjectPropertyException
	 * @throws Main\SystemException
	 */
	public static function onBeforeUpdate(Event $event)
	{
		$parameters = $event->getParameters();
		$newFields = $parameters['fields'];
		$oldFields = static::getById($parameters['primary']['ID'])->fetch();
		foreach(static::$fileFieldNames as $name)
		{
			if(array_key_exists($name, $newFields) && $newFields[$name] != $oldFields[$name])
			{
				static::$filesToDelete[] = [
					'fileId' => $oldFields[$name],
					'entityClass' => static::class,
				];
			}
		}
		return new Main\Entity\EventResult();
	}

	/**
	 * @param Event $event
	 * @return Main\EventResult
	 */
	public static function onBeforeDelete(Event $event)
	{
		$result = new Main\Entity\EventResult();
		$eventData = $event->getParameters();
		$data = static::getById($eventData['primary']['ID'])->fetch();
		foreach(static::$fileFieldNames as $name)
		{
			if($data[$name])
			{
				static::$filesToDelete[] = [
					'fileId' => $data[$name],
					'entityClass' => static::class,
				];
			}
		}
		return $result;
	}

	/**
	 * @param Event $event
	 * @return Main\Entity\EventResult
	 * @throws \Exception
	 */
	public static function onAfterUpdate(Event $event)
	{
		return static::deleteFiles();
	}

	/**
	 * @param Event $event
	 * @return Main\Entity\EventResult
	 * @throws \Exception
	 */
	public static function onAfterDelete(Event $event)
	{
		return static::deleteFiles();
	}

	/**
	 * @return Main\Entity\EventResult
	 * @throws \Exception
	 */
	protected static function deleteFiles()
	{
		$result = new Main\Entity\EventResult();
		$logger = null;
		foreach(static::$filesToDelete as $fileToDelete)
		{
			if (!is_array($fileToDelete))
			{
				continue;
			}

			$fileId = (int)($fileToDelete['fileId'] ?? 0);
			$entityClass = (string)($fileToDelete['entityClass'] ?? '');
			if($fileId > 0)
			{
				$deleteResult = FileTable::delete($fileId);
				if(!$deleteResult->isSuccess())
				{
					foreach ($deleteResult->getErrors() as $error)
					{
						$result->addError($error);
					}

					$logger ??= (new LoggerFactory())->createById(
						'documentgenerator.Default',
						isCheckEnabledFromRegistry: false
					);
					$logger->error(
						'Could not delete file {fileId} queued by {entityClass}. Errors: {errors}',
						[
							'fileId' => $fileId,
							'entityClass' => $entityClass,
							'errors' => implode('; ', $deleteResult->getErrorMessages()),
						]
					);
				}
			}
		}
		static::$filesToDelete = [];
		return $result;
	}
}

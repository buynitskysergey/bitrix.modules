<?php
declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Integration\Rag\Service;

use Bitrix\Bizproc\Error;
use Bitrix\Bizproc\Public\Feature\Rag\KnowledgeBaseFlag;
use Bitrix\Bizproc\Result;
use Bitrix\Main\Config\Feature;
use Bitrix\Main\Config\Option;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;
use Bitrix\Main\SystemException;
use Bitrix\Rag\Public\Service\FileKnowledgeBasePublicService;

class RagService
{
	private const RAG_MODULE_ID = 'rag';
	private const DEFAULT_MAX_SIZE = 1024 * 1024 * 5;
	private const DEFAULT_MAX_FILES_COUNT = 50;
	private const DEFAULT_MAX_BASES_COUNT = 5;

	public function isAvailable(): bool
	{
		return $this->isFeatureAvailable()
			&& Loader::includeModule(self::RAG_MODULE_ID)
			&& class_exists('\Bitrix\Rag\Public\Service\KnowledgeBasePublicService')
		;
	}

	public function createErrorModuleResult(): Result
	{
		return Result::createFromErrorCode(Error::MODULE_NOT_INSTALLED, ['moduleName' => self::RAG_MODULE_ID]);
	}

	/**
	 * @return array<string>
	 */
	public function getAcceptedFileTypes(): array
	{
		if (!$this->isAvailable())
		{
			return [];
		}

		try
		{
			/** @var FileKnowledgeBasePublicService $fileService */
			$fileService = ServiceLocator::getInstance()->get(FileKnowledgeBasePublicService::class);

			return $fileService->getFileUploadConfig()->extensions;
		}
		catch (SystemException)
		{
			return [];
		}
	}

	public function getMaxFileSize(): int
	{
		return (int)Option::get('bizproc', 'rag_max_file_size', self::DEFAULT_MAX_SIZE);
	}

	public function getMaxFilesCount(): int
	{
		return (int)Option::get('bizproc', 'rag_max_files_count', self::DEFAULT_MAX_FILES_COUNT);
	}

	public function getMaxBasesCountPerField(): int
	{
		return (int)Option::get('bizproc', 'rag_max_bases_count', self::DEFAULT_MAX_BASES_COUNT);
	}

	private function isFeatureAvailable(): bool
	{
		return Feature::isEnabled(KnowledgeBaseFlag::class);
	}
}
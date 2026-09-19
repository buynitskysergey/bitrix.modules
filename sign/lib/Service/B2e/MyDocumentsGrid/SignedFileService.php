<?php

namespace Bitrix\Sign\Service\B2e\MyDocumentsGrid;

use Bitrix\Sign\Item\EntityFile;
use Bitrix\Sign\Item\Fs;
use Bitrix\Sign\Item\MyDocumentsGrid\File;
use Bitrix\Sign\Operation\GetSignedB2eFileUrl;
use Bitrix\Sign\Repository\EntityFileRepository;
use Bitrix\Sign\Repository\FileRepository;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Type\EntityFileCode;
use Bitrix\Sign\Type\EntityType;

class SignedFileService
{
	private readonly EntityFileRepository $entityFileRepository;
	private readonly FileRepository $fileRepository;

	public function __construct(
		?EntityFileRepository $entityFileRepository = null,
		?FileRepository $fileRepository = null,
	)
	{
		$container = Container::instance();
		$this->entityFileRepository = $entityFileRepository ?? $container->getEntityFileRepository();
		$this->fileRepository = $fileRepository ?? $container->getFileRepository();
	}

	/**
	 * Reads signed files of the given members in two queries regardless of the member count.
	 *
	 * @param list<int> $memberIds
	 * @return array<int, ?File> file data by member id, null for a member without a downloadable signed file
	 */
	public function listByMemberIds(array $memberIds): array
	{
		$memberIds = array_values(array_unique(array_filter(
			$memberIds,
			static fn (int $memberId): bool => $memberId > 0,
		)));
		if ($memberIds === [])
		{
			return [];
		}

		$entityFilesByMemberId = [];
		$entityFiles = $this->entityFileRepository->listByEntityIdsAndCode(
			EntityType::MEMBER,
			$memberIds,
			EntityFileCode::SIGNED,
		);
		foreach ($entityFiles as $entityFile)
		{
			// a member may have several signed files: keep the first one by id, as the per-member read did
			$known = $entityFilesByMemberId[$entityFile->entityId] ?? null;
			if ($known === null || (int)$entityFile->id < (int)$known->id)
			{
				$entityFilesByMemberId[$entityFile->entityId] = $entityFile;
			}
		}

		$fileIds = [];
		foreach ($entityFilesByMemberId as $entityFile)
		{
			if ($entityFile->fileId > 0)
			{
				$fileIds[] = $entityFile->fileId;
			}
		}

		$filesById = [];
		foreach ($this->fileRepository->listByIds($fileIds) as $file)
		{
			$filesById[$file->id] = $file;
		}

		$result = [];
		foreach ($memberIds as $memberId)
		{
			$entityFile = $entityFilesByMemberId[$memberId] ?? null;
			$result[$memberId] = $entityFile === null
				? null
				: $this->makeFile($memberId, $entityFile, $filesById[$entityFile->fileId] ?? null)
			;
		}

		return $result;
	}

	public function getByMemberId(int $memberId): ?File
	{
		return $this->listByMemberIds([$memberId])[$memberId] ?? null;
	}

	private function makeFile(int $memberId, EntityFile $entityFile, ?Fs\File $file): ?File
	{
		$result = GetSignedB2eFileUrl::createByPreloadedFile(
			EntityType::MEMBER,
			$memberId,
			EntityFileCode::SIGNED,
			$entityFile,
			$file,
		)->launch();

		$data = $result->getData();
		if (!$result->isSuccess() || !isset($data['ext'], $data['url']))
		{
			return null;
		}

		return new File(
			EntityFileCode::SIGNED,
			$data['ext'],
			$data['url'],
		);
	}
}

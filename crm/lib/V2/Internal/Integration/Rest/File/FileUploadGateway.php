<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\Rest\File;

use Bitrix\Crm\FileUploader\EntityFieldController;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Crm\V2\Internal\Integration\UI\File\PendingFileGateway;
use Bitrix\Crm\V2\Internal\Service\Item\File\Value\FileUploadInput;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\SystemException;
use Bitrix\Main\Validation\Group\ValidationGroup;
use Bitrix\Rest\V3\Attribute\RequiredGroup;
use Bitrix\Rest\V3\Dto\DtoValidatorHelper;
use Bitrix\Rest\V3\Exception\Validation\InvalidFileException;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;
use Bitrix\Rest\V3\Realisation\Dto\FileDto;
use Bitrix\Rest\V3\Realisation\Dto\UploadFileDto;
use Bitrix\Rest\V3\Realisation\FileDtoProcessor;
use Bitrix\UI\FileUploader\UploadResult;
use Bitrix\UI\FileUploader\Uploader;

final class FileUploadGateway
{
	private const ERROR_PENDING_UPLOAD_NOT_REGISTERED = 'PENDING_UPLOAD_NOT_REGISTERED';
	private const ERROR_PENDING_UPLOAD_FILE_ID_MISMATCH = 'PENDING_UPLOAD_FILE_ID_MISMATCH';

	private readonly \Closure $entityFieldControllerFactory;
	private readonly \Closure $fileDtoProcessorFactory;
	private readonly \Closure $pendingFileGatewayFactory;

	/**
	 * @var array<string, array{
	 *     fileId: int,
	 *     gateway: PendingFileGateway,
	 *     processor: object
	 * }>
	 */
	private array $pendingUploads = [];

	public function __construct(
		private readonly ?RestIntegrationFactory $restIntegrationFactory = null,
		?callable $entityFieldControllerFactory = null,
		?callable $fileDtoProcessorFactory = null,
		?callable $pendingFileGatewayFactory = null,
	)
	{
		$this->entityFieldControllerFactory = $entityFieldControllerFactory !== null
			? \Closure::fromCallable($entityFieldControllerFactory)
			: static fn(
				array $options,
				UserPermissions $userPermissions,
			): EntityFieldController => new EntityFieldController($options, $userPermissions)
		;
		$this->fileDtoProcessorFactory = $fileDtoProcessorFactory !== null
			? \Closure::fromCallable($fileDtoProcessorFactory)
			: static fn(
				EntityFieldController $controller,
			): FileDtoProcessor => new FileDtoProcessor($controller)
		;
		$this->pendingFileGatewayFactory = $pendingFileGatewayFactory !== null
			? \Closure::fromCallable($pendingFileGatewayFactory)
			: static fn(
				Uploader $uploader,
			): PendingFileGateway => new PendingFileGateway($uploader)
		;
	}

	public function canUpload(
		int $entityTypeId,
		int $entityId,
		?int $categoryId,
		string $fieldName,
		UserPermissions $userPermissions,
	): bool
	{
		$controller = $this->createEntityFieldController(
			$entityTypeId,
			$entityId,
			$categoryId,
			$fieldName,
			$userPermissions,
		);

		return $controller->canUpload();
	}

	/**
	 * @return array{token: string, fileId: int}
	 * @throws SystemException
	 */
	public function upload(
		FileUploadInput $input,
		int $entityTypeId,
		int $entityId,
		?int $categoryId,
		string $fieldName,
		UserPermissions $userPermissions,
	): array
	{
		return $this->uploadMany(
			[$input],
			$entityTypeId,
			$entityId,
			$categoryId,
			$fieldName,
			$userPermissions,
		)[0];
	}

	/**
	 * @param array<int, FileUploadInput> $inputs
	 *
	 * @return array<int, array{token: string, fileId: int}>
	 */
	public function uploadMany(
		array $inputs,
		int $entityTypeId,
		int $entityId,
		?int $categoryId,
		string $fieldName,
		UserPermissions $userPermissions,
	): array
	{
		($this->restIntegrationFactory ?? new RestIntegrationFactory())->requireFileContract();

		$controller = $this->createEntityFieldController(
			$entityTypeId,
			$entityId,
			$categoryId,
			$fieldName,
			$userPermissions,
		);
		$processor = ($this->fileDtoProcessorFactory)($controller);
		$pendingFileGateway = ($this->pendingFileGatewayFactory)($processor->uploader);
		$uploads = [];
		try
		{
			foreach ($inputs as $index => $input)
			{
				$uploadDto = UploadFileDto::create();
				$uploadDto->name = $input->name;
				if ($input->data !== null)
				{
					$uploadDto->data = $input->data;
				}
				if ($input->url !== null)
				{
					$uploadDto->url = $input->url;
				}
				$fileDto = FileDto::create();
				$fileDto->upload = $uploadDto;
				try
				{
					$processor->processDto($fileDto);
				}
				catch (InvalidFileException $exception)
				{
					$this->throwFileValidationException($exception, $input->path);
				}

				$uploadResult = $uploadDto->getResult();
				$fileId = $uploadResult instanceof UploadResult ? $uploadResult->getFileInfo()?->getFileId() ?? 0 : 0;
				$token = $uploadResult instanceof UploadResult ? $uploadResult->getToken() : null;
				if (
					!$uploadResult instanceof UploadResult
					|| !$uploadResult->isSuccess()
					|| !is_string($token)
					|| trim($token) === ''
					|| $fileId <= 0
					|| isset($this->pendingUploads[$token])
				)
				{
					$this->throwInvalidUploadResult();
				}

				$uploads[$index] = ['token' => $token, 'fileId' => $fileId];
				$this->pendingUploads[$token] = [
					'fileId' => $fileId,
					'gateway' => $pendingFileGateway,
					'processor' => $processor,
				];
			}
		}
		catch (\Throwable $throwable)
		{
			foreach ($uploads as $upload)
			{
				try
				{
					$removeResult = $pendingFileGateway->removeAndVerify($upload['token'], $upload['fileId']);
					if ($removeResult->isSuccess())
					{
						unset($this->pendingUploads[$upload['token']]);
					}
				}
				catch (\Throwable)
				{
				}
			}

			throw $throwable;
		}

		return $uploads;
	}

	private function throwFileValidationException(InvalidFileException $exception, string $path): never
	{
		$validation = $exception->output()['validation'] ?? [];
		$errors = [];
		foreach ($validation as $item)
		{
			$errors[] = new Error(
				is_string($item['message'] ?? null) ? $item['message'] : $exception->getMessage(),
				$path,
			);
		}

		if ($errors === [])
		{
			$errors[] = new Error($exception->getMessage(), $path);
		}

		throw new RequestValidationException($errors);
	}

	public function makePersistentAndVerify(string $token, int $expectedFileId): Result
	{
		return $this->transitionAndVerify(
			$token,
			$expectedFileId,
			static fn(PendingFileGateway $gateway): Result => $gateway->makePersistentAndVerify(
				$token,
				$expectedFileId,
			),
		);
	}

	public function removeAndVerify(string $token, int $expectedFileId): Result
	{
		return $this->transitionAndVerify(
			$token,
			$expectedFileId,
			static fn(PendingFileGateway $gateway): Result => $gateway->removeAndVerify(
				$token,
				$expectedFileId,
			),
		);
	}

	/**
	 * @param list<array{token: string, fileId: int}> $uploads
	 */
	public function makePersistentAndVerifyMany(array $uploads): Result
	{
		$gateways = [];
		foreach ($uploads as $upload)
		{
			$pendingUpload = $this->pendingUploads[$upload['token']] ?? null;
			if ($pendingUpload === null || $pendingUpload['fileId'] !== $upload['fileId'])
			{
				return (new Result())->addError(new Error(
					'Pending upload is not registered.',
					self::ERROR_PENDING_UPLOAD_NOT_REGISTERED,
				));
			}
			$gateways[spl_object_id($pendingUpload['gateway'])]['gateway'] = $pendingUpload['gateway'];
			$gateways[spl_object_id($pendingUpload['gateway'])]['uploads'][] = $upload;
		}

		foreach ($gateways as $group)
		{
			$result = $group['gateway']->makePersistentAndVerifyMany($group['uploads']);
			if (!$result->isSuccess())
			{
				return $result;
			}
		}
		foreach ($uploads as $upload)
		{
			unset($this->pendingUploads[$upload['token']]);
		}

		return new Result();
	}

	public function createInput(mixed $value, string $path): FileUploadInput
	{
		($this->restIntegrationFactory ?? new RestIntegrationFactory())->requireFileContract();

		/** @var FileDto $fileDto */
		$fileDto = FileDtoConverter::convert($value, $path);
		$validationResult = DtoValidatorHelper::validate(
			$fileDto,
			ValidationGroup::create(RequiredGroup::Add),
		);
		if (!$validationResult->isSuccess())
		{
			$errors = [];
			foreach ($validationResult->getErrors() as $error)
			{
				$errorPath = $path;
				if (is_string($error->getCode()) && $error->getCode() !== '')
				{
					$errorPath .= '.' . $error->getCode();
				}
				$errors[] = new Error(
					$error->getLocalizableMessage() ?? $error->getMessage(),
					$errorPath,
				);
			}

			throw new RequestValidationException($errors);
		}
		if (($fileDto->upload->url ?? null) !== null)
		{
			throw new RequestValidationException([
				new Error(
					'URL file uploads are temporarily unavailable.',
					$path . '.upload.url',
				),
			]);
		}

		return new FileUploadInput(
			$fileDto->upload->name,
			$fileDto->upload->data ?? null,
			$fileDto->upload->url ?? null,
			$path,
		);
	}

	private function createEntityFieldController(
		int $entityTypeId,
		int $entityId,
		?int $categoryId,
		string $fieldName,
		UserPermissions $userPermissions,
	): object
	{
		return ($this->entityFieldControllerFactory)([
			'entityTypeId' => $entityTypeId,
			'entityId' => $entityId,
			'categoryId' => $categoryId,
			'fieldName' => $fieldName,
		], $userPermissions);
	}

	private function throwInvalidUploadResult(): never
	{
		throw new SystemException('Unexpected file upload result.');
	}

	private function transitionAndVerify(
		string $token,
		int $expectedFileId,
		\Closure $transition,
	): Result
	{
		$pendingUpload = $this->pendingUploads[$token] ?? null;
		if ($pendingUpload === null)
		{
			return (new Result())->addError(
				new Error(
					'Pending upload is not registered.',
					self::ERROR_PENDING_UPLOAD_NOT_REGISTERED,
				),
			);
		}
		if ($pendingUpload['fileId'] !== $expectedFileId)
		{
			return (new Result())->addError(
				new Error(
					'Pending upload file id does not match.',
					self::ERROR_PENDING_UPLOAD_FILE_ID_MISMATCH,
				),
			);
		}

		$result = $transition($pendingUpload['gateway']);
		if ($result->isSuccess())
		{
			unset($this->pendingUploads[$token]);
		}

		return $result;
	}
}

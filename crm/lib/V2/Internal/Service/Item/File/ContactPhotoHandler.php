<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Item\File;

use Bitrix\Crm\Field;
use Bitrix\Crm\Item as LegacyItem;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Crm\V2\Internal\Service\Item\File\Value\FileFieldWriteRequest;
use Bitrix\Crm\V2\Public\Entity\File;
use Bitrix\Crm\V2\Public\Entity\Item\Item;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

final class ContactPhotoHandler implements SystemFileFieldHandler
{
	public function getRestFieldName(): string
	{
		return 'photo';
	}

	public function getFieldName(): string
	{
		return 'PHOTO';
	}

	public function isMultiple(): bool
	{
		return false;
	}

	public function supports(EntityType $entityType, string $fieldName): bool
	{
		return $entityType->getId() === EntityType::contact()->getId() && $fieldName === $this->getFieldName();
	}

	public function checkAccess(
		EntityType $entityType,
		?int $userId,
		LegacyItem $legacyItem,
		string $action,
		FileFieldWriteRequest $request,
	): Result
	{
		if (!$this->supports($entityType, $request->fieldName))
		{
			return $this->error('The system file field is not supported.');
		}
		if (!in_array($action, [FileAccessChecker::ACTION_ADD, FileAccessChecker::ACTION_UPDATE], true))
		{
			return $this->error('The file write action is not supported.');
		}

		$field = Container::getInstance()->getFactory($entityType->getId())
			?->getFieldsCollection()
			->getField($this->getFieldName())
		;
		if (!$field instanceof Field)
		{
			return $this->error('The CRM photo field does not exist.');
		}
		if ($legacyItem->isFieldDisabled($this->getFieldName()))
		{
			return $this->success($field, [], true);
		}

		return $this->success($field, $this->normalizeFileIds($legacyItem->get($this->getFieldName())), false);
	}

	public function process(
		Field $field,
		LegacyItem $legacyItem,
		FileFieldWriteRequest $request,
		UserPermissions $userPermissions,
		object $context,
	): Result
	{
		return (new Result())->setData(['survived' => true]);
	}

	public function applyFinalState(Item $item, FileFieldWriteRequest $request, array $uploadedFileIds): void
	{
		$fileId = $uploadedFileIds[$this->getFieldName()][0] ?? null;
		$item->setPhoto(is_int($fileId) && $fileId > 0 ? File::fromId($fileId) : null);
	}

	private function success(Field $field, array $currentFileIds, bool $skipped): Result
	{
		return (new Result())->setData([
			'field' => $field,
			'currentFileIds' => $currentFileIds,
			'skipped' => $skipped,
		]);
	}

	private function error(string $message): Result
	{
		return (new Result())->addError(new Error($message, $this->getFieldName()));
	}

	/** @return list<int> */
	private function normalizeFileIds(mixed $value): array
	{
		$result = [];
		foreach (is_array($value) ? $value : [$value] as $candidate)
		{
			$fileId = is_int($candidate)
				? $candidate
				: (is_string($candidate)
					&& $candidate !== ''
					&& strspn($candidate, '0123456789') === strlen($candidate)
					? (int)$candidate
					: 0
				);
			if ($fileId > 0)
			{
				$result[$fileId] = $fileId;
			}
		}

		return array_values($result);
	}
}

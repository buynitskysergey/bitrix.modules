<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure;

use Bitrix\Crm\V2\Internal\Service\Item\File\Value\FileFieldWriteRequest;

final class FileFieldInputBag
{
	/** @var array<string, array{multiple: bool, rawValue: mixed, request: FileFieldWriteRequest}> */
	private array $entries = [];

	public function add(
		string $fieldName,
		bool $multiple,
		mixed $rawValue,
		FileFieldWriteRequest $request,
	): void
	{
		$this->entries[$fieldName] = [
			'multiple' => $multiple,
			'rawValue' => $rawValue,
			'request' => $request,
		];
	}

	public function has(string $fieldName): bool
	{
		return array_key_exists($fieldName, $this->entries);
	}

	public function isMultiple(string $fieldName): bool
	{
		return $this->entries[$fieldName]['multiple'];
	}

	public function getRawValue(string $fieldName): mixed
	{
		return $this->entries[$fieldName]['rawValue'];
	}

	/**
	 * @return array<string, FileFieldWriteRequest>
	 */
	public function getWriteRequests(): array
	{
		return array_map(
			static fn(array $entry): FileFieldWriteRequest => $entry['request'],
			$this->entries,
		);
	}
}

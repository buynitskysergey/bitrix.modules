<?php

namespace Bitrix\HumanResources\Rest\View;

use Bitrix\Rest\Integration\View\Attributes;
use Bitrix\Rest\Integration\View\DataType;
use Bitrix\Rest\Integration\View\Base;

final class Structure extends Base
{
	public function getFields(): array
	{
		return [
			'ID' => [
				'TYPE' => DataType::TYPE_INT,
				'ATTRIBUTES' => [
					Attributes::READONLY,
				],
			],
			'NAME' => [
				'TYPE' => DataType::TYPE_STRING,
				'ATTRIBUTES' => [
					Attributes::REQUIRED_ADD,
				],
			],
			'TYPE' => [
				'TYPE' => DataType::TYPE_STRING,
				'ATTRIBUTES' => [
					Attributes::REQUIRED_ADD,
				],
			],
			'XML_ID' => [
				'TYPE' => DataType::TYPE_STRING,
				'ATTRIBUTES' => [
					Attributes::REQUIRED_ADD,
				],
			],
			'CREATED_BY' => [
				'TYPE' => DataType::TYPE_INT,
				'ATTRIBUTES' => [
					Attributes::READONLY,
				],
			],
			'CREATED_AT' => [
				'TYPE' => DataType::TYPE_DATETIME,
				'ATTRIBUTES' => [
					Attributes::READONLY,
				],
			],
			'UPDATED_AT' => [
				'TYPE' => DataType::TYPE_DATETIME,
				'ATTRIBUTES' => [
					Attributes::READONLY,
				],
			],
		];
	}
}
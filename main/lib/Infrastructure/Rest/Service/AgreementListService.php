<?php

namespace Bitrix\Main\Infrastructure\Rest\Service;

use Bitrix\Main\UserConsent\Internals\AgreementTable;

class AgreementListService
{
	private const ALLOWED_SELECT = [
		'ID',
		'NAME',
		'ACTIVE',
		'LANGUAGE_ID',
	];

	/**
	 * @param list<string> $select
	 * @param array<string, string> $order
	 * @param array<string, mixed> $filter
	 * @return list<array<string, mixed>>
	 */
	public function getList(
		array $select = [],
		array $order = [],
		array $filter = [],
		?int $limit = null,
		?int $offset = null,
	): array
	{
		$params = [
			'select' => $this->normalizeSelect($select),
			'order' => $order !== [] ? $order : ['ID' => 'DESC'],
			'filter' => $filter,
		];

		if ($limit !== null)
		{
			$params['limit'] = $limit;
		}
		if ($offset !== null)
		{
			$params['offset'] = $offset;
		}

		return AgreementTable::getList($params)->fetchAll();
	}

	/**
	 * @param list<string> $select
	 * @return list<string>
	 */
	private function normalizeSelect(array $select): array
	{
		if ($select === [])
		{
			return self::ALLOWED_SELECT;
		}

		$allowed = array_fill_keys(self::ALLOWED_SELECT, true);
		$result = [];
		foreach ($select as $field)
		{
			$field = strtoupper((string)$field);
			if (isset($allowed[$field]))
			{
				$result[] = $field;
			}
		}

		return $result !== [] ? array_values(array_unique($result)) : self::ALLOWED_SELECT;
	}
}

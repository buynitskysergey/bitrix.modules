<?php

namespace Bitrix\Main\Infrastructure\Rest\Service;

use Bitrix\Main\UserProfileRecordTable;
use Bitrix\Rest\RestException;

class HistoryFieldsService
{
	/**
	 * @param array{
	 *   filter?: array<string, mixed>,
	 *   order?: array<string, string>
	 * } $query
	 * @return list<array<string, mixed>>
	 */
	public function getList(array $query): array
	{
		global $USER;

		$query = array_change_key_case($query, CASE_LOWER);
		$filter = $query['filter'] ?? [];
		$order = $query['order'] ?? ['ID' => 'ASC'];

		$queryFilter = $this->sanitizeFilter($filter);
		if (!isset($queryFilter['=HISTORY_ID']))
		{
			throw new RestException(
				'HISTORY_ID filter field is required.',
				RestException::ERROR_ARGUMENT,
				\CRestServer::STATUS_WRONG_REQUEST,
			);
		}

		if (!$USER->CanDoOperation('edit_all_users'))
		{
			$queryFilter['=HISTORY.USER_ID'] = $USER->GetID();
		}

		$order = $this->sanitizeOrder($order);

		return UserProfileRecordTable::getList([
			'filter' => $queryFilter,
			'order' => $order,
		])->fetchAll();
	}

	/**
	 * @param array<string, mixed> $filter
	 * @return array<string, mixed>
	 */
	private function sanitizeFilter(array $filter): array
	{
		$allowed = ['HISTORY_ID' => true, 'FIELD' => true];
		$result = [];

		foreach ($filter as $key => $value)
		{
			$key = (string)$key;
			$operation = '=';
			$field = $key;
			if (preg_match('/^([=!<>@%*]+)(.+)$/', $key, $matches))
			{
				$operation = $matches[1];
				$field = $matches[2];
			}

			$field = strtoupper($field);
			if (!isset($allowed[$field]))
			{
				continue;
			}

			if ($operation !== '=')
			{
				throw new RestException(
					"Only '=' operation is allowed for the filter field {$field}.",
					RestException::ERROR_ARGUMENT,
					\CRestServer::STATUS_WRONG_REQUEST,
				);
			}

			$result['=' . $field] = $value;
		}

		return $result;
	}

	/**
	 * @param array<string, string> $order
	 * @return array<string, string>
	 */
	private function sanitizeOrder(array $order): array
	{
		$result = [];
		foreach ($order as $field => $direction)
		{
			$field = strtoupper((string)$field);
			if ($field !== 'ID')
			{
				continue;
			}

			$direction = strtoupper((string)$direction);
			$result[$field] = $direction === 'DESC' ? 'DESC' : 'ASC';
		}

		return $result !== [] ? $result : ['ID' => 'ASC'];
	}
}

<?php

namespace Bitrix\Main\Infrastructure\Rest\Service;

use Bitrix\Main\Error;
use Bitrix\Main\UserConsent\Agreement;
use Bitrix\Main\UserConsent\Internals\ConsentTable;
use Bitrix\Rest\RestException;

class ConsentService
{
	/**
	 * @param array{
	 *   AGREEMENT_ID?: mixed,
	 *   USER_ID?: mixed,
	 *   IP?: mixed,
	 *   URL?: mixed,
	 *   ORIGIN_ID?: mixed,
	 *   ORIGINATOR_ID?: mixed
	 * } $fields
	 */
	public function add(array $fields): int
	{
		$fields = array_change_key_case($fields, CASE_UPPER);
		$agreementId = $fields['AGREEMENT_ID'] ?? null;
		$this->getAgreementById($agreementId);

		$result = ConsentTable::add([
			'AGREEMENT_ID' => $agreementId,
			'USER_ID' => $fields['USER_ID'] ?? null,
			'IP' => $fields['IP'] ?? null,
			'URL' => $fields['URL'] ?? null,
			'ORIGIN_ID' => $fields['ORIGIN_ID'] ?? null,
			'ORIGINATOR_ID' => $fields['ORIGINATOR_ID'] ?? null,
		]);

		if (!$result->isSuccess())
		{
			$this->throwErrors($result->getErrors());
		}

		return (int)$result->getId();
	}

	private function getAgreementById(null|int|string $id): Agreement
	{
		$agreement = new Agreement($id);
		if ($agreement->hasErrors())
		{
			$this->throwErrors($agreement->getErrors());
		}

		return $agreement;
	}

	/**
	 * @param Error[] $errors
	 */
	private function throwErrors(array $errors): void
	{
		foreach ($errors as $error)
		{
			throw new RestException(
				$error->getMessage(),
				RestException::ERROR_ARGUMENT,
				\CRestServer::STATUS_WRONG_REQUEST,
			);
		}
	}
}

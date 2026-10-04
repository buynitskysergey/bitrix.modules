<?php

namespace Bitrix\Main\Infrastructure\Rest\Service;

use Bitrix\Main\Error;
use Bitrix\Main\UserConsent\Agreement;
use Bitrix\Rest\RestException;

class AgreementService
{
	/**
	 * @return array{
	 *   ID: int,
	 *   NAME: ?string,
	 *   ACTIVE: ?string,
	 *   LANGUAGE_ID: ?string,
	 *   LABEL: string,
	 *   TEXT: string
	 * }
	 */
	public function getById(null|int|string $id, array $replace = []): array
	{
		$agreement = $this->getAgreement($id);
		$agreement->setReplace($replace);
		$data = $agreement->getData();

		return [
			'ID' => (int)$agreement->getId(),
			'NAME' => $data['NAME'] ?? null,
			'ACTIVE' => $data['ACTIVE'] ?? null,
			'LANGUAGE_ID' => $data['LANGUAGE_ID'] ?? null,
			'LABEL' => $agreement->getLabelText(),
			'TEXT' => $agreement->getText(),
		];
	}

	private function getAgreement(null|int|string $id): Agreement
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

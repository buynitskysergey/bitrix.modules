<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Diagnostic;

use Bitrix\Main\Result;

final class VibeDemoAuditLog
{
	public const AUDIT_TYPE_ID = 'VIBECODECONNECTOR_VIBE_DEMO_ACTIVATION';

	private const MAX_ISSUER_LENGTH = 255;
	private const MAX_EXPIRE_DATE_LENGTH = 64;
	private const MAX_ERROR_CODE_LENGTH = 64;
	private const MAX_ERROR_CLASS_LENGTH = 255;
	private const MAX_ERROR_MESSAGE_LENGTH = 2048;

	public function recordResult(?string $iss, ?int $days, bool $granted, Result $result): Result
	{
		if (!$result->isSuccess())
		{
			$this->write(\CEventLog::SEVERITY_INFO, 'REFUSED', [
				'iss' => $this->limitString($iss, self::MAX_ISSUER_LENGTH),
				'granted' => $granted,
				'days' => $days,
				'error_codes' => $this->errorCodes($result),
			]);

			return $result;
		}

		if ($granted)
		{
			$this->write(\CEventLog::SEVERITY_INFO, 'OK', [
				'iss' => $this->limitString($iss, self::MAX_ISSUER_LENGTH),
				'granted' => true,
				'days' => $days,
				'expire_date' => $this->nullableString(
					$result->getData(),
					'expireDate',
					self::MAX_EXPIRE_DATE_LENGTH,
				),
			]);
		}

		return $result;
	}

	public function recordResetRefusal(?string $iss, Result $result): Result
	{
		$this->write(\CEventLog::SEVERITY_INFO, 'REFUSED', [
			'iss' => $this->limitString($iss, self::MAX_ISSUER_LENGTH),
			'error_codes' => $this->errorCodes($result),
		]);

		return $result;
	}

	public function recordFailure(?string $iss, ?int $days, bool $granted, \Throwable $error): void
	{
		$this->write(\CEventLog::SEVERITY_SECURITY, 'FAIL', [
			'iss' => $this->limitString($iss, self::MAX_ISSUER_LENGTH),
			'granted' => $granted,
			'days' => $days,
			'error_class' => $this->limitString($error::class, self::MAX_ERROR_CLASS_LENGTH),
			'error' => $this->limitString($error->getMessage(), self::MAX_ERROR_MESSAGE_LENGTH),
		]);
	}

	public function recordReset(?string $iss, bool $reset): void
	{
		$this->write(\CEventLog::SEVERITY_INFO, 'RESET', [
			'iss' => $this->limitString($iss, self::MAX_ISSUER_LENGTH),
			'reset' => $reset,
		]);
	}

	private function write(string $severity, string $outcome, array $payload): void
	{
		try
		{
			\CEventLog::Log(
				$severity,
				self::AUDIT_TYPE_ID,
				'vibecodeconnector',
				$outcome,
				json_encode(
					$payload,
					JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
				),
			);
		}
		catch (\Throwable)
		{
		}
	}

	private function errorCodes(Result $result): array
	{
		$codes = [];
		foreach ($result->getErrors() as $error)
		{
			$code = (string)$error->getCode();
			if ($code !== '')
			{
				$codes[] = $this->limitString($code, self::MAX_ERROR_CODE_LENGTH);
			}
		}

		return $codes;
	}

	private function nullableString(array $data, string $key, int $maxLength): ?string
	{
		return isset($data[$key]) ? $this->limitString((string)$data[$key], $maxLength) : null;
	}

	private function limitString(?string $value, int $maxLength): ?string
	{
		return $value === null ? null : mb_substr($value, 0, $maxLength);
	}
}

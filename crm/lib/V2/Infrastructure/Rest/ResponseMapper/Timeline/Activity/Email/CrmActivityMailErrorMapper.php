<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\ResponseMapper\Timeline\Activity\Email;

use Bitrix\Crm\Activity\Email\Outgoing\MessageSender;
use Bitrix\Crm\V2\Infrastructure\Rest\Exception\Timeline\Activity\Email\CrmActivityMailRestException;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use CRestServer;

class CrmActivityMailErrorMapper
{
	public const INVALID_REQUEST = 'CRM_EMAIL_INVALID_REQUEST';
	public const INVALID_ENTITY = 'CRM_EMAIL_INVALID_ENTITY';
	public const ENTITY_NOT_FOUND = 'CRM_EMAIL_ENTITY_NOT_FOUND';
	public const ACTIVITY_NOT_FOUND = 'CRM_EMAIL_ACTIVITY_NOT_FOUND';
	public const ACCESS_DENIED = 'CRM_EMAIL_ACCESS_DENIED';
	public const MAIL_MODULE_UNAVAILABLE = 'CRM_EMAIL_MAIL_MODULE_UNAVAILABLE';
	public const INVALID_RECIPIENT = 'CRM_EMAIL_INVALID_RECIPIENT';
	public const RECIPIENT_BLACKLISTED = 'CRM_EMAIL_RECIPIENT_BLACKLISTED';
	public const TOO_MANY_RECIPIENTS = 'CRM_EMAIL_TOO_MANY_RECIPIENTS';
	public const SENDER_NOT_AVAILABLE = 'CRM_EMAIL_SENDER_NOT_AVAILABLE';
	public const INVALID_FROM = 'CRM_EMAIL_INVALID_FROM';
	public const SEND_FAILED = 'CRM_EMAIL_SEND_FAILED';

	public function throwOnFailure(Result $result): void
	{
		if ($result->isSuccess())
		{
			return;
		}

		$message = $this->getErrorMessage($result);
		$code = $this->resolveCode($result, $message);

		throw new CrmActivityMailRestException($code, $message, $this->resolveStatus($code));
	}

	public static function throwInvalidRequest(string $message): never
	{
		throw new CrmActivityMailRestException(
			self::INVALID_REQUEST,
			$message,
			CRestServer::STATUS_WRONG_REQUEST,
		);
	}

	private function getErrorMessage(Result $result): string
	{
		$message = implode("\n", $result->getErrorMessages());

		return $message !== '' ? $message : 'CRM email request failed.';
	}

	private function resolveCode(Result $result, string $message): string
	{
		foreach ($result->getErrors() as $error)
		{
			$mappedCode = $this->mapDomainCode($error);
			if ($mappedCode !== null)
			{
				return $mappedCode;
			}
		}

		return $this->resolveLegacyCode($message);
	}

	private function mapDomainCode(Error $error): ?string
	{
		return match ($error->getCode())
		{
			MessageSender::ERROR_SENDER_NOT_AVAILABLE => self::SENDER_NOT_AVAILABLE,
			MessageSender::ERROR_FROM_INVALID => self::INVALID_FROM,
			MessageSender::ERROR_RECIPIENT_BLACKLISTED => self::RECIPIENT_BLACKLISTED,
			default => null,
		};
	}

	private function resolveLegacyCode(string $message): string
	{
		$normalized = mb_strtolower($message);

		return match (true)
		{
			str_contains($normalized, 'positive integer') => self::INVALID_REQUEST,
			str_contains($normalized, 'unknown entitytypeid') => self::INVALID_ENTITY,
			str_contains($normalized, 'entity') && str_contains($normalized, 'not found') => self::ENTITY_NOT_FOUND,
			str_contains($normalized, 'not found') => self::ACTIVITY_NOT_FOUND,
			str_contains($normalized, 'access denied') => self::ACCESS_DENIED,
			str_contains($normalized, 'blacklist') => self::RECIPIENT_BLACKLISTED,
			str_contains($normalized, 'recipient') && str_contains($normalized, 'invalid') => self::INVALID_RECIPIENT,
			str_contains($normalized, 'too many') || str_contains($normalized, 'limit') => self::TOO_MANY_RECIPIENTS,
			str_contains($normalized, 'sender') => self::SENDER_NOT_AVAILABLE,
			str_contains($normalized, '"from"') || str_contains($normalized, 'from address') => self::INVALID_FROM,
			str_contains($normalized, 'mail') && str_contains($normalized, 'not available') => self::MAIL_MODULE_UNAVAILABLE,
			str_contains($normalized, 'send') => self::SEND_FAILED,
			default => self::INVALID_REQUEST,
		};
	}

	private function resolveStatus(string $code): string
	{
		return match ($code)
		{
			self::ACCESS_DENIED => CRestServer::STATUS_FORBIDDEN,
			self::ACTIVITY_NOT_FOUND, self::ENTITY_NOT_FOUND => CRestServer::STATUS_NOT_FOUND,
			default => CRestServer::STATUS_WRONG_REQUEST,
		};
	}
}

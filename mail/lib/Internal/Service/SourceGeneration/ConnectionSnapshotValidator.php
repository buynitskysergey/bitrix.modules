<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\SourceGeneration;

use Bitrix\Mail\Internals\Service\Mailbox\EmailNormalizer;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

final class ConnectionSnapshotValidator
{
	public const RESULT_SNAPSHOT = 'snapshot';
	public const ERROR_EMAIL_INVALID = 'MAIL_SOURCE_GENERATION_EMAIL_INVALID';
	public const ERROR_IMAP_INVALID = 'MAIL_SOURCE_GENERATION_IMAP_INVALID';
	public const ERROR_SMTP_INVALID = 'MAIL_SOURCE_GENERATION_SMTP_INVALID';

	public function __construct(
		private readonly EmailNormalizer $emailNormalizer = new EmailNormalizer(),
	)
	{
	}

	public function validate(array $input): Result
	{
		$result = new Result();
		$email = $this->emailNormalizer->normalize((string)($input['email'] ?? ''));

		if ($email === null)
		{
			return $result->addError(new Error('A valid mailbox email is expected', self::ERROR_EMAIL_INVALID));
		}

		$imap = $this->normalizeImap(is_array($input['imap'] ?? null) ? $input['imap'] : []);
		if ($imap === null)
		{
			return $result->addError(new Error('A complete IMAP connection is expected', self::ERROR_IMAP_INVALID));
		}

		$smtp = $this->normalizeSmtp(is_array($input['smtp'] ?? null) ? $input['smtp'] : []);
		if ($smtp === null)
		{
			return $result->addError(new Error('A complete SMTP connection is expected', self::ERROR_SMTP_INVALID));
		}

		return $result->setData([
			self::RESULT_SNAPSHOT => new ConnectionSnapshot($email, $imap, $smtp),
		]);
	}

	private function normalizeImap(array $imap): ?array
	{
		foreach (['serviceId', 'serviceName', 'server', 'port', 'useTls', 'login', 'password'] as $field)
		{
			if (!array_key_exists($field, $imap))
			{
				return null;
			}
		}

		$server = trim((string)($imap['server'] ?? ''));
		$login = trim((string)($imap['login'] ?? ''));
		$port = (int)($imap['port'] ?? 0);
		$tls = strtoupper(trim((string)($imap['useTls'] ?? 'N')));

		if (
			$server === ''
			|| $login === ''
			|| $port <= 0
			|| $port > 65535
			|| !in_array($tls, ['N', 'Y', 'S'], true)
		)
		{
			return null;
		}

		return [
			'SERVICE_ID' => (int)($imap['serviceId'] ?? 0),
			'SERVICE_NAME' => isset($imap['serviceName']) ? trim((string)$imap['serviceName']) : null,
			'SERVER' => $server,
			'PORT' => $port,
			'USE_TLS' => $tls,
			'LOGIN' => $login,
			'PASSWORD' => (string)($imap['password'] ?? ''),
		];
	}

	private function normalizeSmtp(array $smtp): ?array
	{
		foreach (['server', 'port', 'protocol', 'login', 'password'] as $field)
		{
			if (!array_key_exists($field, $smtp))
			{
				return null;
			}
		}

		$server = trim((string)($smtp['server'] ?? ''));
		$login = trim((string)($smtp['login'] ?? ''));
		$port = (int)($smtp['port'] ?? 0);
		$protocol = strtolower(trim((string)($smtp['protocol'] ?? '')));
		$limit = $smtp['limit'] ?? null;

		if (
			$server === ''
			|| $login === ''
			|| $port <= 0
			|| $port > 65535
			|| !in_array($protocol, ['smtp', 'smtps'], true)
			|| !array_key_exists('password', $smtp)
			|| ($limit !== null && (!is_int($limit) || $limit < 0))
		)
		{
			return null;
		}

		return [
			'SERVER' => $server,
			'PORT' => $port,
			'PROTOCOL' => $protocol,
			'LOGIN' => $login,
			'PASSWORD' => (string)$smtp['password'],
			'LIMIT' => $limit,
		];
	}
}

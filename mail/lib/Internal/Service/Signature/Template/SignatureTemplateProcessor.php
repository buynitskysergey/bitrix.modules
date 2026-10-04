<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\Signature\Template;

use Bitrix\Main\Application;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

final class SignatureTemplateProcessor
{
	public const CURRENT_VERSION = 1;

	public const ERROR_VERSION_UNSUPPORTED = 'SIGNATURE_TEMPLATE_VERSION_UNSUPPORTED';
	public const ERROR_CANONICALIZE_FAILED = 'SIGNATURE_TEMPLATE_CANONICALIZE_FAILED';
	public const ERROR_RESOLVE_FAILED = 'SIGNATURE_TEMPLATE_RESOLVE_FAILED';

	private SignatureTemplateProviderRegistry $registry;

	public function __construct(?SignatureTemplateProviderRegistry $registry = null)
	{
		$this->registry = $registry ?? new SignatureTemplateProviderRegistry([]);
	}

	public function canonicalize(string $html, int $version = self::CURRENT_VERSION): Result
	{
		return $this->process('canonicalize', $html, $version);
	}

	public function resolve(
		string $template,
		SignatureTemplateContext $context,
		int $version = self::CURRENT_VERSION,
	): Result
	{
		return $this->process('resolve', $template, $version, $context);
	}

	private function process(
		string $operation,
		string $template,
		int $version,
		?SignatureTemplateContext $context = null,
	): Result
	{
		if ($version !== self::CURRENT_VERSION)
		{
			return $this->failure(self::ERROR_VERSION_UNSUPPORTED);
		}
		if ($operation === 'resolve' && $context === null)
		{
			return $this->failure(self::ERROR_RESOLVE_FAILED);
		}

		$value = $template;
		foreach ($this->registry->getAll() as $provider)
		{
			try
			{
				$result = $operation === 'canonicalize'
					? $provider->canonicalize($value)
					: $provider->resolve($value, $context)
				;
			}
			catch (\Throwable $exception)
			{
				Application::getInstance()->getExceptionHandler()->writeToLog($exception);

				return $this->failure(
					$operation === 'canonicalize' ? self::ERROR_CANONICALIZE_FAILED : self::ERROR_RESOLVE_FAILED,
				);
			}

			if (!$result->isSuccess())
			{
				return $result;
			}

			$key = $operation === 'canonicalize' ? 'template' : 'html';
			$data = $result->getData();
			if (!array_key_exists($key, $data) || !is_string($data[$key]))
			{
				return $this->failure(
					$operation === 'canonicalize' ? self::ERROR_CANONICALIZE_FAILED : self::ERROR_RESOLVE_FAILED,
				);
			}
			$value = $data[$key];
		}

		$key = $operation === 'canonicalize' ? 'template' : 'html';

		return (new Result())->setData([$key => $value]);
	}

	private function failure(string $code): Result
	{
		$result = new Result();
		$result->addError(new Error($code, $code));

		return $result;
	}
}

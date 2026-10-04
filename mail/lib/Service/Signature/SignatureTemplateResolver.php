<?php

declare(strict_types=1);

namespace Bitrix\Mail\Service\Signature;

use Bitrix\Mail\Internal\Service\Signature\Template\Macro\SignatureMacroProvider;
use Bitrix\Mail\Internal\Service\Signature\Template\SignatureTemplateContext;
use Bitrix\Mail\Internal\Service\Signature\Template\SignatureTemplateProcessor;
use Bitrix\Mail\Internal\Service\Signature\Template\SignatureTemplateProcessorFactory;

final class SignatureTemplateResolver
{
	private SignatureTemplateProcessor $processor;

	public function __construct(?SignatureTemplateProcessor $processor = null)
	{
		$this->processor = $processor ?? SignatureTemplateProcessorFactory::create();
	}

	public function resolve(
		string $template,
		int $userId,
		string $senderEmail = '',
		string $senderName = '',
		int $mailboxId = 0,
	): string
	{
		return $this->resolveInContext(
			$template,
			new SignatureTemplateContext($userId, $mailboxId, $senderEmail, $senderName),
		) ?? '';
	}

	public function resolveInContext(string $template, SignatureTemplateContext $context): ?string
	{
		$result = $this->processor->resolve($template, $context);
		if ($result->isSuccess())
		{
			return (string)($result->getData()['html'] ?? '');
		}

		$errors = $result->getErrors();
		if ($errors === [])
		{
			return null;
		}

		foreach ($errors as $error)
		{
			if ($error->getCode() !== SignatureMacroProvider::ERROR_INVALID_CONTEXT)
			{
				return null;
			}
		}

		return $template;
	}
}

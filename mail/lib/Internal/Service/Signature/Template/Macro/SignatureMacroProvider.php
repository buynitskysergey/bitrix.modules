<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\Signature\Template\Macro;

use Bitrix\Mail\Internal\Service\Signature\Template\SignatureTemplateContext;
use Bitrix\Mail\Internal\Service\Signature\Template\SignatureTemplateProvider;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

Loc::loadMessages(__FILE__);

final class SignatureMacroProvider implements SignatureTemplateProvider
{
	public const TYPE = 'macro';
	public const ERROR_UNKNOWN = 'SIGNATURE_MACRO_UNKNOWN';
	public const ERROR_INVALID_CONTEXT = 'SIGNATURE_MACRO_INVALID_CONTEXT';
	private const MULTILINE_IDS = ['company.legalAddress', 'company.actualAddress'];

	private SignatureMacroCatalog $catalog;
	private SignatureMacroValueProvider $valueProvider;
	private EmptyHtmlLineNormalizer $emptyHtmlLineNormalizer;
	private SignatureMacroHtmlContext $htmlContext;

	public function __construct(
		SignatureMacroValueProvider $valueProvider,
		?SignatureMacroCatalog $catalog = null,
		?EmptyHtmlLineNormalizer $emptyHtmlLineNormalizer = null,
		?SignatureMacroHtmlContext $htmlContext = null,
	)
	{
		$this->valueProvider = $valueProvider;
		$this->catalog = $catalog ?? new SignatureMacroCatalog();
		$this->emptyHtmlLineNormalizer = $emptyHtmlLineNormalizer ?? new EmptyHtmlLineNormalizer();
		$this->htmlContext = $htmlContext ?? new SignatureMacroHtmlContext();
	}

	public function getType(): string
	{
		return self::TYPE;
	}

	public function canonicalize(string $template): Result
	{
		if (!str_contains($template, '{{'))
		{
			return $this->success('template', $template);
		}

		$unknown = $this->findFirstUnknownConstruction($template);
		if ($unknown !== null)
		{
			$result = new Result();
			$result->addError(new Error(
				(string)Loc::getMessage(
					'MAIL_SIGNATURE_MACRO_UNKNOWN',
					['#TOKEN#' => htmlspecialcharsbx($unknown)],
				),
				self::ERROR_UNKNOWN,
			));

			return $result;
		}

		$invalidContextToken = $this->htmlContext->findFirstTokenOutsideText(
			$template,
			array_values($this->catalog->getTokensById()),
		);
		if ($invalidContextToken !== null)
		{
			return $this->invalidContextResult($invalidContextToken);
		}

		return $this->success('template', $template);
	}

	public function resolve(string $template, SignatureTemplateContext $context): Result
	{
		$tokensById = $this->catalog->getTokensById();
		$knownTokens = array_values($tokensById);
		if (!$this->containsAnyToken($template, $knownTokens))
		{
			return $this->success('html', $template);
		}

		$invalidContextToken = $this->htmlContext->findFirstTokenOutsideText($template, $knownTokens);
		if ($invalidContextToken !== null)
		{
			return $this->invalidContextResult($invalidContextToken);
		}

		$requestedIds = [];
		foreach ($tokensById as $id => $token)
		{
			if (str_contains($template, $token))
			{
				$requestedIds[] = $id;
			}
		}

		$values = $this->valueProvider->getValues($context, $requestedIds);
		$replacements = [];
		foreach ($tokensById as $id => $token)
		{
			$value = $values[$id] ?? '';
			$escapedValue = htmlspecialcharsbx((string)$value);
			$replacements[$token] = in_array($id, self::MULTILINE_IDS, true)
				? nl2br($escapedValue, false)
				: $escapedValue
			;
		}

		$html = $this->emptyHtmlLineNormalizer->normalize($template, $replacements);

		return $this->success('html', $html);
	}

	private function findFirstUnknownConstruction(string $template): ?string
	{
		$knownTokens = array_flip(array_values($this->catalog->getTokensById()));
		$offset = 0;
		while (($start = strpos($template, '{{', $offset)) !== false)
		{
			$end = strpos($template, '}}', $start + 2);
			if ($end === false)
			{
				return substr($template, $start);
			}

			$construction = substr($template, $start, $end + 2 - $start);
			if (!isset($knownTokens[$construction]))
			{
				return $construction;
			}

			$offset = $end + 2;
		}

		return null;
	}

	/** @param string[] $tokens */
	private function containsAnyToken(string $template, array $tokens): bool
	{
		foreach ($tokens as $token)
		{
			if (str_contains($template, $token))
			{
				return true;
			}
		}

		return false;
	}

	private function success(string $key, string $value): Result
	{
		return (new Result())->setData([$key => $value]);
	}

	private function invalidContextResult(string $token): Result
	{
		$result = new Result();
		$result->addError(new Error(
			(string)Loc::getMessage(
				'MAIL_SIGNATURE_MACRO_INVALID_CONTEXT',
				['#TOKEN#' => htmlspecialcharsbx($token)],
			),
			self::ERROR_INVALID_CONTEXT,
		));

		return $result;
	}
}

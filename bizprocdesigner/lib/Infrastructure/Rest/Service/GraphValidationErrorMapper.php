<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Rest\Service;

use Bitrix\BizprocDesigner\Infrastructure\Rest\Dto\ValidationIssueDto;
use Bitrix\BizprocDesigner\Infrastructure\Rest\Dto\ValidationReportDto;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Rest\V3\Dto\DtoCollection;

/**
 * Turns the verdict of the graph validators into the report of template.validate.
 *
 * Almost everything the report carries is already in the error: the address in Error::code, the class of
 * the failure in customData['errorCode']. The block is not - it is recovered from the index inside the
 * address against the submitted blocks, or read from customData['blockId'] where a validator named it. The
 * text of the message is never read: an address is never parsed out of it.
 *
 * The refusal of template.draft.add needs no mapping - the errors go to the core untouched
 * ({@see \Bitrix\BizprocDesigner\Infrastructure\Rest\Exception\GraphValidationException}).
 */
final class GraphValidationErrorMapper
{
	/**
	 * @param array $blocks blocks as the caller submitted them - the addresses count their positions here
	 */
	public static function toReport(Result $result, array $blocks): ValidationReportDto
	{
		$issues = new DtoCollection(ValidationIssueDto::class);
		foreach ($result->getErrors() as $error)
		{
			$issues->add(self::toIssue($error, $blocks));
		}

		$report = new ValidationReportDto();
		$report->valid = $issues->count() === 0;
		$report->issues = $issues;

		return $report;
	}

	private static function toIssue(Error $error, array $blocks): ValidationIssueDto
	{
		$path = (string)$error->getCode();

		$issue = new ValidationIssueDto();
		$issue->path = $path;
		$issue->blockId = self::statedBlockId($error) ?? self::blockId($path, $blocks);
		$issue->code = self::errorCode($error);
		$issue->message = $error->getMessage();

		return $issue;
	}

	/**
	 * The block a validator named itself. An error about the graph as a whole carries no address to recover
	 * a block from, yet may well be about one - an unclosed loop is about its loop node.
	 */
	private static function statedBlockId(Error $error): ?string
	{
		$customData = $error->getCustomData();
		$blockId = is_array($customData) ? ($customData['blockId'] ?? null) : null;

		return is_string($blockId) && $blockId !== '' ? $blockId : null;
	}

	/**
	 * The block the problem belongs to, taken from the "blocks.<index>" its address opens with. Null both
	 * for a problem outside a block and for a submitted block carrying no usable id - the report is built
	 * for an invalid graph too, where the id itself may be what the validator rejected.
	 */
	private static function blockId(string $path, array $blocks): ?string
	{
		$index = self::blockIndex($path);
		if ($index === null)
		{
			return null;
		}

		$block = $blocks[$index] ?? null;
		$id = is_array($block) ? ($block['id'] ?? null) : null;

		return is_scalar($id) ? (string)$id : null;
	}

	private static function blockIndex(string $path): ?int
	{
		$segments = explode('.', $path);
		if (($segments[0] ?? '') !== 'blocks' || preg_match('/^[0-9]+$/D', $segments[1] ?? '') !== 1)
		{
			return null;
		}

		return (int)$segments[1];
	}

	private static function errorCode(Error $error): ?string
	{
		$customData = $error->getCustomData();
		$errorCode = is_array($customData) ? ($customData['errorCode'] ?? null) : null;

		return is_string($errorCode) ? $errorCode : null;
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\AI\Service\RequestContext;

use Bitrix\AI\Context;

/**
 * Feature label of an outbound AI request: the owning module together with the scenario
 * behind the call, as `ai.{moduleId}.{contextId}`.
 *
 * The single place where the shape of the label is born. A send point that assembled the
 * value itself would also decide which characters are allowed and where the value is cut;
 * the check in front of the header drops a value that disagrees, so the scenario would
 * vanish from the label leaving nothing but a log line behind.
 *
 * Nothing beyond the given context is read: no option, no database, no current user. That is
 * what lets a send point build the label before anything that can fail has run.
 */
final class FeatureTag
{
	private const PREFIX = 'ai';
	private const UNKNOWN = 'unknown';

	private function __construct(
		public readonly string $value,
	)
	{
	}

	public static function fromContext(Context $context): self
	{
		return new self(
			self::PREFIX
			. '.' . self::normalizePart($context->getModuleId())
			. '.' . self::normalizePart($context->getContextId())
		);
	}

	/**
	 * Bytewise on purpose: the cap is counted in bytes and so is the check in front of the
	 * header, so a part measured in characters would pass here and be dropped there.
	 *
	 * That is also why the cut comes before the replacement: one byte outside the alphabet
	 * becomes exactly one underscore, so the value is the same either way and the work no
	 * longer follows the length of a context id that arrives unbounded.
	 */
	private static function normalizePart(string $raw): string
	{
		$clean = (string)preg_replace('/[^A-Za-z0-9_-]/', '_', substr($raw, 0, self::partMax()));
		if ($clean === '')
		{
			$clean = self::UNKNOWN;
		}

		return $clean;
	}

	/**
	 * Derived rather than assigned, so no part can outgrow the cap the header check applies.
	 */
	private static function partMax(): int
	{
		return intdiv(ContextHeaders::FEATURE_MAX_LENGTH - strlen(self::PREFIX) - 2, 2);
	}
}

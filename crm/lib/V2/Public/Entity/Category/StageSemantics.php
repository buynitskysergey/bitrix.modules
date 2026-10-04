<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Category;

use Bitrix\Main\ArgumentException;

/**
 * The semantics of a stage: the work is still going through it, it closes the work as won, or it
 * closes the work as lost.
 *
 * The value of a case is the domain name of the semantics and it is the whole of what a caller needs
 * to know. The letters the semantics is stored in belong to the storage boundary and are translated
 * on the way in and out of it, so no caller ever spells one out.
 */
enum StageSemantics: string
{
	case Process = 'process';
	case Success = 'success';
	case Failure = 'failure';

	private const STORAGE_PROCESS = 'P';
	private const STORAGE_SUCCESS = 'S';
	private const STORAGE_FAILURE = 'F';

	/**
	 * @internal Reads the value the storage boundary keeps the semantics in. Process semantics is
	 * also stored as no value at all, so an absent one is process semantics rather than a refusal.
	 *
	 * @throws ArgumentException On a value that is none of the three.
	 */
	public static function internalFromStorageValue(?string $value): self
	{
		return match ($value)
		{
			null, '', self::STORAGE_PROCESS => self::Process,
			self::STORAGE_SUCCESS => self::Success,
			self::STORAGE_FAILURE => self::Failure,
			default => throw new ArgumentException("Not a stage semantics: {$value}"),
		};
	}

	/**
	 * @internal The value to hand to the storage boundary.
	 */
	public function internalToStorageValue(): string
	{
		return match ($this)
		{
			self::Process => self::STORAGE_PROCESS,
			self::Success => self::STORAGE_SUCCESS,
			self::Failure => self::STORAGE_FAILURE,
		};
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Util;

/**
 * Bitrix language ids are not BCP 47 tags: br, ua, vn and kz are country codes, sc and tc are Chinese scripts.
 * An id missing from the table yields null, because an invalid lang attribute is an accessibility violation itself.
 */
final class LanguageTag
{
	private const TAG_BY_LANGUAGE_ID = [
		'en' => 'en',
		'de' => 'de',
		'la' => 'es',
		'br' => 'pt-BR',
		'fr' => 'fr',
		'it' => 'it',
		'pl' => 'pl',
		'ru' => 'ru',
		'ua' => 'uk',
		'tr' => 'tr',
		'sc' => 'zh-Hans',
		'tc' => 'zh-Hant',
		'ja' => 'ja',
		'vn' => 'vi',
		'id' => 'id',
		'ms' => 'ms',
		'th' => 'th',
		'ar' => 'ar',
		'kz' => 'kk',
		'hi' => 'hi',
	];

	public static function getByLanguageId(string $languageId): ?string
	{
		return self::TAG_BY_LANGUAGE_ID[$languageId] ?? null;
	}
}

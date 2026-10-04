<?php

namespace Bitrix\Main\Infrastructure\Rest\Support;

use Bitrix\Main\Rest\Smile as LegacySmile;

/**
 * Loads smile catalog via legacy REST encoder (absolute image URLs, camelCase keys).
 */
final class SmileCatalog
{
	/**
	 * @return array{sets: list<array>, smiles: list<array>}
	 */
	public static function fetch(bool $fullTypings = false): array
	{
		$options = [];
		if ($fullTypings)
		{
			$options['FULL_TYPINGS'] = 'Y';
		}

		$smiles = \CSmileGallery::getSmilesWithSets(\CSmileGallery::GALLERY_DEFAULT, $options);

		return LegacySmile::objectEncode([
			'SETS' => $smiles['SMILE_SET'],
			'SMILES' => $smiles['SMILE'],
		], [
			'IMAGE_FIELD' => ['IMAGE'],
		]);
	}
}

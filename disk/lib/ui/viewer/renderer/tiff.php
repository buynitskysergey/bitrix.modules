<?php

declare(strict_types=1);

namespace Bitrix\Disk\UI\Viewer\Renderer;

use Bitrix\Main\UI\Viewer\Renderer\Renderer;

final class Tiff extends Renderer
{
	private const JS_TYPE_TIFF = 'tiff';

	public static function getJsType(): string
	{
		return self::JS_TYPE_TIFF;
	}

	public static function getAllowedContentTypes(): array
	{
		return [
			'image/tiff',
		];
	}

	public function render(): ?string
	{
		return null;
	}

	public function getData(): array
	{
		return [
			'src' => $this->sourceUri,
		];
	}
}

<?php

namespace Bitrix\Main\UI\Viewer\Renderer;

use Bitrix\Main\Loader;
use Bitrix\Main\Web\MimeType;

class Audio extends Renderer
{
	public const JS_TYPE_AUDIO = 'audio';

	public static function getJsType()
	{
		return self::JS_TYPE_AUDIO;
	}

	public static function getAllowedContentTypes()
	{
		return [
			'audio/mp3',
			'audio/ogg',
			'audio/mpeg',
			'audio/mp4',
			'audio/x-m4a',
			'audio/wav',
			'audio/x-wav',
		];
	}

	public function render()
	{
		Loader::includeModule('fileman');

		return \CJSCore::getHTML(['player']);
	}

	public function getData()
	{
		$contentType = $this->getOption('contentType');
		if (!in_array($contentType, self::getAllowedContentTypes(), true))
		{
			$contentType = MimeType::getByFilename($this->name);
		}

		return [
			'contentType' => $contentType,
			'src' => $this->sourceUri,
		];
	}
}

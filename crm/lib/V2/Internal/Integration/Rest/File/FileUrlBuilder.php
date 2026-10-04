<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\Rest\File;

use Bitrix\Crm\UserField\FileViewer;
use Bitrix\Main\Web\Uri;

final class FileUrlBuilder
{
	public function __construct(
		private readonly ScopedRestServer $server,
	)
	{
	}

	/**
	 * @return array{url: string, downloadUrl: string}
	 */
	public function build(
		int $entityTypeId,
		int $entityId,
		string $fieldName,
		int $fileId,
		bool $isSystemField = false,
	): array
	{
		$viewerFieldName = $isSystemField ? mb_strtoupper($fieldName) : $fieldName;
		$url = new Uri((new FileViewer($entityTypeId))->getUrl($entityId, $viewerFieldName, $fileId));
		if ($isSystemField && !\CCrmOwnerType::isUseDynamicTypeBasedApproach($entityTypeId))
		{
			$url->addParams(['dynamic' => 'N']);
		}

		if ($isSystemField)
		{
			return [
				'url' => $url->toAbsolute()->getUri(),
				'downloadUrl' => \CRestUtil::getDownloadUrl(
					[
						'entityTypeId' => $entityTypeId,
						'entityId' => $entityId,
						'fieldName' => $viewerFieldName,
						'fileId' => $fileId,
					],
					$this->server,
				),
			];
		}

		return [
			'url' => $url->toAbsolute()->getUri(),
			'downloadUrl' => \CRestUtil::getDownloadUrl(
				[
					'entityTypeId' => $entityTypeId,
					'entityId' => $entityId,
					'fieldName' => $fieldName,
					'fileId' => $fileId,
				],
				$this->server,
			),
		];
	}
}

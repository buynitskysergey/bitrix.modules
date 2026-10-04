<?php

namespace Bitrix\Crm\Integration\AI\Operation\Autostart\Slider;

use Bitrix\Crm\Integration\AI\Operation\Autostart\FillFieldsSettings;
use Bitrix\Main\Result;

class AutostartSettingsRepository
{
	public function get(array $scope): FillFieldsSettings
	{
		return FillFieldsSettings::get($scope['entityTypeId'], $scope['categoryId']);
	}

	public function save(FillFieldsSettings $settings, array $scope): Result
	{
		return FillFieldsSettings::save($settings, $scope['entityTypeId'], $scope['categoryId']);
	}

	public function getRevision(array $scope): string
	{
		return sha1(FillFieldsSettings::getRawValue($scope['entityTypeId'], $scope['categoryId']));
	}

	public function getFreshRevision(array $scope): string
	{
		return sha1(FillFieldsSettings::getFreshRawValue($scope['entityTypeId'], $scope['categoryId']));
	}
}

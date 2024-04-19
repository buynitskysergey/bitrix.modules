<?php

class CASDiblockVersion {

	public static function isIblockNewGridv18() {
		return self::checkMinVersion('18.0.0');
	}

	public static function getIblockVersion() {
		return CASDModuleVersion::getModuleVersion('iblock');
	}

	public static function checkMinVersion($checkVersion)
	{
		return CASDModuleVersion::checkMinVersion('iblock', $checkVersion);
	}
}
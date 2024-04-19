<?

class CASDModuleVersion {
	protected static $moduleVersion = array();

	public static function getModuleVersion($module) {
		if (!isset(self::$moduleVersion[$module])) {
			self::loadModuleVersion($module);
		}
		return self::$moduleVersion[$module];
	}

	public static function checkMinVersion($module, $version)
	{
		if (!isset(self::$moduleVersion[$module])) {
			self::loadModuleVersion($module);
		}
		if (!isset(self::$moduleVersion[$module]) || self::$moduleVersion[$module] == '0.0.0') {
			return false;
		}

		return version_compare(self::$moduleVersion[$module], $version, '>=');
	}

	/**
	 * @param string $module
	 */
	protected static function loadModuleVersion($module) {
		self::$moduleVersion[$module] = '0.0.0';
		$moduleObject = \CModule::CreateModuleObject($module);
		if ($moduleObject) {
			self::$moduleVersion[$module] = $moduleObject->MODULE_VERSION;
		}
		unset($moduleObject);
	}
}
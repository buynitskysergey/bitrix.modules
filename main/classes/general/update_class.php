<?php
/**********************************************************************/
/**    DO NOT MODIFY THIS FILE                                       **/
/**    MODIFICATION OF THIS FILE WILL ENTAIL SITE FAILURE            **/
/**********************************************************************/

if (!defined("US_SHARED_KERNEL_PATH"))
{
	define("US_SHARED_KERNEL_PATH", "/bitrix");
}

class CUpdateSystem
{
	/**
	 * @deprecated Will be removed.
	 */
	public static function ParseServerData(&$strServerOutput, &$arRes, &$strError)
	{
		return CUpdateClient::ParseServerData($strServerOutput, $arRes, $strError);
	}

	/**
	 * @deprecated Use CUpdateClient::AddMessage2Log()
	 */
	public static function AddMessage2Log($sText, $sErrorCode = "")
	{
		CUpdateClient::AddMessage2Log($sText, $sErrorCode);
	}

	/**
	 * @deprecated Use CUpdateClient::CopyDirFiles()
	 */
	public static function CopyDirFiles($path_from, $path_to, &$strError)
	{
		return CUpdateClient::CopyDirFiles($path_from, $path_to, $strError);
	}

	/**
	 * @deprecated Use CUpdateClient::DeleteDirFilesEx()
	 */
	public static function DeleteDirFilesEx($path)
	{
		return CUpdateClient::DeleteDirFilesEx($path);
	}
}

class CUpdatesXMLNode
{
	var $name;                // Name of the node
	var $content;            // Content of the node
	var $children;            // Subnodes
	var $attributes;        // Attributes

	public function __construct()
	{
	}

	function __toString()
	{
		switch ($this->name)
		{
			case "cdata-section":
				$ret = "<![CDATA[";
				$ret .= $this->content;
				$ret .= "]]>";
				break;

			default:
				$isOneLiner = false;

				if (empty($this->children) && ($this->content == ''))
				{
					$isOneLiner = true;
				}

				$attrStr = "";

				if (!empty($this->attributes))
				{
					foreach ($this->attributes as $attr)
					{
						$attrStr .= " " . $attr->name . "=\"" . $attr->content . "\" ";
					}
				}

				if ($isOneLiner)
				{
					$oneLinerEnd = " /";
				}
				else
				{
					$oneLinerEnd = "";
				}

				$ret = "<" . $this->name . $attrStr . $oneLinerEnd . ">";

				if (!empty($this->children))
				{
					foreach ($this->children as $child)
					{
						$ret .= $child->__toString();
					}
				}

				if (!$isOneLiner)
				{
					if ($this->content <> '')
					{
						$ret .= $this->content;
					}

					$ret .= "</" . $this->name . ">";
				}

				break;
		}

		return $ret;
	}

	function __toArray()
	{
		$arInd = [];
		$retHash = [];

		$retHash["@"] = [];
		if (!empty($this->attributes) && is_array($this->attributes))
		{
			foreach ($this->attributes as $attr)
			{
				$retHash["@"][$attr->name] = $attr->content;
			}
		}

		$retHash["#"] = "";
		if ($this->content <> '')
		{
			$retHash["#"] = $this->content;
		}
		else
		{
			if (!empty($this->children) && is_array($this->children))
			{
				$ar = [];
				foreach ($this->children as $child)
				{
					if (array_key_exists($child->name, $arInd))
					{
						$arInd[$child->name] = $arInd[$child->name] + 1;
					}
					else
					{
						$arInd[$child->name] = 0;
					}

					$ar[$child->name][$arInd[$child->name]] = $child->__toArray();
				}
				$retHash["#"] = $ar;
			}
		}

		return $retHash;
	}
}

class CUpdatesXMLDocument
{
	var $version;                // XML version
	var $encoding;                // XML encoding
	var $children;
	var $root;

	public function __construct()
	{
	}

	/* Returns an XML string of the DOM document */
	function __toString()
	{
		$ret = "<" . "?xml";
		if ($this->version <> '')
		{
			$ret .= " version=\"" . $this->version . "\"";
		}
		if ($this->encoding <> '')
		{
			$ret .= " encoding=\"" . $this->encoding . "\"";
		}
		$ret .= "?" . ">";

		if (!empty($this->children))
		{
			foreach ($this->children as $child)
			{
				$ret .= $child->__toString();
			}
		}

		return $ret;
	}

	/* Returns an array of the DOM document */
	function __toArray()
	{
		$arRetArray = [];

		if (!empty($this->children))
		{
			foreach ($this->children as $child)
			{
				$arRetArray[$child->name] = $child->__toArray();
			}
		}

		return $arRetArray;
	}
}

class CUpdatesXML
{
	var $tree;
	var $TrimWhiteSpace;

	public function __construct($TrimWhiteSpace = true)
	{
		$this->TrimWhiteSpace = ($TrimWhiteSpace ? true : false);
		$this->tree = false;
	}

	function Load($file)
	{
		$this->tree = false;

		if (file_exists($file))
		{
			$content = file_get_contents($file);
			$this->tree = $this->__parse($content);
			return true;
		}

		return false;
	}

	function LoadString($text)
	{
		$this->tree = false;

		if ($text <> '')
		{
			$this->tree = $this->__parse($text);
			return true;
		}

		return false;
	}

	function GetTree()
	{
		return $this->tree;
	}

	function GetArray()
	{
		return $this->tree->__toArray();
	}

	function GetString()
	{
		return $this->tree->__toString();
	}

	function SelectNodes($strNode)
	{
		if (!is_object($this->tree))
		{
			return false;
		}

		$result = $this->tree;

		$tmp = explode("/", $strNode);
		for ($i = 1, $ni = count($tmp); $i < $ni; $i++)
		{
			if ($tmp[$i] != "")
			{
				if (!is_array($result->children))
				{
					return false;
				}

				$bFound = false;
				for ($j = 0, $nj = count($result->children); $j < $nj; $j++)
				{
					if ($result->children[$j]->name == $tmp[$i])
					{
						$result = $result->children[$j];
						$bFound = true;
						break;
					}
				}

				if (!$bFound)
				{
					return false;
				}
			}
		}

		return $result;
	}

	/* Will return an DOM object tree from the well-formed XML. */
	function __parse($strXMLText)
	{
		$TagStack = [];

		$oXMLDocument = new CUpdatesXMLDocument();

		// stip the !doctype
		$strXMLText = preg_replace("%<\!DOCTYPE.*?>%is", "", $strXMLText);

		// get document version and encoding from header
		preg_match_all("#<\?(.*?)\?>#i", $strXMLText, $arXMLHeader_tmp);
		foreach ($arXMLHeader_tmp[0] as $strXMLHeader_tmp)
		{
			preg_match_all("/([a-zA-Z:]+=\".*?\")/i", $strXMLHeader_tmp, $arXMLParam_tmp);
			foreach ($arXMLParam_tmp[0] as $strXMLParam_tmp)
			{
				if ($strXMLParam_tmp <> '')
				{
					$arXMLAttribute_tmp = explode("=\"", $strXMLParam_tmp);
					if ($arXMLAttribute_tmp[0] == "version")
					{
						$oXMLDocument->version = substr($arXMLAttribute_tmp[1], 0, strlen($arXMLAttribute_tmp[1]) - 1);
					}
					elseif ($arXMLAttribute_tmp[0] == "encoding")
					{
						$oXMLDocument->encoding = substr($arXMLAttribute_tmp[1], 0, strlen($arXMLAttribute_tmp[1]) - 1);
					}
				}
			}
		}

		// strip header
		$strXMLText = preg_replace("#<\?.*?\?>#", "", $strXMLText);

		// strip comments
		$strXMLText = CUpdatesXML::__stripComments($strXMLText);

		$oXMLDocument->root = $oXMLDocument->children;
		$currentNode = $oXMLDocument;

		$pos = 0;
		$endTagPos = 0;
		while ($pos < strlen($strXMLText))
		{
			$char = substr($strXMLText, $pos, 1);
			if ($char == "<")
			{
				// find tag name
				$endTagPos = strpos($strXMLText, ">", $pos);

				// tag name with attributes
				$tagName = substr($strXMLText, $pos + 1, $endTagPos - ($pos + 1));

				// check if it's an endtag </tagname>
				if (substr($tagName, 0, 1) == "/")
				{
					$lastNodeArray = array_pop($TagStack);
					$lastTag = $lastNodeArray["TagName"];

					$lastNode = &$lastNodeArray["ParentNodeObject"];

					unset($currentNode);
					$currentNode = &$lastNode;

					$tagName = substr($tagName, 1, strlen($tagName));

					// strip out namespace; nameSpace:Name
					$colonPos = strpos($tagName, ":");

					if ($colonPos > 0)
					{
						$tagName = substr($tagName, $colonPos + 1, strlen($tagName));
					}

					if ($lastTag != $tagName)
					{
						print("Error parsing XML, unmatched tags $tagName");
						return false;
					}
				}
				else
				{
					$firstSpaceEnd = strpos($tagName, " ");
					$firstNewlineEnd = strpos($tagName, "\n");

					if ($firstNewlineEnd)
					{
						if ($firstSpaceEnd)
						{
							$tagNameEnd = min($firstSpaceEnd, $firstNewlineEnd);
						}
						else
						{
							$tagNameEnd = $firstNewlineEnd;
						}
					}
					else
					{
						if ($firstSpaceEnd)
						{
							$tagNameEnd = $firstSpaceEnd;
						}
						else
						{
							$tagNameEnd = 0;
						}
					}

					$justName = $tagNameEnd > 0 ? substr($tagName, 0, $tagNameEnd) : $tagName;

					// strip out namespace; nameSpace:Name
					$colonPos = strpos($justName, ":");

					if ($colonPos > 0)
					{
						$justName = substr($justName, $colonPos + 1, strlen($justName));
					}

					// remove trailing / from the name if exists
					if (substr($justName, strlen($justName) - 1, 1) == "/")
					{
						$justName = substr($justName, 0, strlen($justName) - 1);
					}

					// check for CDATA
					$isCDATASection = false;
					$cdataPos = strpos($strXMLText, "<![CDATA[", $pos);
					if ($cdataPos == $pos && $pos > 0)
					{
						$isCDATASection = true;
						$endTagPos = strpos($strXMLText, "]]>", $cdataPos);
						$cdataSection = substr($strXMLText, $cdataPos + 9, $endTagPos - ($cdataPos + 9));

						// new CDATA node
						unset($subNode);
						$subNode = new CUpdatesXMLNode();
						$subNode->name = "cdata-section";
						$subNode->content = $cdataSection;

						$currentNode->children[] = &$subNode;

						$pos = $endTagPos;
						$endTagPos += 2;
					}
					else
					{
						// normal start tag
						unset($subNode);
						$subNode = new CUpdatesXMLNode();
						$subNode->name = $justName;

						$currentNode->children[] = &$subNode;
					}

					// find attributes
					if ($tagNameEnd > 0)
					{
						$attributePart = substr($tagName, $tagNameEnd, strlen($tagName));

						// attributes
						unset($attr);
						$attr = CUpdatesXML::__parseAttributes($attributePart);

						if ($attr)
						{
							$subNode->attributes = &$attr;
						}
					}

					// check if it's a oneliner: <tagname /> or a cdata section
					if (!$isCDATASection)
					{
						if (substr($tagName, strlen($tagName) - 1, 1) != "/")
						{
							array_push($TagStack, ["TagName" => $justName, "ParentNodeObject" => &$currentNode]);

							unset($currentNode);
							$currentNode = &$subNode;
						}
					}
				}
			}

			$pos = strpos($strXMLText, "<", $pos + 1);

			if ($pos === false)
			{
				// end of document
				$pos = strlen($strXMLText);
			}
			else
			{
				// content tag
				$tagContent = substr($strXMLText, $endTagPos + 1, $pos - ($endTagPos + 1));

				if (($this->TrimWhiteSpace && (trim($tagContent) != "")) || !$this->TrimWhiteSpace)
				{
					unset($subNode);

					// convert special chars
					$currentNode->content = static::replaceSpecialChars($tagContent);
				}
			}
		}

		return $oXMLDocument;
	}

	protected function replaceSpecialChars($content)
	{
		return str_replace(
			["&gt;", "&lt;", "&apos;", "&quot;", "&amp;"],
			[">", "<", "'", '"', "&"],
			$content
		);
	}

	function __stripComments(&$str)
	{
		$str = preg_replace("#<\!--.*?-->#s", "", $str);
		return $str;
	}

	/* Parses the attributes. Returns false if no attributes in the supplied string is found */
	function __parseAttributes($attributeString)
	{
		$ret = false;

		preg_match_all("/(\\S+?)\\s*=\\s*[\"](.*?)[\"]/s", $attributeString, $attributeArray);

		foreach ($attributeArray[0] as $i => $attributePart)
		{
			if (trim($attributePart) != "" && trim($attributePart) != "/")
			{
				$attributeName = $attributeArray[1][$i];

				// strip out namespace; nameSpace:Name
				$colonPos = strpos($attributeName, ":");

				if ($colonPos > 0)
				{
					$attributeName = substr($attributeName, $colonPos + 1, strlen($attributeName));
				}

				$attributeValue = $attributeArray[2][$i];

				unset($attrNode);
				$attrNode = new CUpdatesXMLNode();
				$attrNode->name = $attributeName;

				// convert special chars
				$attrNode->content = static::replaceSpecialChars($attributeValue);

				$ret[] = &$attrNode;
			}
		}
		return $ret;
	}
}

class CUpdater
{
	var $errorMessage;
	var $curPath;    // Путь к скрипту updater (без имени скрипта) относительно корня сайта
	var $curModulePath;    // Путь к папке с обновлениями модуля
	var $dbType;    // Тип базы данных
	var $updater;    // Путь к скрипту updater (c именем скрипта) относительно корня сайта
	var $moduleID;    // Модуль
	var $callType; // Прямой вызов (ALL - все, KERNEL - ядро, PERSONAL - персональные файлы, DATABASE - база данных  // DB=PERSONAL+DATABASE)
	var $kernelPath; // Путь к ядру

	static $config;
	static $migrationErrors;

	public function Init($curPath, $dbType, $updater, $curDir, $moduleID, $callType = "ALL")
	{
		$this->errorMessage = [];
		$this->curPath = $curPath;
		$this->dbType = strtoupper($dbType);
		$this->updater = $updater;
		$this->curModulePath = $curDir;
		$this->moduleID = $moduleID;

		$this->callType = [];
		if (!is_array($callType))
		{
			$callType = [$callType];
		}

		foreach ($callType as $val)
		{
			$val = strtoupper($val);
			switch ($val)
			{
				case "ALL":
					$this->callType = ["KERNEL", "PERSONAL", "DATABASE"];
					break;
				case "KERNEL":
					if (!in_array("KERNEL", $this->callType))
					{
						$this->callType[] = "KERNEL";
					}
					break;
				case "PERSONAL":
					if (!in_array("PERSONAL", $this->callType))
					{
						$this->callType[] = "PERSONAL";
					}
					break;
				case "DATABASE":
					if (!in_array("DATABASE", $this->callType))
					{
						$this->callType[] = "DATABASE";
					}
					break;
				case "DB":
					if (!in_array("PERSONAL", $this->callType))
					{
						$this->callType[] = "PERSONAL";
					}
					if (!in_array("DATABASE", $this->callType))
					{
						$this->callType[] = "DATABASE";
					}
					break;
				case "DATABASE_DDL":
					$this->callType = ["DATABASE_DDL"];
					break;
			}
		}

		$this->kernelPath = US_SHARED_KERNEL_PATH;
	}

	// Устанавливает все компоненты
	// $arDeleteFiles = array("component.name" => array("/images/1.gif", "/templates/.default/style.css"), "component.name1" => array("/style.css"));
	public function InstallComponents($arDeleteFiles = [])
	{
		if (!in_array("KERNEL", $this->callType))
		{
			return true;
		}

		CUpdateClient::AddMessage2Log("Run updater '" . $this->updater . "': InstallComponents()", "CRUPDICS1");

		$bFlag = true;

		$componentsPath = $_SERVER["DOCUMENT_ROOT"] . $this->curModulePath . "/install/components";
		if ($handle = @opendir($componentsPath))
		{
			while (($dir = readdir($handle)) !== false)
			{
				if ($dir == "." || $dir == ".." || !is_dir($componentsPath . "/" . $dir))
				{
					continue;
				}

				if (file_exists($componentsPath . "/" . $dir . "/component.php") || file_exists($componentsPath . "/" . $dir . "/class.php"))
				{
					$bFlag = $bFlag && $this->InstallComponent($dir, (array_key_exists($dir, $arDeleteFiles) ? $arDeleteFiles[$dir] : []));
				}
				else
				{
					if ($handle1 = @opendir($componentsPath . "/" . $dir))
					{
						while (($dir1 = readdir($handle1)) !== false)
						{
							if ($dir1 == "." || $dir1 == ".." || !is_dir($componentsPath . "/" . $dir . "/" . $dir1))
							{
								continue;
							}

							if (file_exists($componentsPath . "/" . $dir . "/" . $dir1 . "/component.php") || file_exists($componentsPath . "/" . $dir . "/" . $dir1 . "/class.php"))
							{
								$bFlag = $bFlag && $this->InstallComponent($dir . ":" . $dir1, (array_key_exists($dir . ":" . $dir1, $arDeleteFiles) ? $arDeleteFiles[$dir . ":" . $dir1] : []));
							}
						}
						@closedir($handle1);
					}
				}
			}
			@closedir($handle);
		}

		return $bFlag;
	}

	protected function __MakeComponentPath($componentName)
	{
		if ($componentName == '' || !preg_match("#^([A-Za-z0-9_.-]+:)?([A-Za-z0-9_-]+\\.)*([A-Za-z0-9_-]+)$#i", $componentName))
		{
			return "";
		}

		return "/" . str_replace(":", "/", $componentName);
	}

	// Устанавливает компонент по его имени
	// $arDeleteFiles - удаляет файлы из массива ( Array("/images/1.gif", "/templates/.default/style.css") )
	public function InstallComponent($componentName, $arDeleteFiles = [])
	{
		if (!in_array("KERNEL", $this->callType))
		{
			return true;
		}

		CUpdateClient::AddMessage2Log("Run updater '" . $this->updater . "': InstallComponent(" . $componentName . ")", "CRUPDIC1");

		$componentPath = $this->__MakeComponentPath($componentName);
		if ($componentPath == '')
		{
			CUpdateClient::AddMessage2Log("Wrong component name", "CRUPDIC2");
			return false;
		}

		if (!file_exists($_SERVER["DOCUMENT_ROOT"] . $this->curModulePath . "/install/components" . $componentPath))
		{
			CUpdateClient::AddMessage2Log("Component is not found", "CRUPDIC3");
			return false;
		}

		if (!empty($arDeleteFiles))
		{
			for ($i = 0, $cnt = count($arDeleteFiles); $i < $cnt; $i++)
			{
				$path2Modules = $_SERVER["DOCUMENT_ROOT"] . US_SHARED_KERNEL_PATH . "/modules/" . $this->moduleID . "/install/components" . $componentPath;
				$path2Components = $_SERVER["DOCUMENT_ROOT"] . US_SHARED_KERNEL_PATH . "/components/" . $componentPath;

				if (file_exists($path2Modules . $arDeleteFiles[$i]))
				{
					@unlink($path2Modules . $arDeleteFiles[$i]);
				}
				if (file_exists($path2Components . $arDeleteFiles[$i]))
				{
					@unlink($path2Components . $arDeleteFiles[$i]);
				}
			}
		}

		return $this->CopyDirFiles($this->curModulePath . "/install/components" . $componentPath, US_SHARED_KERNEL_PATH . "/components" . $componentPath);
	}

	// Устанавливает все мастера
	// $arDeleteFiles = array("component.name" => array("/images/1.gif", "/templates/.default/style.css"), "component.name1" => array("/style.css"));
	public function InstallWizards($arDeleteFiles = [])
	{
		if (!in_array("KERNEL", $this->callType))
		{
			return true;
		}

		CUpdateClient::AddMessage2Log("Run updater '" . $this->updater . "': InstallWizards()", "CRUPDICS1");

		$bFlag = true;

		$wizardsPath = $_SERVER["DOCUMENT_ROOT"] . $this->curModulePath . "/install/wizards";
		if ($handle = @opendir($wizardsPath))
		{
			while (($dir = readdir($handle)) !== false)
			{
				if ($dir == "." || $dir == ".." || !is_dir($wizardsPath . "/" . $dir))
				{
					continue;
				}

				if (file_exists($wizardsPath . "/" . $dir . "/wizard.php"))
				{
					$bFlag = $bFlag && $this->InstallWizard($dir, (array_key_exists($dir, $arDeleteFiles) ? $arDeleteFiles[$dir] : []));
				}
				else
				{
					if ($handle1 = @opendir($wizardsPath . "/" . $dir))
					{
						while (($dir1 = readdir($handle1)) !== false)
						{
							if ($dir1 == "." || $dir1 == ".." || !is_dir($wizardsPath . "/" . $dir . "/" . $dir1))
							{
								continue;
							}

							if (file_exists($wizardsPath . "/" . $dir . "/" . $dir1 . "/wizard.php"))
							{
								$bFlag = $bFlag && $this->InstallWizard($dir . ":" . $dir1, (array_key_exists($dir . ":" . $dir1, $arDeleteFiles) ? $arDeleteFiles[$dir . ":" . $dir1] : []));
							}
						}
						@closedir($handle1);
					}
				}
			}
			@closedir($handle);
		}

		return $bFlag;
	}

	// Устанавливает мастер по его имени
	// $arDeleteFiles - удаляет файлы из массива ( Array("/images/1.gif", "/templates/.default/style.css") )
	public function InstallWizard($wizardName, $arDeleteFiles = [])
	{
		if (!in_array("KERNEL", $this->callType))
		{
			return true;
		}

		CUpdateClient::AddMessage2Log("Run updater '" . $this->updater . "': InstallWizard(" . $wizardName . ")", "CRUPDIC1");

		$wizardPath = $this->__MakeComponentPath($wizardName);
		if ($wizardPath == '')
		{
			CUpdateClient::AddMessage2Log("Wrong wizard name", "CRUPDIC2");
			return false;
		}

		if (!file_exists($_SERVER["DOCUMENT_ROOT"] . $this->curModulePath . "/install/wizards" . $wizardPath))
		{
			CUpdateClient::AddMessage2Log("Wizard is not found", "CRUPDIC3");
			return false;
		}

		if (!empty($arDeleteFiles))
		{
			for ($i = 0, $cnt = count($arDeleteFiles); $i < $cnt; $i++)
			{
				$path2Modules = $_SERVER["DOCUMENT_ROOT"] . US_SHARED_KERNEL_PATH . "/modules/" . $this->moduleID . "/install/wizards" . $wizardPath;
				$path2Wizards = $_SERVER["DOCUMENT_ROOT"] . US_SHARED_KERNEL_PATH . "/wizards/" . $wizardPath;

				if (file_exists($path2Modules . $arDeleteFiles[$i]))
				{
					@unlink($path2Modules . $arDeleteFiles[$i]);
				}
				if (file_exists($path2Wizards . $arDeleteFiles[$i]))
				{
					@unlink($path2Wizards . $arDeleteFiles[$i]);
				}
			}
		}

		return $this->CopyDirFiles($this->curModulePath . "/install/wizards" . $wizardPath, US_SHARED_KERNEL_PATH . "/wizards" . $wizardPath);
	}

	public function CopyFiles($fromDir, $toDir)
	{
		if (!in_array("KERNEL", $this->callType))
		{
			return true;
		}

		CUpdateClient::AddMessage2Log("Run updater '" . $this->updater . "': CopyFiles(" . $fromDir . ", " . $toDir . ")", "CRUPDCF1");

		$errorMessage = "";

		if (substr($fromDir, 0, 1) != "/")
		{
			$fromDir = $this->curModulePath . "/" . $fromDir;
		}
		if (substr($toDir, 0, 1) != "/")
		{
			$toDir = $this->kernelPath . "/" . $toDir;
		}

		$fromDirFull = $_SERVER["DOCUMENT_ROOT"] . $fromDir;
		$toDirFull = $_SERVER["DOCUMENT_ROOT"] . $toDir;

		if (!file_exists($fromDirFull))
		{
			return true;
		}

		$result = CUpdateClient::CopyDirFiles($fromDirFull, $toDirFull, $errorMessage);

		if (!$result)
		{
			$this->errorMessage[] = $errorMessage;
		}

		return $result;
	}

	public function CopyDirFiles($fromDir, $toDir)
	{
		if (!in_array("KERNEL", $this->callType))
		{
			return true;
		}

		CUpdateClient::AddMessage2Log("Run updater '" . $this->updater . "': CopyDirFiles(" . $fromDir . ", " . $toDir . ")", "CRUPDCDF1");
		$errorMessage = "";

		if (substr($fromDir, 0, 1) != "/")
		{
			$fromDir = $this->curPath . "/" . $fromDir;
		}

		$fromDirFull = $_SERVER["DOCUMENT_ROOT"] . $fromDir;
		$toDirFull = $_SERVER["DOCUMENT_ROOT"] . $toDir;

		if (!file_exists($fromDirFull))
		{
			return true;
		}

		$result = CUpdateClient::CopyDirFiles($fromDirFull, $toDirFull, $errorMessage);

		if (!$result)
		{
			$this->errorMessage[] = $errorMessage;
		}

		return $result;
	}

	public function Query($query, $tableName = "")
	{
		if (!in_array("DATABASE", $this->callType))
		{
			return false;
		}

		$bCanUpdate = true;
		if ($tableName <> '')
		{
			if (!$this->TableExists($tableName))
			{
				$bCanUpdate = false;
			}
		}

		$result = false;

		$strQuery = '';
		if ($bCanUpdate)
		{
			$strQuery = "";

			if (is_array($query))
			{
				foreach ($query as $key => $value)
				{
					if ($this->dbType == strtoupper($key))
					{
						$strQuery = $value;
						break;
					}
				}
			}
			else
			{
				$strQuery = $query;
			}

			if ($strQuery == '')
			{
				$bCanUpdate = false;
			}
		}

		CUpdateClient::AddMessage2Log("Run updater '" . $this->updater . "': Query(" . $strQuery . ", " . $tableName . ")", "CRUPDCDF2");

		if ($bCanUpdate)
		{
			$result = $GLOBALS["DB"]->Query($strQuery, true);

			if (!$result)
			{
				$this->errorMessage[] = $GLOBALS["DB"]->db_Error;
			}
		}

		return $result;
	}

	public function QueryBatch($queryPath, $tableName = "")
	{
		if (!in_array("DATABASE", $this->callType))
		{
			return false;
		}

		CUpdateClient::AddMessage2Log("Run updater '" . $this->updater . "': QueryBatch(" . $queryPath . ", " . $tableName . ")", "CRUPDCDF3");

		$bCanUpdate = true;
		if ($tableName <> '')
		{
			if (!$this->TableExists($tableName))
			{
				$bCanUpdate = false;
			}
		}

		if ($bCanUpdate)
		{
			$strQueryPath = "";

			if (is_array($queryPath))
			{
				foreach ($queryPath as $key => $value)
				{
					if ($this->dbType == strtoupper($key))
					{
						$strQueryPath = $value;
						break;
					}
				}
			}
			else
			{
				$strQueryPath = $queryPath;
			}

			if ($strQueryPath == '')
			{
				$bCanUpdate = false;
			}
		}

		$arError = false;
		if ($bCanUpdate)
		{
			if (substr($strQueryPath, 0, 1) != "/")
			{
				$strQueryPath = $this->curPath . "/" . $strQueryPath;
			}

			$queryPathFull = $_SERVER["DOCUMENT_ROOT"] . $strQueryPath;

			if (file_exists($queryPathFull))
			{
				$arError = $GLOBALS["DB"]->RunSQLBatch($queryPathFull);
			}

			if ($arError)
			{
				foreach ($arError as $value)
				{
					$this->errorMessage[] = $value;
				}
			}
		}

		return !$arError;
	}

	public function TableExists($tableName)
	{
		global $DB;

		if (!in_array("DATABASE", $this->callType))
		{
			return false;
		}

		$tableName = preg_replace("/[^A-Za-z0-9%_]+/", "", $tableName);
		if ($tableName == '')
		{
			return false;
		}

		if ($this->dbType == "MYSQL")
		{
			$strSql = "SHOW TABLES LIKE '" . strtolower($DB->ForSql($tableName)) . "'";
		}
		elseif ($this->dbType == "ORACLE")
		{
			$strSql = "SELECT TABLE_NAME FROM USER_TABLES WHERE TABLE_NAME LIKE UPPER('" . strtoupper($DB->ForSql($tableName)) . "')";
		}
		elseif ($this->dbType == "MSSQL")
		{
			$strSql = "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME LIKE '" . strtoupper($DB->ForSql($tableName)) . "'";
		}
		elseif ($this->dbType == "PGSQL")
		{
			$strSql = "SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tablename LIKE '" . strtolower($DB->ForSql($tableName)) . "'";
		}
		else
		{
			$strSql = "";
		}

		$dbResult = $DB->Query($strSql);
		if ($dbResult->Fetch())
		{
			return true;
		}
		else
		{
			return false;
		}
	}

	public function ColumnExists($tableName, $columnName)
	{
		global $DB;

		if (!$this->CanUpdateDatabase() || !$this->TableExists($tableName))
		{
			return false;
		}

		$re = '/[^A-Za-z0-9_]+/';
		$columnName = preg_replace($re, '', $columnName);
		$tableName = preg_replace($re, '', $tableName);
		if (empty($tableName) || empty($columnName))
		{
			return false;
		}

		$strSql = sprintf(
			'SELECT %s FROM %s WHERE 1 = 0',
			$DB->quote($columnName),
			$DB->quote($tableName),
		);

		return $DB->Query($strSql, true) !== false;
	}

	public function CanUpdateDatabase()
	{
		return (in_array("DATABASE", $this->callType));
	}

	public function CanRunDdlQuery()
	{
		return (in_array("DATABASE_DDL", $this->callType));
	}

	public function CanUpdateKernel()
	{
		return (in_array("KERNEL", $this->callType));
	}

	public function CanUpdatePersonalFiles()
	{
		return (in_array("PERSONAL", $this->callType));
	}

	public static function getCurrentConfig()
	{
		return self::$config;
	}

	public static function addError($errorMessage)
	{
		if (!is_array(self::$migrationErrors))
		{
			self::$migrationErrors = [];
		}

		self::$migrationErrors[] = (string)$errorMessage;
	}

	public function beforeIncludeUpdaterFile()
	{
		self::$config = [
			'moduleId' => $this->moduleID,
			'canUpdateDatabase' => $this->CanUpdateDatabase(),
			'canUpdateKernel' => $this->CanUpdateKernel(),
			'canUpdatePersonalFiles' => $this->CanUpdatePersonalFiles(),
			'canRunDdlQuery' => $this->CanRunDdlQuery(),
			'updaterFilename' => $this->updater,
			'updaterRootDirectory' => $this->curModulePath,
		];
	}

	public function afterIncludeUpdaterFile()
	{
		self::$config = null;

		if (!empty(self::$migrationErrors))
		{
			$this->errorMessage = array_merge($this->errorMessage, self::$migrationErrors);
		}
		self::$migrationErrors = null;
	}

	public function canRunUpdater()
	{
		if (!in_array('DATABASE_DDL', $this->callType))
		{
			return true;
		}

		// Updater in DATABASE_DDL mode should not be executed for old updates and modules without modern migration_config.json support:
		if (file_exists($_SERVER["DOCUMENT_ROOT"] . $this->curModulePath . '/migration_config.json'))
		{
			return true;
		}
		if (file_exists($_SERVER['DOCUMENT_ROOT'] . $this->kernelPath . '/modules/' . $this->moduleID . '/migration_config.json'))
		{
			return true;
		}

		return false;
	}
}

class CUpdateFilesProcessor
{
	var $errorMessages;
	var $moduleMigrationConfig;
	var $updatesRootDirectory;
	var $moduleId;

	public function process($updatesRootDirectory, $moduleId)
	{
		$this->updatesRootDirectory = $updatesRootDirectory;
		$this->moduleId = $moduleId;
		$this->moduleMigrationConfig = $this->loadMigrationConfigFile();

		$this->copyInstallFiles();
		$this->copyPersonalFiles();
		$this->deleteFiles();

		return empty($this->errorMessages);
	}

	public function getErrorMessages()
	{
		return $this->errorMessages;
	}

	private function loadMigrationConfigFile()
	{
		$configCandidates = [
			$this->updatesRootDirectory . '/migration_config.json',
			$_SERVER['DOCUMENT_ROOT'] . US_SHARED_KERNEL_PATH . '/modules/' . $this->moduleId . '/migration_config.json',
		];

		$moduleMigrationConfig = null;

		foreach ($configCandidates as $configCandidate)
		{
			if (file_exists($configCandidate))
			{
				$moduleMigrationConfig = json_decode(file_get_contents($configCandidate), true);
				if (is_array($moduleMigrationConfig))
				{
					break;
				}
				else
				{
					$moduleMigrationConfig = null;
				}
			}
		}

		return $moduleMigrationConfig;
	}

	private function copyInstallFiles()
	{
		if (!IsModuleInstalled($this->moduleId))
		{
			return;
		}

		$installFilesToCopy = $this->getMigrationConfigValue('installDirectoriesMapping');
		foreach ($installFilesToCopy as $dirFrom => $dirTo)
		{
			if (file_exists($this->updatesRootDirectory . '/' . $dirFrom))
			{
				CUpdateClient::AddMessage2Log("Process module '" . $this->moduleId . "': copyInstallFiles(" . $dirFrom . ", " . $dirTo . ")", 'CRUPDCF1');
				$this->CopyFiles($this->updatesRootDirectory . '/' . $dirFrom, $_SERVER["DOCUMENT_ROOT"] . US_SHARED_KERNEL_PATH . '/' . $dirTo);
			}
		}
	}

	private function copyPersonalFiles()
	{
		if (!IsModuleInstalled($this->moduleId))
		{
			return;
		}

		$personalFilesToCopy = $this->getMigrationConfigValue('publicDirectoriesMapping');
		foreach ($personalFilesToCopy as $dirFrom => $dirTo)
		{
			if (file_exists($this->updatesRootDirectory . '/' . $dirFrom))
			{
				CUpdateClient::AddMessage2Log("Process module '" . $this->moduleId . "': copyPersonalFiles(" . $dirFrom . ", " . $dirTo . ")", 'CRUPDCF1');
				$this->CopyFiles($this->updatesRootDirectory . '/' . $dirFrom, $_SERVER["DOCUMENT_ROOT"] . '/' . $dirTo);
			}
		}
	}

	private function deleteFiles()
	{
		if ($handle = @opendir($this->updatesRootDirectory))
		{
			while (($file = readdir($handle)) !== false)
			{
				if ($file == '.' || $file == '..')
				{
					continue;
				}

				if (substr($file, 0, 13) === 'deleted_files' && substr($file, -4) === '.txt')
				{
					$fullFileName = $this->updatesRootDirectory . '/' . $file;
					$filesToDelete = file($fullFileName, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
					if ($filesToDelete === false)
					{
						$this->errorMessages[] = str_replace("#FILE#", $fullFileName, GetMessage("SUPP_RV_READ_DESCR_FILE"));
					}
					else
					{
						foreach ($filesToDelete as $fileName)
						{
							CUpdateClient::DeleteDirFilesEx($_SERVER['DOCUMENT_ROOT'] . US_SHARED_KERNEL_PATH . '/' . $fileName);
						}
					}
					@unlink($fullFileName);
				}
			}
		}
	}

	private function getMigrationConfigValue($key)
	{
		if (
			is_array($this->moduleMigrationConfig)
			&& isset($this->moduleMigrationConfig[$key])
			&& is_array($this->moduleMigrationConfig[$key])
		)
		{
			return $this->moduleMigrationConfig[$key];
		}

		return [];
	}

	private function copyFiles($fromDirFull, $toDirFull)
	{
		if (!file_exists($fromDirFull))
		{
			return;
		}

		$errorMessage = '';
		$result = CUpdateClient::CopyDirFiles($fromDirFull, $toDirFull, $errorMessage);

		if (!$result)
		{
			$this->errorMessages[] = $errorMessage;
		}
	}
}

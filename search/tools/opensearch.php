<?php
IncludeModuleLangFile(__FILE__);

class CSearchOpenSearch extends CSearchFullText
{
	public $arForumTopics = [];
	private $errorText = '';
	private $errorNumber = 0;
	public $tags = '';
	public $SITE_ID = '';
	public $connectionString = '';
	public $user = '';
	public $password = '';
	public $indexName = '';
	public $siteAnalyzerMap = [];
	protected $connected = false;

	public function __construct($config = [])
	{
		parent::__construct($config);

		$this->connectionString = $this->config['connection'];
		$this->user = $this->config['user'];
		$this->password = $this->config['password'];
		$this->indexName = $this->config['index_name'];
		$this->siteAnalyzerMap = $this->config['analyzers'] ?? [];

		if (!$this->siteAnalyzerMap)
		{
			$langs = CLang::GetList();
			while ($site = $langs->Fetch())
			{
				$analyzer = COption::GetOptionString('search', 'opensearch_analyzer_' . $site['ID']);
				if (!$analyzer)
				{
					$analyzer = array_search($site['LANGUAGE_ID'], static::getLanguageAnalyzers()) ?: 'english';
				}
				$this->siteAnalyzerMap[$site['ID']] = $analyzer;
			}
		}
	}

	public function connect($ignoreErrors = false)
	{
		global $APPLICATION;

		if ($this->connected)
		{
			return true;
		}

		if (!preg_match('/^[a-zA-Z0-9_-]+$/', $this->indexName))
		{
			if ($ignoreErrors)
			{
				$APPLICATION->ThrowException(GetMessage('SEARCH_OPENSEARCH_CONN_ERROR_INDEX_NAME'));
			}
			else
			{
				throw new \Bitrix\Main\DB\ConnectionException('OpenSearch connect error', GetMessage('SEARCH_OPENSEARCH_CONN_ERROR_INDEX_NAME'));
			}

			return false;
		}

		if ($ignoreErrors)
		{
			$error = '';
			$server = new Bitrix\Main\Web\HttpClient([
				'disableSslVerification' => true,
			]);

			$server->setAuthorization($this->user, $this->password);
			$strJson = $server->get($this->connectionString);
			if ($server->getStatus() !== 200 || !is_array(json_decode($strJson, true)))
			{
				if ($server->getStatus())
				{
					$error = $strJson;
				}
				else
				{
					$error = implode('<br.', $server->getError());
				}

				$APPLICATION->ThrowException(GetMessage('SEARCH_OPENSEARCH_CONN_ERROR', ['#ERRSTR#' => $error]));

				return false;
			}

			foreach ($this->siteAnalyzerMap as $siteId => $analyzer)
			{
				if (!$this->checkIndexTemplate($this->indexName, $siteId, $analyzer))
				{
					$APPLICATION->ThrowException(GetMessage('SEARCH_OPENSEARCH_CONN_ERROR', ['#ERRSTR#' => $this->getError()]));

					return false;
				}
			}
		}

		return true;
	}

	// https://opensearch.org/docs/latest/analyzers/supported-analyzers/index/
	public static function getLanguageAnalyzers()
	{
		return [
			'arabic' => 'ar',
			'armenian' => '',
			'basque' => '',
			'bengali' => '',
			'brazilian' => 'br',
			'bulgarian' => '',
			'catalan' => '',
			'czech' => '',
			'danish' => '',
			'dutch' => '',
			'english' => 'en',
			'estonian' => '',
			'finnish' => '',
			'french' => 'fr',
			'galician' => '',
			'german' => 'de',
			'greek' => '',
			'hindi' => '',
			'hungarian' => '',
			'indonesian' => 'ms',
			'irish' => '',
			'italian' => 'it',
			'latvian' => '',
			'lithuanian' => 'lt',
			'norwegian' => '',
			'persian' => '',
			'portuguese' => '',
			'romanian' => '',
			'russian' => 'ru',
			'sorani' => '',
			'spanish' => 'la',
			'swedish' => '',
			'thai' => 'th',
			'turkish' => 'tr',
		];
	}

	protected $version = 28;

	protected function checkIndexTemplate($indexName, $siteId, $analyzer, $adminNotify = true)
	{
		$templateName = $indexName . '-' . $siteId . '-template';
		$pipelineName = $indexName . '-pipeline'; // TODO $indexName . '-' . $siteId. '-pipeline'

		$result = false;
		try
		{
			$result = $this->query('GET', '/_index_template/' . $templateName);
		}
		catch (\Bitrix\Main\DB\ConnectionException $e)
		{
		}

		if (!$result && !in_array($this->errorNumber, [400, 404]))
		{
			return false;
		}

		$versionMatch = (
			isset($result['index_templates'][0]['index_template']['version'])
			&& $result['index_templates'][0]['index_template']['version'] === $this->version
		);

		$analyzerMatch = true;
		if ($analyzer)
		{
			$analyzerMatch = (
				isset($result['index_templates'][0]['index_template']['template']['mappings']['properties']['body']['analyzer'])
				&& $result['index_templates'][0]['index_template']['template']['mappings']['properties']['body']['analyzer'] === $analyzer
			);
		}
		else
		{
			$analyzerMatch = !isset($result['index_templates'][0]['index_template']['template']['mappings']['properties']['body']['analyzer']);
		}

		$ml = CPageOption::GetOptionString('search', 'opensearch_index_model_id', null) ?? COption::GetOptionString('search', 'opensearch_index_model_id');
		$mlMatch = true;
		if ($ml)
		{
			$mlDimension = CPageOption::GetOptionInt('search', 'opensearch_index_dimension') ?: COption::GetOptionInt('search', 'opensearch_index_dimension');
			$mlSpaceType = CPageOption::GetOptionString('search', 'opensearch_index_space_type') ?: COption::GetOptionString('search', 'opensearch_index_space_type');
			$mlMatch = (
				isset($result['index_templates'][0]['index_template']['template']['mappings']['properties']['body_ml']['type'])
				&& $result['index_templates'][0]['index_template']['template']['mappings']['properties']['body_ml']['type'] === 'knn_vector'
			)
			&& (
				isset($result['index_templates'][0]['index_template']['template']['mappings']['properties']['body_ml']['dimension'])
				&& $result['index_templates'][0]['index_template']['template']['mappings']['properties']['body_ml']['dimension'] === $mlDimension
			)
			&& (
				isset($result['index_templates'][0]['index_template']['template']['mappings']['properties']['body_ml']['method']['space_type'])
				&& $result['index_templates'][0]['index_template']['template']['mappings']['properties']['body_ml']['method']['space_type'] === $mlSpaceType
			)
			&& (
				isset($result['index_templates'][0]['index_template']['template']['settings']['index']['knn'])
				&& $result['index_templates'][0]['index_template']['template']['settings']['index']['knn'] === 'true'
			)
			&& (
				isset($result['index_templates'][0]['index_template']['template']['settings']['index']['default_pipeline'])
				&& $result['index_templates'][0]['index_template']['template']['settings']['index']['default_pipeline'] === $pipelineName
			)
			;
		}
		else
		{
			$mlDimension = false;
			$mlSpaceType = false;
			$mlMatch = !(
				isset($result['index_templates'][0]['index_template']['template']['mappings']['properties']['body_ml'])
			)
			&& !(
				isset($result['index_templates'][0]['index_template']['template']['settings']['index']['knn'])
			)
			&& !(
				isset($result['index_templates'][0]['index_template']['template']['settings']['index']['default_pipeline'])
			)
			;
		}

		$updateNeeded = !$versionMatch || !$analyzerMatch || !$mlMatch;

		if ($updateNeeded)
		{
			try
			{
				$result = $this->query('DELETE', '/_index_template/' . $templateName);
			}
			catch (\Bitrix\Main\DB\ConnectionException $e)
			{
				// Suppress error when no template was there yet.
			}
		}

		if ($this->errorNumber === 404 || $updateNeeded)
		{
			$template = [
				'settings' => [
					'index.knn' => true,
					'default_pipeline' => $pipelineName,
				],
				'mappings' => [
					'properties' => [
						'id' => [
							'type' => 'integer',
						],
						'date_change' => [
							'type' => 'date',
							'format' => 'date_time_no_millis',
						],
						'date_change_ts' => [
							'type' => 'integer',
						],
						'module_id' => [
							'type' => 'keyword',
						],
						'item_id' => [
							'type' => 'keyword',
						],
						'custom_rank' => [
							'type' => 'integer',
						],
						'user_id' => [
							'type' => 'integer',
						],
						'entity_type_id' => [
							'type' => 'keyword',
						],
						'entity_id' => [
							'type' => 'keyword',
						],
						'title' => [
							'type' => 'text',
						],
						'body' => [
							'type' => 'text',
						],
						'body_ml' => [
							'type' => 'knn_vector',
							'dimension' => $mlDimension,
							'method' => [
								'engine' => 'lucene',
								'space_type' => $mlSpaceType,
								'name' => 'hnsw',
								'parameters' => new stdClass(),
							],
						],
						'param1' => [
							'type' => 'keyword',
						],
						'param2' => [
							'type' => 'keyword',
						],
						'date_from' => [
							'type' => 'date',
							'format' => 'date_time_no_millis',
						],
						'date_from_ts' => [
							'type' => 'integer',
						],
						'date_to' => [
							'type' => 'date',
							'format' => 'date_time_no_millis',
						],
						'date_to_ts' => [
							'type' => 'integer',
						],
						'tag' => [
							'type' => 'keyword', // array
						],
						'xright' => [ // Can not use right here because sql plugin makes it uppercase
							'type' => 'keyword', // array
						],
						'param' => [
							'properties' => new stdClass(),
							'dynamic' => true,
						],
					]
				]
			];

			if ($analyzer)
			{
				$template['mappings']['properties']['body']['analyzer'] = $analyzer;
			}

			if (!$ml)
			{
				unset($template['settings']);
				unset($template['mappings']['properties']['body_ml']);
			}

			$result = $this->query('PUT', '/_index_template/' . $templateName, [
				'index_patterns' => [
					$indexName . '-' . $siteId,
				],
				'version' => $this->version,
				'template' => $template
			]);

			if ($adminNotify)
			{
				$error = [
					'MESSAGE' => GetMessage('SEARCH_OPENSEARCH_REINDEX', [
						'#LINK#' => '/bitrix/admin/search_reindex.php?lang=' . LANGUAGE_ID,
					]),
					'TAG' => 'SEARCH_REINDEX',
					'MODULE_ID' => 'SEARCH',
					'NOTIFY_TYPE' => CAdminNotify::TYPE_ERROR,
				];
				CAdminNotify::Add($error);
			}

			return $result;
		}

		return true;
	}

	protected static $siteIdChecked = [];

	protected function checkTemplateBySiteId($siteId)
	{
		global $CACHE_MANAGER;

		if (isset(static::$siteIdChecked[$siteId]))
		{
			return;
		}
		static::$siteIdChecked[$siteId] = true;

		$cacheId = 'opensearch-template-' . $siteId;
		if ($CACHE_MANAGER->Read(CACHED_opensearch_template, $cacheId, 'opensearch'))
		{
			return;
		}

		// a site with no analyzer has no template to check, but a failed check must not be cached as a success
		$templateChecked = true;
		if (isset($this->siteAnalyzerMap[$siteId]))
		{
			$analyzer = $this->siteAnalyzerMap[$siteId];
			$templateChecked = (bool)$this->checkIndexTemplate($this->indexName, $siteId, $analyzer, false);
		}

		if ($templateChecked)
		{
			$CACHE_MANAGER->Set($cacheId, true);
		}
	}

	public function truncate()
	{
		$this->query('DELETE', '/' . $this->indexName . '-*?expand_wildcards=all');
	}

	public function deleteById($ID)
	{
		$deleteBatch = '';
		foreach ($this->siteAnalyzerMap as $siteId => $_)
		{
			$deleteBatch .= json_encode([
				'delete' => [
					'_index' => $this->indexName . '-' . $siteId,
					'_id' => intval($ID),
				],
			]) . "\n";
		}
		$this->query('POST', '/_bulk', $deleteBatch);
	}

	public function replace($ID, $arFields)
	{
		if (array_key_exists('~DATE_CHANGE', $arFields))
		{
			$arFields['DATE_CHANGE'] = $arFields['~DATE_CHANGE'];
			unset($arFields['~DATE_CHANGE']);
		}
		elseif (array_key_exists('LAST_MODIFIED', $arFields))
		{
			$arFields['DATE_CHANGE'] = $arFields['LAST_MODIFIED'];
			unset($arFields['LAST_MODIFIED']);
		}
		elseif (array_key_exists('DATE_CHANGE', $arFields))
		{
			$arFields['DATE_CHANGE'] = CDatabase::FormatDate($arFields['DATE_CHANGE'], 'DD.MM.YYYY HH:MI:SS', CLang::GetDateFormat());
		}

		$DATE_FROM = isset($arFields['DATE_FROM']) && $arFields['DATE_FROM'] ? intval(MakeTimeStamp($arFields['DATE_FROM'])) : 0;
		$DATE_TO = isset($arFields['DATE_TO']) && $arFields['DATE_TO'] ? intval(MakeTimeStamp($arFields['DATE_TO'])) : 0;
		$DATE_CHANGE = intval(MakeTimeStamp($arFields['DATE_CHANGE']));

		$BODY = (isset($arFields['TITLE']) && $arFields['TITLE'] ? CSearch::KillEntities($arFields['TITLE']) . "\n" : '')
			. CSearch::KillEntities($arFields['BODY'])
			. (isset($arFields['TAGS']) && $arFields['TAGS'] ? "\n" . $arFields['TAGS'] : '')
		;

		$deleteBatch = '';
		$sites = $this->sites($arFields['SITE_ID']);
		foreach ($this->siteAnalyzerMap as $siteId => $_)
		{
			if (in_array($siteId, $sites))
			{
				$doc = [
					'id' => $ID,
					'date_change' => $DATE_CHANGE ? date('c', $DATE_CHANGE) : null,
					'date_change_ts' => $DATE_CHANGE ? $DATE_CHANGE - CTimeZone::GetOffset() : 0,
					'module_id' => $arFields['MODULE_ID'],
					'item_id' => $arFields['ITEM_ID'],
					'custom_rank' => intval($arFields['CUSTOM_RANK'] ?? 0),
					'user_id' => intval($arFields['USER_ID'] ?? 0),
					'entity_type_id' => $arFields['ENTITY_TYPE_ID'] ?? '',
					'entity_id' => $arFields['ENTITY_ID'] ?? '',
					'title' => $arFields['TITLE'] ?? '',
					'body' => $BODY,
					'param1' => $arFields['PARAM1'],
					'param2' => $arFields['PARAM2'],
					'date_from' => $DATE_FROM ? date('c', $DATE_FROM) : null,
					'date_from_ts' => $DATE_FROM ? $DATE_FROM - CTimeZone::GetOffset() : 0,
					'date_to' => $DATE_TO ? date('c', $DATE_TO) : null,
					'date_to_ts' => $DATE_TO ? $DATE_TO - CTimeZone::GetOffset() : 0,
					'tag' => $this->tags($arFields['SITE_ID'], $arFields['TAGS'] ?? ''),
					'xright' => $this->rights($arFields['PERMISSIONS']),
					'param' => $this->params($arFields['PARAMS'] ?? []),
				];
				$this->checkTemplateBySiteId($siteId);

				$result = $this->query(
					'PUT',
					'/' . $this->indexName . '-' . $siteId . '/_doc/' . intval($ID),
					$doc
				);

				if (!$result)
				{
					throw new \Bitrix\Main\DB\SqlQueryException('OpenSearch index error', $this->getError(), '');
				}
			}
			elseif (!defined('BX_SEARCH_FULL_REINDEXING'))
			{
				$deleteBatch .= json_encode([
					'delete' => [
						'_index' => $this->indexName . '-' . $siteId,
						'_id' => intval($ID),
					],
				]) . "\n";
			}
		}

		if ($deleteBatch)
		{
			$this->query('POST', '/_bulk', $deleteBatch);
		}
	}

	public function update($ID, $arFields)
	{
		$DB = CDatabase::GetModuleConnection('search');
		$ID = intval($ID);

		$arUpdate = [];
		$bReplace = array_key_exists('TITLE', $arFields)
			|| array_key_exists('BODY', $arFields)
			|| array_key_exists('MODULE_ID', $arFields)
			|| array_key_exists('ITEM_ID', $arFields)
			|| array_key_exists('PARAM1', $arFields)
			|| array_key_exists('PARAM2', $arFields)
		;

		if (array_key_exists('~DATE_CHANGE', $arFields))
		{
			$arFields['DATE_CHANGE'] = $arFields['~DATE_CHANGE'];
			unset($arFields['~DATE_CHANGE']);
		}
		elseif (array_key_exists('LAST_MODIFIED', $arFields))
		{
			$arFields['DATE_CHANGE'] = $arFields['LAST_MODIFIED'];
			unset($arFields['LAST_MODIFIED']);
		}
		elseif (array_key_exists('DATE_CHANGE', $arFields))
		{
			$arFields['DATE_CHANGE'] = CDatabase::FormatDate($arFields['DATE_CHANGE'], 'DD.MM.YYYY HH:MI:SS', CLang::GetDateFormat());
		}

		if (array_key_exists('DATE_CHANGE', $arFields))
		{
			$DATE_CHANGE = intval(MakeTimeStamp($arFields['DATE_CHANGE']));
			$arUpdate['date_change'] = $DATE_CHANGE > 0 ? date('c', $DATE_CHANGE - CTimeZone::GetOffset()) : null;
			$arUpdate['date_change_ts'] = $DATE_CHANGE > 0 ? $DATE_CHANGE : 0;
		}

		if (array_key_exists('DATE_FROM', $arFields))
		{
			$DATE_FROM = intval(MakeTimeStamp($arFields['DATE_FROM']));
			$arUpdate['date_from'] = $DATE_FROM > 0 ? date('c', $DATE_FROM - CTimeZone::GetOffset()) : null;
			$arUpdate['date_from_ts'] = $DATE_FROM > 0 ? $DATE_FROM : 0;
		}

		if (array_key_exists('DATE_TO', $arFields))
		{
			$DATE_TO = intval(MakeTimeStamp($arFields['DATE_TO']));
			$arUpdate['date_to'] = $DATE_TO > 0 ? date('c', $DATE_TO - CTimeZone::GetOffset()) : null;
			$arUpdate['date_to_ts'] = $DATE_TO > 0 ? $DATE_TO : 0;
		}

		if (array_key_exists('CUSTOM_RANK', $arFields))
		{
			$arUpdate['custom_rank'] = $arFields['CUSTOM_RANK'] > 0 ? intval($arFields['CUSTOM_RANK']) : 0;
		}

		if (array_key_exists('TAGS', $arFields))
		{
			$arUpdate['tag'] = $this->tags($arFields['SITE_ID'], $arFields['TAGS']);
		}

		if (array_key_exists('PERMISSIONS', $arFields))
		{
			$arUpdate['xright'] = $this->rights($arFields['PERMISSIONS']);
		}

		if (array_key_exists('PARAMS', $arFields))
		{
			$arUpdate['param'] = $this->params($arFields['PARAMS']);
		}

		if (array_key_exists('SITE_ID', $arFields))
		{
			$sites = $this->sites($arFields['SITE_ID']);
		}
		else
		{
			$sites = [];
			$dbSites = $DB->Query('SELECT * from b_search_content_site WHERE SEARCH_CONTENT_ID=' . $ID);
			while ($site = $dbSites->Fetch())
			{
				$sites[$site['SITE_ID']] = $site['SITE_ID'];
			}
		}

		$isDocumentMissing = false;
		if (!empty($arUpdate) && !$bReplace)
		{
			$deleteBatch = '';
			foreach ($this->siteAnalyzerMap as $siteId => $_)
			{
				if (in_array($siteId, $sites))
				{
					try
					{
						$result = $this->query('POST', '/' . $this->indexName . '-' . $siteId . '/_update/' . intval($ID), [
							'doc' => $arUpdate,
						]);
					}
					catch (\Bitrix\Main\DB\ConnectionException $e)
					{
						// a missing document or index answers 404, and 400 on servers that never answer 404
						if (!in_array($this->errorNumber, [400, 404], true))
						{
							throw $e;
						}

						$isDocumentMissing = true;
						break;
					}
				}
				else
				{
					$deleteBatch .= json_encode([
						'delete' => [
							'_index' => $this->indexName . '-' . $siteId,
							'_id' => intval($ID),
						],
					]) . "\n";
				}
			}

			if ($deleteBatch)
			{
				$this->query('POST', '/_bulk', $deleteBatch);
			}

			if (!$isDocumentMissing)
			{
				return;
			}
		}

		if ($bReplace || $isDocumentMissing)
		{
			$dbItem = $DB->Query('
				SELECT
					*
					, ' . $DB->DateToCharFunction('DATE_CHANGE') . ' as LAST_MODIFIED
					, ' . $DB->DateToCharFunction('DATE_FROM') . ' as DATE_FROM
					, ' . $DB->DateToCharFunction('DATE_TO') . ' as DATE_TO
				FROM b_search_content
				WHERE ID = ' . $ID
			);
			$searchItem = $dbItem->Fetch();
			if ($searchItem)
			{
				$arTags = [];
				$dbTags = $DB->Query('SELECT * from b_search_tags WHERE SEARCH_CONTENT_ID=' . $ID);
				while ($tag = $dbTags->Fetch())
				{
					$arTags[] = $tag['NAME'];
				}
				$searchItem['TAGS'] = $arTags ? implode(',', $arTags) : null;

				$searchItem['PERMISSIONS'] = [];
				$dbRights = $DB->Query('SELECT * from b_search_content_right WHERE SEARCH_CONTENT_ID=' . $ID);
				while ($right = $dbRights->Fetch())
				{
					$searchItem['PERMISSIONS'][] = $right['GROUP_CODE'];
				}

				$searchItem['SITE_ID'] = [];
				$dbSites = $DB->Query('SELECT * from b_search_content_site WHERE SEARCH_CONTENT_ID=' . $ID);
				while ($site = $dbSites->Fetch())
				{
					$searchItem['SITE_ID'][$site['SITE_ID']] = $site['URL'];
				}

				$searchItem['PARAMS'] = [];
				$dbParams = $DB->Query('SELECT * from b_search_content_param WHERE SEARCH_CONTENT_ID=' . $ID);
				while ($param = $dbParams->Fetch())
				{
					$searchItem['PARAMS'][$param['PARAM_NAME']][] = $param['PARAM_VALUE'];
				}

				$this->replace($ID, $searchItem);
			}
		}
	}

	public function syncPermission($permission, $arFilter)
	{
		$message = new \Bitrix\Search\SyncPermissionMessage($permission, $arFilter);
		$message->send('search_sync_perm');
	}

	public function syncContent($id)
	{
		$message = new \Bitrix\Search\SyncIndexItemMessage($id);
		$message->send('search_sync_content');

		return true;
	}

	public function tags($arLID, $sContent)
	{
		$tags = [];
		if (is_array($arLID))
		{
			foreach ($arLID as $site_id => $_)
			{
				$arTags = tags_prepare($sContent, $site_id);
				foreach ($arTags as $tag)
				{
					$tags[$tag] = $tag;
				}
			}
		}
		sort($tags);

		return $tags;
	}

	public function rights($arRights)
	{
		$rights = [];
		if (is_array($arRights))
		{
			foreach ($arRights as $group_id)
			{
				if (is_numeric($group_id))
				{
					$rights[$group_id] = 'G' . intval($group_id);
				}
				else
				{
					$rights[$group_id] = $group_id;
				}
			}
		}
		sort($rights);

		return $rights;
	}

	public function sites($arSites)
	{
		$sites = [];
		if (is_array($arSites))
		{
			foreach ($arSites as $site_id => $_)
			{
				$sites[$site_id] = $site_id;
			}
		}
		else
		{
			$sites[$arSites] = $arSites;
		}
		sort($sites);

		return $sites;
	}

	public function params($arParams)
	{
		$params = [];
		if (is_array($arParams))
		{
			foreach ($arParams as $k1 => $v1)
			{
				$name = trim($k1);
				if ($name !== '')
				{
					if (!is_array($v1))
					{
						$v1 = [$v1];
					}

					foreach ($v1 as $v2)
					{
						$value = trim($v2);
						if ($value !== '')
						{
							$params[$name] = $value;
						}
					}
				}
			}
		}

		return $params;
	}

	public function getErrorText()
	{
		return $this->errorText;
	}

	public function getErrorNumber()
	{
		return $this->errorNumber;
	}

	public function search($arParams, $aSort, $aParamsEx, $bTagsCloud)
	{
		$result = [];
		$this->errorText = '';
		$this->errorNumber = 0;

		$this->tags = trim($arParams['TAGS']);

		$limit = 0;
		if (is_array($aParamsEx) && isset($aParamsEx['LIMIT']))
		{
			$limit = intval($aParamsEx['LIMIT']);
			unset($aParamsEx['LIMIT']);
		}
		if ($limit <= 0)
		{
			$limit = intval(COption::GetOptionInt('search', 'max_result_size'));
		}
		if ($limit <= 0)
		{
			$limit = 500;
		}

		$offset = 0;
		if (is_array($aParamsEx) && isset($aParamsEx['OFFSET']))
		{
			$offset = intval($aParamsEx['OFFSET']);
			unset($aParamsEx['OFFSET']);
		}

		if (is_array($aParamsEx) && isset($aParamsEx['QUERY_OBJECT']))
		{
			unset($aParamsEx['QUERY_OBJECT']);
			unset($aParamsEx['STEMMING']);
		}

		if (is_array($aParamsEx) && !empty($aParamsEx))
		{
			$aParamsEx['LOGIC'] = 'OR';
			$arParams[] = $aParamsEx;
		}

		$this->SITE_ID = $arParams['SITE_ID'];
		unset($arParams['SITE_ID']);

		$query = [
			'_source' => false,
			'query' => [
				'bool' => [
					'must' => [
					],
					'filter' => [
						'bool' => [
							'must' => [
							],
						],
					],
				],
			],
			'from' => $offset,
			'size' => $limit,
		];

		$strQuery = trim($arParams['QUERY']);
		if ($strQuery !== '')
		{
			$ml = CPageOption::GetOptionString('search', 'opensearch_index_model_id', null) ?? COption::GetOptionString('search', 'opensearch_index_model_id');
			if ($ml)
			{
				$query['query']['bool']['must'][] = [
					'neural' => [
						'body_ml' => [
							'query_text' => $strQuery,
							'model_id' => $ml,
							'k' => 10,
						],
					],
				];
			}
			else
			{
				$query['query']['bool']['must'][] = [
					'match' => [
						'body' => [
							'query' => $strQuery,
						],
					],
				];
			}
		}

		$rights = $this->CheckPermissions();
		if ($rights)
		{
			$query['query']['bool']['filter']['bool']['must'][] = [
				'terms' => [
					'xright' => $rights,
				],
			];
		}

		$arWhere = $this->prepareFilter($arParams);
		if ($arWhere)
		{
			$query['query']['bool']['filter']['bool']['must'][] = $arWhere;
		}

		if (!$query['query']['bool']['filter']['bool']['must'])
		{
			unset($query['query']['bool']['filter']);
		}

		if ($strQuery || $this->tags || $bTagsCloud)
		{
			if ($bTagsCloud)
			{
				$query['size'] = 0; // we don't need hits
				$query['aggregations'] = [
					'tags' => [
						'terms' => [
							'field' => 'tag',
							'size' => $limit,
						],
						'aggregations' => [
							'max_dc' => [
								'max' => [
									'field' => 'date_change_ts',
								],
							],
						],
					],
				];

				$r = $this->query('GET', '/' . $this->indexName . '-' . $this->SITE_ID . '/_search', $query);
				if (!$r)
				{
					throw new \Bitrix\Main\DB\SqlQueryException('OpenSearch select error', $this->getError(), 'GET /' . $this->indexName . '-' . $this->SITE_ID . '/_search');
				}
				else
				{
					if (
						is_array($r)
						&& isset($r['aggregations'])
						&& is_array($r['aggregations'])
						&& isset($r['aggregations']['tags'])
						&& is_array($r['aggregations']['tags'])
					)
					{
						foreach ($r['aggregations']['tags']['buckets'] as $searcBucket)
						{
							$result[] = [
								'NAME' => $searcBucket['key'],
								'CNT' => $searcBucket['doc_count'],
								'FULL_DATE_CHANGE' => ConvertTimeStamp($searcBucket['max_dc']['value'] + CTimeZone::GetOffset(), 'FULL'),
								'DATE_CHANGE' => ConvertTimeStamp($searcBucket['max_dc']['value'] + CTimeZone::GetOffset(), 'SHORT'),
							];
						}
					}
				}
			}
			else
			{
				$order = $this->__PrepareSort($aSort);
				if ($order)
				{
					$query['sort'] = $order;
				}

				$query['fields'] = ['id', 'module_id', 'param1', 'param2'];

				$r = $this->query('GET', '/' . $this->indexName . '-' . $this->SITE_ID . '/_search', $query);
				if (!$r)
				{
					throw new \Bitrix\Main\DB\SqlQueryException('OpenSearch select error', $this->getError(), json_encode($query));
				}
				else
				{
					$this->arForumTopics = [];
					if (
						is_array($r)
						&& isset($r['hits'])
						&& is_array($r['hits'])
						&& isset($r['hits']['hits'])
						&& is_array($r['hits']['hits'])
					)
					{
						foreach ($r['hits']['hits'] as $searchHit)
						{
							if ($searchHit['fields']['module_id'][0] == 'FORUM')
							{
								if (array_key_exists($searchHit['fields']['param2'][0], $this->arForumTopics))
								{
									continue;
								}
								$this->arForumTopics[$searchHit['fields']['param2'][0]] = true;
							}
							$result[] = [
								'ID' => $searchHit['fields']['id'][0],
								'MODULE_ID' => $searchHit['fields']['module_id'][0],
								'PARAM1' => $searchHit['fields']['param1'][0],
								'PARAM2' => $searchHit['fields']['param2'][0],
							];
						}
					}
				}
			}
		}
		else
		{
			$this->errorText = GetMessage('SEARCH_ERROR3');
			$this->errorNumber = 3;
		}

		return $result;
	}

	public function searchTitle($phrase = '', $arPhrase = [], $nTopCount = 5, $arParams = [], $bNotFilter = false, $order = '')
	{
		$query = [
			'_source' => false,
			'fields' => ['id'],
			'query' => [
				'bool' => [
					'must' => [
						[
							'match_phrase_prefix' => [
								'title' => $phrase,
							],
						],
					],
				],
			],
			'size' => $nTopCount,
		];

		$site_id = SITE_ID;
		if (is_array($arParams) && isset($arParams['SITE_ID']))
		{
			$site_id = $arParams['SITE_ID'];
			unset($arParams['SITE_ID']);
		}

		$query['query']['bool']['filter']['bool']['must'] = [];
		$arWhere = $this->prepareFilter($arParams);
		if ($arWhere)
		{
			$query['query']['bool']['filter']['bool']['must'][] = $arWhere;
		}

		$rights = $this->CheckPermissions();
		if ($rights)
		{
			$query['query']['bool']['filter']['bool']['must'][] = [
				'terms' => [
					'xright' => $rights,
				],
			];
		}

		if (!$query['query']['bool']['filter']['bool']['must'])
		{
			unset($query['query']['bool']['filter']);
		}

		$order = $this->__PrepareSort($order);
		if ($order)
		{
			$query['sort'] = $order;
		}

		$r = $this->query('GET', '/' . $this->indexName . '-' . $site_id . '/_search', $query);
		if (!$r)
		{
			throw new \Bitrix\Main\DB\SqlQueryException('OpenSearch query error', $this->getError(), json_encode($query));
		}
		else
		{
			$result = [];
			if (
				is_array($r)
				&& isset($r['hits'])
				&& is_array($r['hits'])
				&& isset($r['hits']['hits'])
				&& is_array($r['hits']['hits'])
			)
			{
				foreach ($r['hits']['hits'] as $searchHit)
				{
					$result[] = $searchHit['fields']['id'][0];
				}
			}

			return $result;
		}
	}

	public function getRowFormatter()
	{
		return new CSearchOpenSearchFormatter($this);
	}

	public function filterField($field, $value, $logic = 'should')
	{
		$arWhere = [];

		if (is_array($value))
		{
			if (!empty($value))
			{
				$arWhere = [
					'bool' => [
						$logic => [
						]
					]
				];
				foreach ($value as $v)
				{
					$arWhere['bool'][$logic][] = [
						'term' => [
							$field => [
								'value' => $v
							]
						]
					];
				}
			}
		}
		else
		{
			if ($value !== false)
			{
				$arWhere = [
					'bool' => [
						$logic => [
							'term' => [
								$field => [
									'value' => $value
								]
							]
						]
					]
				];
			}
		}

		return $arWhere;
	}

	public function prepareFilter($arFilter)
	{
		if (!is_array($arFilter))
		{
			$arFilter = [];
		}

		if (array_key_exists('LOGIC', $arFilter) && $arFilter['LOGIC'] == 'OR')
		{
			$logic = 'should';
			unset($arFilter['LOGIC']);
		}
		else
		{
			$logic = 'must';
		}

		$arWhere = [
			'bool' => [
				$logic => [
				]
			]
		];

		foreach ($arFilter as $field => $val)
		{
			$field = mb_strtoupper($field);
			if (
				is_array($val)
				&& count($val) == 1
				&& $field !== 'URL'
				&& $field !== 'PARAMS'
				&& !is_numeric($field)
			)
			{
				$val = $val[0];
			}

			$negate = false;
			if (str_starts_with($field, '!'))
			{
				$negate = true;
				$field = mb_substr($field, 1);
			}
			$field = mb_strtolower($field);

			switch ($field)
			{
			case 'item_id':
			case '=item_id':
			case 'module_id':
			case '=module_id':
			case 'param1':
			case '=param1':
			case 'param2':
			case '=param2':
			case 'user_id':
			case '=user_id':
			case 'entity_id':
			case '=entity_id':
			case 'entity_type_id':
			case '=entity_type_id':
				if ($negate)
				{
					if ($val !== false  && $val !== 'no' && !is_array($val))
					{
						$arWhere['bool'][$logic][] = $this->filterField($field, $val, 'must_not');
					}
				}
				else
				{
					$cond = $this->filterField($field, $val);
					if ($cond)
					{
						$arWhere['bool'][$logic][] = $cond;
					}
				}
				break;
			case 'DATE_CHANGE':
			case '>=DATE_CHANGE':
				if ($val <> '')
				{
					$ts = MakeTimeStamp($val) - CTimeZone::GetOffset();
					$arWhere['bool'][$logic][] = [
						'range' => [
							'date_change_ts' => [
								'from' => $ts,
								'to' => null,
								'include_lower' => true,
								'include_upper' => true,
							]
						]
					];
				}
				break;
			case '<=DATE_CHANGE':
				if ($val <> '')
				{
					$ts = MakeTimeStamp($val) - CTimeZone::GetOffset();
					$arWhere['bool'][$logic][] = [
						'range' => [
							'date_change_ts' => [
								'from' => null,
								'to' => $ts,
								'include_lower' => true,
								'include_upper' => true,
							]
						]
					];
				}
				break;
			case 'CHECK_DATES':
				if ($val == 'Y')
				{
					$ts = time() - CTimeZone::GetOffset();
					$arWhere['bool'][$logic][] = [
						'bool' => [
							'must' => [
								[
									'bool' => [
										'should' => [
											[
												'term' => [
													'date_from_ts' => [
														'value' => 0,
													]
												]
											],
											[
												'range' => [
													'date_from_ts' => [
														'from' => null,
														'to' => $ts,
														'include_lower' => true,
														'include_upper' => true,
													]
												]
											],
										]
									]
								],
								[
									'bool' => [
										'should' => [
											[
												'term' => [
													'date_to_ts' => [
														'value' => 0,
													]
												]
											],
											[
												'range' => [
													'date_to_ts' => [
														'from' => $ts,
														'to' => null,
														'include_lower' => true,
														'include_upper' => true,
													]
												]
											],
										]
									]
								],
							]
						]
					];
				}
				break;
			case 'TAGS':
				$arTags = explode(',', $val);
				foreach ($arTags as $i => &$strTag)
				{
					$strTag = trim($strTag, " \n\r\t\"");
					if ($strTag === '')
					{
						unset($arTags[$i]);
					}
				}
				unset($strTag);

				$cond = $this->filterField('tag', $arTags, 'must');
				if ($cond)
				{
					$arWhere['bool'][$logic][] = $cond;
				}
				break;
			case 'PARAMS':
				if (is_array($val))
				{
					$params = [];
					foreach ($this->params($val) as $key => $value)
					{
						$params[] = [
							'term' => [
								'param.' . $key => [
									'value' => $value,
								]
							]
						];
					}
					if ($params)
					{
						$arWhere['bool'][$logic][] = [
							'bool' => [
								'must' => $params
							]
						];
					}
				}
				break;
			case 'URL': //TODO
			case 'QUERY':
			case 'LIMIT':
			case 'USE_TF_FILTER':
				break;
			default:
				if (is_numeric($field) && is_array($val))
				{
					$subFilter = $this->prepareFilter($val);
					if ($subFilter)
					{
						$arWhere['bool'][$logic][] = $subFilter;
					}
				}
				else
				{
					//AddMessage2Log("field: $field; val: ".print_r($val, 1));
				}
				break;
			}
		}

		if (!$arWhere['bool'][$logic])
		{
			return [];
		}

		return $arWhere;
	}

	public function CheckPermissions()
	{
		global $USER;
		$DB = CDatabase::GetModuleConnection('search');

		$arResult = [];

		if (!$USER->IsAdmin())
		{
			if ($USER->GetID() > 0)
			{
				CSearchUser::CheckCurrentUserGroups();
				$rs = $DB->Query('SELECT GROUP_CODE FROM b_search_user_right WHERE USER_ID = ' . $USER->GetID());
				while ($ar = $rs->Fetch())
				{
					$arResult[] = $ar['GROUP_CODE'];
				}
			}
			else
			{
				$arResult[] = 'G2';
			}
		}

		return $this->rights($arResult);
	}

	public function __PrepareSort($aSort = [])
	{
		$arOrder = [];
		if (!is_array($aSort))
		{
			$aSort = [$aSort => 'ASC'];
		}

		foreach ($aSort as $key => $ord)
		{
			$ord = mb_strtoupper($ord) !== 'ASC' ? 'desc' : 'asc';
			$key = mb_strtoupper($key);
			switch ($key)
			{
				case 'ID':
				case 'DATE_CHANGE':
				case 'MODULE_ID':
				case 'ITEM_ID':
				case 'CUSTOM_RANK':
				case 'PARAM1':
				case 'PARAM2':
				case 'DATE_FROM':
				case 'DATE_TO':
					$arOrder[mb_strtolower($key)] = ['order' => $ord];
					break;
				case 'RANK':
					$arOrder['_score'] = ['order' => $ord];
					break;
			}
		}

		if (count($arOrder) == 0)
		{
			return $this->__PrepareSort([
				'CUSTOM_RANK' => 'DESC',
				'RANK' => 'DESC',
				'DATE_CHANGE' => 'DESC',
			]);
		}

		return $arOrder;
	}

	protected static function collectError($error)
	{
		if (is_array($error))
		{
			$result = $error['reason'] ?? '';
			if (isset($error['caused_by']))
			{
				$result .= '<br>' . static::collectError($error['caused_by']);
			}
		}
		else
		{
			$result = $error;
		}

		return $result;
	}

	protected ?COpenSearchServer $server = null;

	public function query($verb, $url, $params = '')
	{
		global $DB;

		if (!$this->connected)
		{
			$this->connect();
		}

		$this->errorText = '';
		$this->errorNumber = 0;

		$result = false;

		if ($this->server === null)
		{
			$this->server = extension_loaded('curl') ? new COpenSearchServerCurl($this) : new COpenSearchServerHttpClient($this);
		}

		$this->server->prepareRequest($verb, $url, is_array($params) ? json_encode($params) : $params);

		$stime = 0;
		if ($DB->ShowSqlStat)
		{
			$stime = microtime(1);
		}

		$this->server->sendRequest();

		if ($DB->ShowSqlStat)
		{
			$DB->addDebugQuery($verb . ' ' . $url . "\n" . json_encode($params, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), microtime(1) - $stime);
		}

		$responseStatus = $this->server->responseStatus;
		$responseContents = $this->server->responseContents;

		if (
			$responseStatus === 200
			|| $responseStatus === 201
		)
		{
			$result = json_decode($responseContents, true);
		}

		if (!is_array($result))
		{
			if ($responseStatus)
			{
				$errorText = $responseContents;
				// try to parse
				try
				{
					$error = json_decode($errorText, true);
					if (is_array($error) && isset($error['error']))
					{
						$errorText = static::collectError($error['error']);
					}
				}
				catch (\Throwable $_)
				{
				}
				$this->errorText = $errorText;
				$this->errorNumber = $responseStatus;
			}
			else
			{
				$this->errorText = $this->server->errorText;
			}
		}
		elseif (isset($result['error']))
		{
			$this->errorText = $result['error'];
			$this->errorNumber = $result['status'];
		}

		if ($this->errorText)
		{
			throw new \Bitrix\Main\DB\ConnectionException('OpenSearch query error', $this->errorText);
		}

		return $result;
	}

	public function getError()
	{
		if ($this->errorText)
		{
			$result = '[' . $this->errorNumber . '] ' . $this->errorText;
		}
		else
		{
			$result = '';
		}

		return $result;
	}
}

class CSearchOpenSearchFormatter extends CSearchFormatter
{
	/** @var CSearchOpenSearch */
	private $search = null;

	public function __construct($search)
	{
		$this->search = $search;
	}

	public function format($r)
	{
		if ($r)
		{
			if (array_key_exists('CNT', $r))
			{
				return $r;
			}
			elseif (array_key_exists('ID', $r))
			{
				return $this->formatRow($r);
			}
		}
	}

	public function formatRow($r)
	{
		$DB = CDatabase::GetModuleConnection('search');
		$ID = intval($r['ID']);

		if ($this->search->SITE_ID)
		{
			$rs = $DB->Query('
				select
					sc.ID
					,sc.MODULE_ID
					,sc.ITEM_ID
					,sc.TITLE
					,sc.TAGS
					,sc.BODY
					,sc.PARAM1
					,sc.PARAM2
					,sc.UPD
					,sc.DATE_FROM
					,sc.DATE_TO
					,sc.URL
					,sc.CUSTOM_RANK
					,' . $DB->DateToCharFunction('sc.DATE_CHANGE') . ' as FULL_DATE_CHANGE
					,' . $DB->DateToCharFunction('sc.DATE_CHANGE', 'SHORT') . ' as DATE_CHANGE
					,scsite.SITE_ID
					,scsite.URL SITE_URL
					,sc.USER_ID
				from b_search_content sc
				INNER JOIN b_search_content_site scsite ON sc.ID=scsite.SEARCH_CONTENT_ID
				where ID = ' . $ID . "
				and scsite.SITE_ID = '" . $DB->ForSql($this->search->SITE_ID) . "'
			");
		}
		else
		{
			$rs = $DB->Query('
				select
					sc.ID
					,sc.MODULE_ID
					,sc.ITEM_ID
					,sc.TITLE
					,sc.TAGS
					,sc.BODY
					,sc.PARAM1
					,sc.PARAM2
					,sc.UPD
					,sc.DATE_FROM
					,sc.DATE_TO
					,sc.URL
					,sc.CUSTOM_RANK
					,' . $DB->DateToCharFunction('sc.DATE_CHANGE') . ' as FULL_DATE_CHANGE
					,' . $DB->DateToCharFunction('sc.DATE_CHANGE', 'SHORT') . ' as DATE_CHANGE
					,\'\' as SITE_ID
				from b_search_content sc
				where ID = ' . $ID . '
			');
		}

		$r = $rs->Fetch();

		return $r;
	}
}

abstract class COpenSearchServer
{
	public $responseContents = null;
	public $responseStatus = null;
	public $errorText = '';

	public function __construct(protected CSearchOpenSearch $openSearch)
	{
	}

	abstract public function prepareRequest($verb, $url, $params = '');

	abstract public function sendRequest();
}

class COpenSearchServerHttpClient extends COpenSearchServer
{
	protected ?\Bitrix\Main\Web\HttpClient $server = null;
	protected ?\Bitrix\Main\Web\Http\Request $request = null;

	public function __construct(protected CSearchOpenSearch $openSearch)
	{
		parent::__construct($openSearch);
		$this->server = new \Bitrix\Main\Web\HttpClient([
			'disableSslVerification' => true,
		]);
	}

	public function prepareRequest($verb, $url, $params = '')
	{
		$headers = [];
		if ($this->openSearch->user)
		{
			$headers['Authorization'] = 'Basic ' . base64_encode($this->openSearch->user . ':' . $this->openSearch->password);
		}

		$headers['Content-Type'] = 'application/json';

		$body = new \Bitrix\Main\Web\Http\Stream('php://temp', 'r+');
		$body->write($params);

		$this->request = new \Bitrix\Main\Web\Http\Request(
			$verb,
			new \Bitrix\Main\Web\Uri($this->openSearch->connectionString . $url),
			$headers,
			$body
		);
	}

	public function sendRequest()
	{
		$response = $this->server->sendRequest($this->request);
		$this->responseStatus = $response->getStatusCode();
		$this->responseContents = (string)$response->getBody();

		if (!$this->responseStatus)
		{
			$errors = $this->server->getError();
			$this->errorText = $errors ? implode(' ', $errors) : '-1';
		}
	}
}

class COpenSearchServerCurl extends COpenSearchServer
{
	protected $server = null;

	public function __construct(protected CSearchOpenSearch $openSearch)
	{
		parent::__construct($openSearch);
		$this->server = curl_init();
	}

	public function prepareRequest($verb, $url, $params = '')
	{
		curl_setopt($this->server, CURLOPT_URL, $this->openSearch->connectionString . $url);
		curl_setopt($this->server, CURLOPT_RETURNTRANSFER, true);

		// Disable SSL verification
		curl_setopt($this->server, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($this->server, CURLOPT_SSL_VERIFYHOST, false);

		$headers = [];
		if ($this->openSearch->user)
		{
			$credentials = base64_encode($this->openSearch->user . ':' . $this->openSearch->password);
			$headers[] = 'Authorization: Basic ' . $credentials;
		}

		$headers[] = 'Content-Type: application/json';
		if ($params)
		{
			curl_setopt($this->server, CURLOPT_POSTFIELDS, $params);
			$headers[] = 'Content-Length: ' . strlen($params);
		}

		curl_setopt($this->server, CURLOPT_HTTPHEADER, $headers);

		curl_setopt($this->server, CURLOPT_CUSTOMREQUEST, $verb);
	}

	public function sendRequest()
	{
		$this->responseContents = curl_exec($this->server);
		if ($this->responseContents === false)
		{
			$this->responseStatus = 0;
			$this->errorText = curl_error($this->server) . ' [' . curl_errno($this->server) . ']';
		}
		else
		{
			$this->responseStatus = curl_getinfo($this->server, CURLINFO_HTTP_CODE);
		}
	}
}

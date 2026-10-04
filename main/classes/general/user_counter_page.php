<?php

use Bitrix\Main\Config\Option;
use Bitrix\Main\Application;

class CUserCounterPage
{
	public static function getPageSizeOption($defaultValue = 100)
	{
		$value = (int)Option::get('main', 'user_counter_pull_page_size', $defaultValue);
		if ($value <= 0)
		{
			$value = $defaultValue;
		}

		return $value;
	}

	public static function checkSendCounter()
	{
		global $DB, $USER;

		if (!CUserCounter::CheckLiveMode())
		{
			return;
		}

		$connection = Application::getConnection();

		if(!$connection->lock('counterpull'))
		{
			return;
		}

		$counterPageSize = (int)static::getPageSizeOption(100);

		$userSQL = "SELECT USER_ID FROM b_user_counter WHERE SENT='0' GROUP BY USER_ID LIMIT ".$counterPageSize;
		$res = $DB->Query($userSQL);

		$pullMessage = [];
		$userIdList = [];

		while ($row = $res->fetch())
		{
			$userIdList[] = (int)$row["USER_ID"];
		}

		if (
			is_object($USER)
			&& $USER->isAuthorized()
			&& count($userIdList) >= $counterPageSize
			&& !in_array((int)$USER->getId(), $userIdList, true)
		)
		{
			$userIdList[] = (int)$USER->getId();
		}

		$userString = '';
		foreach($userIdList as $userId)
		{
			$userString .= ($userString <> ''? ', ' : '').$userId;
		}

		if ($userString <> '')
		{
			$arSites = array();
			$by = '';
			$order = '';
			$res = CSite::GetList($by, $order, array("ACTIVE" => "Y"));
			while($row = $res->Fetch())
			{
				$arSites[] = $row['ID'];
			}

			$helper = $connection->getSqlHelper();

			$strSQL = "
				SELECT uc.USER_ID as CHANNEL_ID, uc.USER_ID, uc.SITE_ID, uc.CODE, uc.CNT
				FROM b_user_counter uc
				INNER JOIN b_user u ON u.ID = uc.USER_ID AND (CASE WHEN u.EXTERNAL_AUTH_ID IN ('" . implode("', '", \Bitrix\Main\UserTable::getExternalUserTypes()) . "') THEN 'Y' ELSE 'N' END) = 'N' AND u.LAST_ACTIVITY_DATE > " . $helper->addSecondsToDateTime('(-3600)')."
				WHERE uc.USER_ID IN (".$userString.") AND uc.CODE NOT LIKE '" . CUserCounter::LIVEFEED_CODE . "L%' AND uc.SENT = '0'
			";

			$res = $DB->Query($strSQL);
			while($row = $res->Fetch())
			{
				CUserCounter::addValueToPullMessage($row, $arSites, $pullMessage);
			}

			$strSQL = "
				SELECT uc.USER_ID as CHANNEL_ID, uc.USER_ID, uc.SITE_ID, uc.CODE, uc.CNT
				FROM b_user_counter uc
				INNER JOIN b_user u ON u.ID = uc.USER_ID AND (CASE WHEN u.EXTERNAL_AUTH_ID IN ('" . implode("', '", \Bitrix\Main\UserTable::getExternalUserTypes()) . "') THEN 'Y' ELSE 'N' END) = 'N' AND u.LAST_ACTIVITY_DATE > " . $helper->addSecondsToDateTime('(-3600)')."
				WHERE uc.USER_ID IN (" . $userString . ") AND uc.CODE LIKE '" . CUserCounter::LIVEFEED_CODE . "L%'
			";

			$res = $DB->Query($strSQL);
			while($row = $res->Fetch())
			{
				CUserCounter::addValueToPullMessage($row, $arSites, $pullMessage);
			}

			$DB->Query("UPDATE b_user_counter SET SENT = '1' WHERE SENT = '0' AND USER_ID IN (".$userString.")");
		}

		$connection->unlock('counterpull');

		foreach ($pullMessage as $channelId => $arMessage)
		{
			\Bitrix\Pull\Event::add($channelId, Array(
				'module_id' => 'main',
				'command' => 'user_counter',
				'expiry' => 3600,
				'params' => $arMessage,
			));
		}
	}
}

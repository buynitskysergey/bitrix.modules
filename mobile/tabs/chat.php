<?php
namespace Bitrix\Mobile\AppTabs;

use Bitrix\ImMobile\NavigationTab\Manager;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Mobile\Tab\Tabable;

/**
 * proxy class
 * @see \Bitrix\ImMobile\Manager
 */
class Chat implements Tabable
{
	private $context;
	
	public function isAvailable()
	{
		return (
			Loader::includeModule('im')
			&& Loader::includeModule('immobile')
			&& Loader::includeModule('mobileapp')
		);
	}
	
	public function getData()
	{
		if (!$this->isAvailable())
		{
			return null;
		}
		
		$messengerNavigationManager = new Manager($this->context);
		if ($messengerNavigationManager->isNextNavigation())
		{
			return $messengerNavigationManager->getMessengerComponent();
		}
		
		return $messengerNavigationManager->getOldChatComponent();
	}
	
	public function getMenuData()
	{
		return null;
	}
	
	public function shouldShowInMenu()
	{
		return false;
	}
	
	public function canBeRemoved()
	{
		return false;
	}
	
	/**
	 * @return integer
	 */
	public function defaultSortValue()
	{
		return 100;
	}
	
	public function canChangeSort()
	{
		return true;
	}
	
	public function getTitle()
	{
		return Loc::getMessage("TAB_NAME_IM_RECENT");
	}
	
	public function setContext($context)
	{
		$this->context = $context;
	}
	
	public function getShortTitle()
	{
		return Loc::getMessage("TAB_NAME_IM_RECENT_SHORT");
	}
	
	public function getId()
	{
		return "chats";
	}
	
	public function getIconId(): string
	{
		return 'chat';
	}
}

<?php

namespace Bitrix\Mobile\Internal\Onboarding;

enum PushType: string
{
	case INVITE = 'invite';
	case DESKTOP = 'desktop';
	case COPILOT = 'copilot';
	case CRM = 'crm';
	case TASK = 'task';
	case CALENDAR = 'calendar';
	case RETURN = 'return';
	case DEMO_WEB = 'demo_web';
	case SET_NOTIFICATIONS = 'set_notifications';
	case PROMOTION = 'promotion';

	public function getActionType(): string
	{
		return match ($this) {
			self::INVITE => 'MOBILE_OPEN_INVITE',
			self::DESKTOP => 'MOBILE_OPEN_DESKTOP',
			self::COPILOT => 'MOBILE_OPEN_COPILOT_CHAT',
			self::CRM => 'MOBILE_OPEN_CRM_TAB',
			self::TASK => 'MOBILE_OPEN_TASK_TAB',
			self::CALENDAR => 'MOBILE_OPEN_CALENDAR_TAB',
			self::RETURN => 'MOBILE_OPEN_RETURN',
			self::DEMO_WEB => 'MOBILE_OPEN_DEMO_WEB',
			self::SET_NOTIFICATIONS => 'MOBILE_OPEN_SET_NOTIFICATIONS',
			self::PROMOTION => 'MOBILE_PROMOTION',
		};
	}

	public function getActionMoreType(): ?string
	{
		return match ($this) {
			self::CRM => 'MOBILE_OPEN_CRM_TAB_FROM_MORE',
			self::TASK => 'MOBILE_OPEN_TASK_TAB_FROM_MORE',
			self::CALENDAR => 'MOBILE_OPEN_CALENDAR_TAB_FROM_MORE',
			default => null,
		};
	}

	public function getAnalyticsEvent(): string
	{
		return match ($this) {
			self::CRM => 'push_mobile_1-8d_crm_send_attempt',
			self::TASK => 'push_mobile_1-8d_tasks_send_attempt',
			self::DESKTOP => 'push_mobile_1-8d_web_send_attempt',
			self::INVITE => 'push_mobile_1-8d_invite_send_attempt',
			self::COPILOT => 'push_mobile_1-8d_copilot_send_attempt',
			self::PROMOTION => 'push_mobile_promo1_send_attempt',
			self::RETURN => 'push_mobile_1-8d_return_send_attempt',
			self::DEMO_WEB => 'push_mobile_1-8d_demo_web_send_attempt',
			self::SET_NOTIFICATIONS => 'push_mobile_1-8d_set_notifications_send_attempt',
			self::CALENDAR => 'push_mobile_1-8d_calendar_send_attempt',
		};
	}
}

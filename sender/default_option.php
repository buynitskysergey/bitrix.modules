<?php
$isCloudRegionMayTrackMails = \Bitrix\Main\Loader::includeModule('sender')
	&& \Bitrix\Sender\Integration\Bitrix24\Service::isCloudRegionMayTrackMails();

$sender_default_option = [
	'interval'=>'20',
	'auto_method'=>'agent',
	'max_emails_per_hit'=>'500',
	'max_emails_per_cron'=>'500',
	'reiterate_method'=>'agent',
	'reiterate_interval'=>'60',
	'address_from'=>'',
	'address_send_to_me'=>'',
	'unsub_link'=>'',
	'sub_link'=>'',
	'auto_agent_interval'=>'0',
	'track_mails'=> $isCloudRegionMayTrackMails ? 'N' : 'Y',
	"mail_consent" => $isCloudRegionMayTrackMails ? 'Y' : 'N',
	"~mail_max_consent_requests" => "1",
	\Bitrix\Sender\Integration\Sender\Mail\FeedbackId::OPTION_SOURCE_ID => '',
	\Bitrix\Sender\Integration\Sender\Mail\FeedbackId::OPTION_SENDER_ID => '',
	\Bitrix\Sender\Integration\Sender\Mail\FeedbackId::OPTION_ENABLED => 'N',
	\Bitrix\Sender\Integration\Sender\Mail\FeedbackId::OPTION_PORTAL_ID => '',
];

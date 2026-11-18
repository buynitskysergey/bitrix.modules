<?php

/**
 * Bitrix Framework
 * @package bitrix
 * @subpackage mail
 * @copyright 2001-2025 Bitrix
 */

require_once($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/mail/constants.php");

\Bitrix\Main\Loader::registerClassAliases([
	'Bitrix\Mail\Public\Service\Mailbox\AddressResolver'
		=> 'Bitrix\Mail\Public\Service\Mailbox\Alias\AddressResolver',
	'Bitrix\Mail\Public\Service\Mailbox\ResolvedMailbox'
		=> 'Bitrix\Mail\Public\Service\Mailbox\Alias\ResolvedMailbox',
	'Bitrix\Mail\Public\Service\Mailbox\AddressHistory'
		=> 'Bitrix\Mail\Public\Service\Mailbox\Alias\AddressHistory',
	'Bitrix\Mail\Public\Service\Mailbox\AddressOccupation'
		=> 'Bitrix\Mail\Public\Service\Mailbox\Alias\AddressOccupation',
	'Bitrix\Mail\Public\Service\Mailbox\AddressOccupationStatus'
		=> 'Bitrix\Mail\Public\Service\Mailbox\Alias\AddressOccupationStatus',
	'Bitrix\Mail\Public\Service\Mailbox\OrphanAlias'
		=> 'Bitrix\Mail\Public\Service\Mailbox\Alias\OrphanAlias',
]);

\Bitrix\Main\Loader::registerAutoloadClasses(
	'mail',
	array(
		'CMail'                => 'classes/general/mail.php',
		'CMailError'           => 'classes/general/mail.php',
		'CMailHeader'          => 'classes/general/mail.php',
		'CMailAttachment'      => 'classes/general/mail.php',
		'CMailFilter'          => 'classes/general/mail.php',
		'CMailFilterCondition' => 'classes/general/mail.php',
		'CMailLog'             => 'classes/general/mail.php',

		'CMailbox'             => 'classes/mysql/mail.php',
		'CMailUtil'            => 'classes/mysql/mail.php',
		'CMailMessage'         => 'classes/mysql/mail.php',

		'CSMTPServer'          => 'classes/general/smtp.php',
		'CSMTPServerHost'      => 'classes/general/smtp.php',
		'CSMTPConnection'      => 'classes/general/smtp.php',

		'CMailImap'            => 'classes/general/imap.php',

		'CMailRestService'     => 'classes/general/rest.php',

		'CMailDomain'          => 'classes/general/domain.php',
		'CMailYandex'          => 'classes/general/yandex.php',
		'CMailDomain2'         => 'classes/general/domain2.php',
		'CMailYandex2'         => 'classes/general/yandex2.php',

		'CMailDomainRegistrar' => 'classes/general/domain_registrar.php',
		'CMailRegru'           => 'classes/general/regru.php',

		'Bitrix\Mail\BaseException'     => 'lib/exception.php',
		'Bitrix\Mail\ReceiverException' => 'lib/exception.php',

		// The ajax router builds the class name from the lowercase action, so a controller in a
		// case sensitive path is only reachable through an explicit entry.
		'Bitrix\Mail\Controller\MessageConversation' => 'lib/controller/messageconversation.php',
	)
);

// Dev/QA controllers (e2e provisioning among them) live under dev/, which is not part of a shipped
// portal, so they exist only in a developer checkout. Registered the way ui, imbot and statistic
// register theirs: the whole dev/ under Controller\Dev - Controller, not Dev, because the action
// resolver only routes to classes under the module's controller namespaces from .settings.php. Each
// controller under it guards itself in processBeforeAction.
if (is_dir(__DIR__ . '/dev'))
{
	\Bitrix\Main\Loader::registerNamespace('Bitrix\\Mail\\Controller\\Dev', __DIR__ . '/dev');
}

<?php

// Dev/QA controllers (e2e provisioning among them) live under dev/, which is not part of a
// shipped portal, so they exist only in a developer checkout. Controller\Dev, not Dev: the
// action resolver only routes to classes under the module's controller namespaces from
// .settings.php. Each controller under it guards itself in processBeforeAction.
if (is_dir(__DIR__ . '/dev'))
{
	\Bitrix\Main\Loader::registerNamespace('Bitrix\\StaffTrack\\Controller\\Dev', __DIR__ . '/dev');
}

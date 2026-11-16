<?php

// Developer mode. Controller\Dev, not Dev: the action resolver builds a class only under the
// module's controller namespaces, so a handler registered as Bitrix\Note\Dev is unreachable by
// any action name. The directory gate is the guard itself - dev/ is not part of a shipped
// portal, so on a client install there is no class to resolve.
if (is_dir(__DIR__ . '/dev'))
{
	\Bitrix\Main\Loader::registerNamespace('Bitrix\\Note\\Controller\\Dev', __DIR__ . '/dev');
}

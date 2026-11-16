<?php

include(__DIR__ . "/include/class_aliases.php");
include(__DIR__ . "/include/autoload.php");
include(__DIR__ . "/include/assets.php");

if (is_dir(__DIR__ . '/dev'))
{
	\Bitrix\Main\Loader::registerNamespace('Bitrix\\Sender\\UI\\Controller\\Dev', __DIR__ . '/dev');
}

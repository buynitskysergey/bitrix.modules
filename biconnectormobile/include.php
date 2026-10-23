<?php

use Bitrix\Main\Loader;

Loader::requireModule('mobile');
Loader::requireModule('mobileapp');

// biconnector is deliberately not required here: the module may outlive it on a portal where the
// administrator has removed the BI Builder, and a throwing include() would take down every consumer
// of this module - the application menu is built through an event handler of ours. Without biconnector
// the feature simply has no entry points (AccessManager) and refuses its actions (MobileBIFeatureAccess).

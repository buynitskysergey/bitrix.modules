<?php

namespace Bitrix\BIConnector\Superset\Grid\Row\Action;

use Bitrix\Main\Grid\Row\Action\DataProvider;

class DashboardTagDataProvider extends DataProvider
{
	public function prepareActions(): array
	{
		return [
			new EditTagAction(),
			new DeleteTagAction(),
		];
	}
}

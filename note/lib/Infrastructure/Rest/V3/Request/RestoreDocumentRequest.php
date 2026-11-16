<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Rest\V3\Request;

use Bitrix\Rest\V3\Interaction\Request\Request;

class RestoreDocumentRequest extends Request
{
	public int $id;

	/**
	 * Where the document lands. Absent means the collection it was deleted from, which is where a
	 * restore usually goes. A document whose collection is gone has nowhere to return to and is
	 * refused until this names one.
	 */
	public ?int $collectionId = null;
}

<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Rest\V3\Request;

use Bitrix\Rest\V3\Interaction\Request\Request;

class MoveCollectionRequest extends Request
{
	public int $id;

	/**
	 * The sort weight the answers carry: a number above a neighbour's weight lands the collection above
	 * it, and a number between two neighbours lands it between them. Absent puts the collection last.
	 *
	 * The weight that ends up stored is recomputed from the neighbours, so the answer is the collection
	 * as it was saved rather than an echo of the request.
	 */
	public ?int $position = null;
}

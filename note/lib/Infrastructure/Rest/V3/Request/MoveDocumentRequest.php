<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Rest\V3\Request;

use Bitrix\Rest\V3\Interaction\Request\Request;

class MoveDocumentRequest extends Request
{
	public int $id;

	/** Target collection. Absent means the collection the document is in now. */
	public ?int $collectionId = null;

	/**
	 * Target parent. Absent means a root of the target collection - the request describes where the
	 * document lands, not what changes about it, so a reorder among children names its parent too.
	 */
	public ?int $parentId = null;

	/**
	 * The sort weight the answers carry: a number above a neighbour's weight lands the document above
	 * it, and a number between two neighbours lands it between them. Absent puts the document last.
	 *
	 * The weight that ends up stored is recomputed from the neighbours, so the answer is the document
	 * as it was saved rather than an echo of the request.
	 */
	public ?int $position = null;
}

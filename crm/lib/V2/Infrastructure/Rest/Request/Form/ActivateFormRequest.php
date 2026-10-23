<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Form;

use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Rest\V3\Interaction\Request\Request;

/**
 * `crm.form.activate`: which form to turn on.
 *
 * One form named by `id`, as everywhere in the family, and no `filter`: turning on a set of forms
 * would spend the limit of working forms of the whole portal at once, which is a contract of its
 * own rather than a hidden ability of this one. A `filter` a client did send is answered rather
 * than dropped in silence ({@see RefusesFilterAddressing}).
 *
 * The expected version is not taken, unlike in {@see UpdateFormRequest}. This is a switch of state
 * rather than a change of the contents of the form: it is idempotent, so a repeated call is
 * harmless, and demanding a version would make every switch from a list read the form first.
 *
 * Nothing else is carried. The state the call asks for is the method itself, and a form is turned
 * off by {@see DeactivateFormRequest} rather than by a value of this request.
 */
final class ActivateFormRequest extends Request
{
	use RefusesFilterAddressing;

	/** Answer to a request that addresses a set instead of one form. */
	public const FILTER_REFUSED = 'crm.form.activate turns on one form addressed by id and takes no filter.';

	#[NotEmpty]
	#[PositiveNumber]
	public int $id;
}

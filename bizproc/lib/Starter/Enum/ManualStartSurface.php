<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Starter\Enum;

/**
 * The register of the surfaces a workflow can be started from by an employee. A start entry point names
 * itself with one of these values and passes it as {@see \CBPDocument::PARAM_MANUAL_START_SURFACE}; the
 * value means "this start is a manual start of an employee, performed here" and affects nothing but the
 * choice of the template version and the diagnostics of the starts that carry no mark.
 *
 * The register is the single list every entry point works from, including the ones outside this module:
 * a start marked with a literal of its own would be invisible to the completeness check of the surfaces.
 * An automatic start - a document event, an automation rule, a scheduled or a robot start - a child
 * process of the "Start a workflow" step and a debug run carry no mark at all and have no case here.
 *
 * Several surfaces of the product share one identifier when they share the entry point: the start form
 * of the process is opened from the catalog of the start points, from "My processes", from the card of a
 * CRM document and from an element of a list, and all of them start the process through the same
 * controller action. The version acts on the employee and not on the place the form was opened from, so a
 * finer split would name places the resolution rule cannot tell apart anyway.
 */
enum ManualStartSurface: string
{
	// the start form of the process: the slider and every surface that opens it
	case StartForm = 'bizproc.start_form';

	// the manual start by a trigger button of the scheme
	case TriggerButton = 'bizproc.trigger_button';

	case Rest = 'bizproc.rest';

	// the start page of the administrative section of the box
	case AdminStartPage = 'bizproc.admin_start';

	// the public and the administrative forms of an information block element
	case IblockElementForm = 'iblock.element_form';
	case IblockElementAdmin = 'iblock.element_admin';

	case WebdavElement = 'webdav.element';
	case DiskFile = 'disk.file';
}

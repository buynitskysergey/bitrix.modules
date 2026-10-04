<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Service;

use Bitrix\Bizproc\Internal\Access\AccessController;
use Bitrix\Bizproc\Internal\Access\Model\TemplateItem;
use Bitrix\Bizproc\Internal\Access\Permission\PermissionDictionary;

/**
 * Single public entry point for workflow-template ACL checks. Every channel (chart editor, template
 * service, classic designer, grid/REST, permissions page) asks this facade instead of the legacy
 * document-type gate.
 *
 * publish dominates edit per template (SDD invariant 1): a holder of "publish" on a template may also
 * edit that template, but not the other way around. The OR lives here and is evaluated per concrete
 * template — never as a global "has any publish" shortcut. The other rights are independent.
 *
 * A null $userId resolves the CURRENT user (never someone else's or an anonymous one). $documentType is
 * reserved for the document-type / owner scope the rule may consume later; the current flat-matrix rule
 * ignores it.
 */
class TemplateAccessService
{
	public function canAccessTemplates(?int $userId = null): bool
	{
		$controller = $this->controller($userId);

		return $controller->check((string)PermissionDictionary::BIZPROC_TEMPLATE_CREATE)
			|| $controller->check((string)PermissionDictionary::BIZPROC_TEMPLATE_EDIT)
			|| $controller->check((string)PermissionDictionary::BIZPROC_TEMPLATE_PUBLISH);
	}

	public function canCreate(?int $userId = null, array $documentType = []): bool
	{
		// CREATE is a scope-less toggler: no template item participates.
		return $this->controller($userId)->check((string)PermissionDictionary::BIZPROC_TEMPLATE_CREATE);
	}

	public function canEdit(int $templateId, ?int $userId = null, array $documentType = []): bool
	{
		return $this->check(PermissionDictionary::BIZPROC_TEMPLATE_EDIT, $templateId, $userId)
			|| $this->check(PermissionDictionary::BIZPROC_TEMPLATE_PUBLISH, $templateId, $userId);
	}

	public function canPublish(int $templateId, ?int $userId = null, array $documentType = []): bool
	{
		return $this->check(PermissionDictionary::BIZPROC_TEMPLATE_PUBLISH, $templateId, $userId);
	}

	public function canDelete(int $templateId, ?int $userId = null, array $documentType = []): bool
	{
		return $this->check(PermissionDictionary::BIZPROC_TEMPLATE_DELETE, $templateId, $userId);
	}

	public function canConfigureRights(?int $userId = null): bool
	{
		// CONFIGURE_RIGHTS is a scope-less toggler: holding it (or being an admin) grants full access to the
		// rights configuration (any right, every role, every template). No template item participates.
		return $this->controller($userId)->check((string)PermissionDictionary::BIZPROC_TEMPLATE_CONFIGURE_RIGHTS);
	}

	private function check(int $permission, int $templateId, ?int $userId): bool
	{
		return $this->controller($userId)->check((string)$permission, new TemplateItem($templateId));
	}

	protected function controller(?int $userId): AccessController
	{
		return $userId === null
			? AccessController::getCurrent()
			: AccessController::getInstance($userId);
	}
}

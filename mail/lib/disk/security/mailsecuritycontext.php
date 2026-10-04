<?php

namespace Bitrix\Mail\Disk\Security;

use Bitrix\Main;
use Bitrix\Mail;
use Bitrix\Disk;

if (!Main\Loader::includeModule('disk'))
{
	return false;
}

class MailSecurityContext extends Disk\Security\SecurityContext
{

	/**
	 * @param $targetId
	 * @return bool
	 */
	public function canAdd($targetId)
	{
		return true;
	}

	/**
	 * @param $objectId
	 * @return bool
	 */
	public function canChangeRights($objectId)
	{
		return false;
	}

	/**
	 * @param $objectId
	 * @return bool
	 */
	public function canChangeSettings($objectId)
	{
		return false;
	}

	/**
	 * @param $objectId
	 * @return bool
	 */
	public function canCreateWorkflow($objectId)
	{
		return false;
	}

	/**
	 * @param $objectId
	 * @return bool
	 */
	public function canDelete($objectId)
	{
		return false;
	}

	/**
	 * @param $objectId
	 * @return bool
	 */
	public function canMarkDeleted($objectId)
	{
		return false;
	}

	/**
	 * @param $objectId
	 * @param $targetId
	 * @return bool
	 */
	public function canMove($objectId, $targetId)
	{
		return false;
	}

	/**
	 * @param $objectId
	 * @return bool
	 */
	public function canRead($objectId)
	{
		global $DB;

		$message = $DB->query(sprintf(
			'SELECT ID, MAILBOX_ID FROM b_mail_message WHERE ID IN (
				SELECT MESSAGE_ID FROM b_mail_msg_attachment WHERE FILE_ID = (
					SELECT FILE_ID FROM b_disk_object WHERE ID = %u
				)
			)',
			$objectId
		))->fetch();

		if (Mail\Helper\Message::hasAccess($message, $this->userId))
		{
			return true;
		}

		return $this->isOwnDraftAttachment($objectId);
	}

	/**
	 * Draft-owned copies have no b_mail_msg_attachment row until the draft is sent,
	 * so their author reads them through the draft binding.
	 *
	 * @param $objectId
	 * @return bool
	 */
	private function isOwnDraftAttachment($objectId)
	{
		$userId = (int)$this->userId;
		if ($userId <= 0 || !Mail\Helper\Config\Feature::isInternalDraftsAvailable())
		{
			return false;
		}

		static $draftTablesExist = null;
		if ($draftTablesExist === null)
		{
			$connection = Main\Application::getConnection();
			$draftTablesExist =
				$connection->isTableExists(Mail\Internals\DraftTable::getTableName())
				&& $connection->isTableExists(Mail\Internals\DraftAttachmentTable::getTableName())
			;
		}
		if (!$draftTablesExist)
		{
			return false;
		}

		$draft = Mail\Internals\DraftAttachmentTable::query()
			->setSelect([
				'DRAFT_CONTEXT_TYPE' => 'DRAFT.CONTEXT_TYPE',
				'DRAFT_CRM_ENTITY_TYPE_ID' => 'DRAFT.CRM_ENTITY_TYPE_ID',
				'DRAFT_CRM_ENTITY_ID' => 'DRAFT.CRM_ENTITY_ID',
			])
			->registerRuntimeField(
				new Main\ORM\Fields\Relations\Reference(
					'DRAFT',
					Mail\Internals\DraftTable::class,
					Main\ORM\Query\Join::on('this.DRAFT_ID', 'ref.ID'),
					['join_type' => Main\ORM\Query\Join::TYPE_INNER],
				),
			)
			->registerRuntimeField(
				new Main\ORM\Fields\Relations\Reference(
					'DISK_OBJECT',
					Disk\Internals\ObjectTable::class,
					Main\ORM\Query\Join::on('this.FILE_ID', 'ref.FILE_ID'),
					['join_type' => Main\ORM\Query\Join::TYPE_INNER],
				),
			)
			->where('DRAFT.USER_ID', $userId)
			->where('DRAFT.STATUS', Mail\Internals\DraftTable::STATUS_ACTIVE)
			->where('DRAFT.DATE_EXPIRE', '>', new Main\Type\DateTime())
			->where('DISK_OBJECT.ID', (int)$objectId)
			->setLimit(1)
			->fetch()
		;
		if ($draft === false)
		{
			return false;
		}
		if ($draft['DRAFT_CONTEXT_TYPE'] !== Mail\Internals\DraftTable::CONTEXT_CRM)
		{
			return true;
		}
		if (!Main\Loader::includeModule('crm'))
		{
			return false;
		}

		return \CCrmActivity::checkUpdatePermission(
			(int)$draft['DRAFT_CRM_ENTITY_TYPE_ID'],
			(int)$draft['DRAFT_CRM_ENTITY_ID'],
			\CCrmPerms::getUserPermissions($userId),
		);
	}

	/**
	 * @param $objectId
	 * @return bool
	 */
	public function canRename($objectId)
	{
		return false;
	}

	/**
	 * @param $objectId
	 * @return bool
	 */
	public function canRestore($objectId)
	{
		return false;
	}

	/**
	 * @param $objectId
	 * @return bool
	 */
	public function canShare($objectId)
	{
		return false;
	}

	/**
	 * @param $objectId
	 * @return bool
	 */
	public function canUpdate($objectId)
	{
		return false;
	}

	/**
	 * @param $objectId
	 * @return bool
	 */
	public function canStartBizProc($objectId)
	{
		return false;
	}

	public function getSqlExpressionForList($columnObjectId, $columnCreatedBy)
	{
		return '1 = 0';
	}

}

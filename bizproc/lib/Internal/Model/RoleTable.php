<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Model;

use Bitrix\Main\Access\Role\AccessRoleTable;

/**
 * Class RoleTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_Role_Query query()
 * @method static EO_Role_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_Role_Result getById($id)
 * @method static EO_Role_Result getList(array $parameters = [])
 * @method static EO_Role_Entity getEntity()
 * @method static \Bitrix\Bizproc\Internal\Model\EO_Role createObject($setDefaultValues = true)
 * @method static \Bitrix\Bizproc\Internal\Model\EO_Role_Collection createCollection()
 * @method static \Bitrix\Bizproc\Internal\Model\EO_Role wakeUpObject($row)
 * @method static \Bitrix\Bizproc\Internal\Model\EO_Role_Collection wakeUpCollection($rows)
 */
final class RoleTable extends AccessRoleTable
{
	public static function getTableName(): string
	{
		return 'b_bp_access_role';
	}
}

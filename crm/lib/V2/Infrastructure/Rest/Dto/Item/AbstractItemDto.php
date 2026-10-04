<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\Dictionary\WebformDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\LastCommunicationDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\User\EmployeeDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Provider\AssignedUserIdProvider;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Rule\InArrayFrom;
use Bitrix\Main\Type\DateTime;
use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\RelationToOne;
use Bitrix\Rest\V3\Attribute\Sortable;
use Bitrix\Rest\V3\Dto\Dto;

abstract class AbstractItemDto extends Dto
{
	#[Filterable]
	#[Sortable]
	public ?int $id;

	#[Filterable]
	#[Sortable]
	public DateTime $createdTime;
	#[Filterable]
	#[Sortable]
	public ?int $createdById;
	#[RelationToOne('createdById', 'id')]
	public ?EmployeeDto $createdBy;

	#[Filterable]
	#[Sortable]
	public DateTime $updatedTime;
	#[Filterable]
	#[Sortable]
	public ?int $updatedById;
	#[RelationToOne('updatedById', 'id')]
	public ?EmployeeDto $updatedBy;

	#[Filterable]
	#[Sortable]
	public DateTime $lastActivityTime;
	#[Filterable]
	#[Sortable]
	public ?int $lastActivityById;
	#[RelationToOne('lastActivityById', 'id')]
	public ?EmployeeDto $lastActivityBy;

	#[Editable]
	#[Filterable]
	#[Sortable]
	#[InArrayFrom(AssignedUserIdProvider::class)]
	public int $assignedById;
	#[RelationToOne('assignedById', 'id')]
	public ?EmployeeDto $assignedBy;

	#[Editable]
	#[Filterable]
	#[Sortable]
	public ?bool $opened;

	#[Editable]
	public ?string $comments = null;

	#[Editable]
	#[Filterable]
	#[Sortable]
	public ?string $originatorId = null;

	#[Editable]
	#[Filterable]
	#[Sortable]
	public ?string $originId = null;

	#[Editable]
	public ?string $originVersion = null;
	#[Filterable]
	#[Sortable]
	public ?int $webformId;
	#[RelationToOne('webformId', 'id')]
	public ?WebformDto $webform;
	#[RelationToOne('lastCommunication', 'communicationTime')]
	public ?LastCommunicationDto $lastCommunication;
}

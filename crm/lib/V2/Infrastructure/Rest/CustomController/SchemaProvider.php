<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\CustomController;

use Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Activity\Mail;
use Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Category\RouteBuilder;
use Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Dictionary\AddressType;
use Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Dictionary\StageSemantic;
use Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Field\Field;
use Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Item\Item;
use Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Item\ProductRow\Field as ProductRowField;
use Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Item\ProductRow\ProductRow;
use Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Lead\Address;
use Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Product\Product;
use Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Requisite\Requisite;
use Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Timeline\Activity\Email\Email;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Dictionary\AddressTypeDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Dictionary\EntityTypeDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Dictionary\StageSemanticDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Field\CrmFieldMetadataDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\ItemDtoGenerator;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\ProductRow\ProductRowDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Lead\AddressDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Product\ProductDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Requisite\RequisiteDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Timeline\Activity\Email\EmailActivityDto;
use Bitrix\Crm\V2\Internal\Repository\ProductRow\ProductEnabledTypeRepository;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Crm\V2\Public\OwnerType;
use Bitrix\Crm\Feature;
use Bitrix\Crm\Feature\RestV3CrudDeal;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Application;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Rest\V3\Dto\Generator;
use Bitrix\Rest\V3\Schema\ControllerData;
use Bitrix\Rest\V3\Schema\GeneratedDto;
use Bitrix\Rest\V3\Schema\MethodDescription;

class SchemaProvider extends \Bitrix\Rest\V3\Schema\SchemaProvider
{
	protected const MODULE = 'crm';

	/** @var array<string, class-string<\Bitrix\Crm\Feature\BaseFeature>> */
	protected array $featureByRoute = [
		'crm.deal.add' => RestV3CrudDeal::class,
		'crm.contact.add' => RestV3CrudDeal::class,
		'crm.lead.add' => RestV3CrudDeal::class,
		'crm.company.add' => RestV3CrudDeal::class,
		'crm.quote.add' => RestV3CrudDeal::class,
		'crm.smartInvoice.add' => RestV3CrudDeal::class,
		'crm.deal.addFieldValue' => RestV3CrudDeal::class,
		'crm.lead.addFieldValue' => RestV3CrudDeal::class,
		'crm.quote.addFieldValue' => RestV3CrudDeal::class,
		'crm.smartInvoice.addFieldValue' => RestV3CrudDeal::class,
		'crm.contact.addFieldValue' => RestV3CrudDeal::class,
		'crm.company.addFieldValue' => RestV3CrudDeal::class,
		'crm.deal.update' => RestV3CrudDeal::class,
		'crm.contact.update' => RestV3CrudDeal::class,
		'crm.lead.update' => RestV3CrudDeal::class,
		'crm.company.update' => RestV3CrudDeal::class,
		'crm.quote.update' => RestV3CrudDeal::class,
		'crm.smartInvoice.update' => RestV3CrudDeal::class,
		'crm.deal.delete' => RestV3CrudDeal::class,
		'crm.lead.delete' => RestV3CrudDeal::class,
		'crm.quote.delete' => RestV3CrudDeal::class,
		'crm.smartInvoice.delete' => RestV3CrudDeal::class,
		'crm.deal.deleteFieldValue' => RestV3CrudDeal::class,
		'crm.lead.deleteFieldValue' => RestV3CrudDeal::class,
		'crm.quote.deleteFieldValue' => RestV3CrudDeal::class,
		'crm.smartInvoice.deleteFieldValue' => RestV3CrudDeal::class,
		'crm.deal.get' => RestV3CrudDeal::class,
		'crm.contact.get' => RestV3CrudDeal::class,
		'crm.company.get' => RestV3CrudDeal::class,
		'crm.lead.get' => RestV3CrudDeal::class,
		'crm.quote.get' => RestV3CrudDeal::class,
		'crm.smartInvoice.get' => RestV3CrudDeal::class,
		'crm.deal.list' => RestV3CrudDeal::class,
		'crm.contact.list' => RestV3CrudDeal::class,
		'crm.company.list' => RestV3CrudDeal::class,
		'crm.lead.list' => RestV3CrudDeal::class,
		'crm.quote.list' => RestV3CrudDeal::class,
		'crm.smartInvoice.list' => RestV3CrudDeal::class,
		'crm.deal.field.list' => RestV3CrudDeal::class,
		'crm.deal.field.get' => RestV3CrudDeal::class,
		'crm.contact.field.list' => RestV3CrudDeal::class,
		'crm.contact.field.get' => RestV3CrudDeal::class,
		'crm.lead.field.list' => RestV3CrudDeal::class,
		'crm.lead.field.get' => RestV3CrudDeal::class,
		'crm.company.field.list' => RestV3CrudDeal::class,
		'crm.company.field.get' => RestV3CrudDeal::class,
		'crm.quote.field.list' => RestV3CrudDeal::class,
		'crm.quote.field.get' => RestV3CrudDeal::class,
		'crm.smartInvoice.field.list' => RestV3CrudDeal::class,
		'crm.smartInvoice.field.get' => RestV3CrudDeal::class,
		'crm.dictionary.entityType.get' => RestV3CrudDeal::class,
		'crm.dictionary.entityType.list' => RestV3CrudDeal::class,
		'crm.contact.delete' => RestV3CrudDeal::class,
		'crm.company.delete' => RestV3CrudDeal::class,
		'crm.contact.deleteFieldValue' => RestV3CrudDeal::class,
		'crm.company.deleteFieldValue' => RestV3CrudDeal::class,
		'crm.dictionary.stageSemantic.get' => RestV3CrudDeal::class,
		'crm.dictionary.stageSemantic.list' => RestV3CrudDeal::class,
		'crm.dictionary.addressType.get' => RestV3CrudDeal::class,
		'crm.dictionary.addressType.list' => RestV3CrudDeal::class,
		'crm.lead.address.get' => RestV3CrudDeal::class,
		'crm.lead.address.set' => RestV3CrudDeal::class,
		'crm.lead.address.delete' => RestV3CrudDeal::class,
		'crm.lead.productRow.add' => RestV3CrudDeal::class,
		'crm.lead.productRow.update' => RestV3CrudDeal::class,
		'crm.lead.productRow.get' => RestV3CrudDeal::class,
		'crm.lead.productRow.delete' => RestV3CrudDeal::class,
		'crm.lead.productRow.list' => RestV3CrudDeal::class,
		'crm.lead.productRow.replace' => RestV3CrudDeal::class,
		'crm.lead.productRow.getAvailableForPayment' => RestV3CrudDeal::class,
		'crm.deal.productRow.add' => RestV3CrudDeal::class,
		'crm.deal.productRow.update' => RestV3CrudDeal::class,
		'crm.deal.productRow.get' => RestV3CrudDeal::class,
		'crm.deal.productRow.delete' => RestV3CrudDeal::class,
		'crm.deal.productRow.list' => RestV3CrudDeal::class,
		'crm.deal.productRow.replace' => RestV3CrudDeal::class,
		'crm.deal.productRow.getAvailableForPayment' => RestV3CrudDeal::class,
		'crm.quote.productRow.add' => RestV3CrudDeal::class,
		'crm.quote.productRow.update' => RestV3CrudDeal::class,
		'crm.quote.productRow.get' => RestV3CrudDeal::class,
		'crm.quote.productRow.delete' => RestV3CrudDeal::class,
		'crm.quote.productRow.list' => RestV3CrudDeal::class,
		'crm.quote.productRow.replace' => RestV3CrudDeal::class,
		'crm.quote.productRow.getAvailableForPayment' => RestV3CrudDeal::class,
		'crm.lead.productRow.field.list' => RestV3CrudDeal::class,
		'crm.lead.productRow.field.get' => RestV3CrudDeal::class,
		'crm.deal.productRow.field.list' => RestV3CrudDeal::class,
		'crm.deal.productRow.field.get' => RestV3CrudDeal::class,
		'crm.quote.productRow.field.list' => RestV3CrudDeal::class,
		'crm.quote.productRow.field.get' => RestV3CrudDeal::class,
		'crm.product.list' => RestV3CrudDeal::class,
		'crm.requisite.list' => RestV3CrudDeal::class,
	];

	/**
	 * The seven actions of `crm.{entity}.productRow.*`, in the order of {@see ProductRow}. Kept here for
	 * the routes generated per type; the ones of lead, deal and quote are written out below literally.
	 */
	protected const PRODUCT_ROW_ACTIONS = [
		'add',
		'update',
		'get',
		'delete',
		'list',
		'replace',
		'getAvailableForPayment',
	];

	/**
	 * The two help actions of `crm.{entity}.productRow.field.*`, in the order of {@see ProductRowField}.
	 * Kept apart from the actions above because another controller serves them: the name of the method is
	 * a name of the group either way, but one implementation cannot answer both.
	 */
	protected const PRODUCT_ROW_FIELD_ACTIONS = [
		'list',
		'get',
	];

	protected array $routesConfig = [
		'crm.deal.add' => [
			'controller' => Item::class,
			'method' => 'add',
			'dtoGenerator' => ItemDtoGenerator::class,
			'entityTypeId' => OwnerType::DEAL,
		],
		'crm.contact.add' => [
			'controller' => Item::class,
			'method' => 'add',
			'dtoGenerator' => ItemDtoGenerator::class,
			'entityTypeId' => OwnerType::CONTACT,
		],
		'crm.lead.add' => [
			'controller' => Item::class,
			'method' => 'add',
			'dtoGenerator' => ItemDtoGenerator::class,
			'entityTypeId' => OwnerType::LEAD,
		],
		'crm.company.add' => [
			'controller' => Item::class,
			'method' => 'add',
			'dtoGenerator' => ItemDtoGenerator::class,
			'entityTypeId' => OwnerType::COMPANY,
		],
		'crm.quote.add' => [
			'controller' => Item::class,
			'method' => 'add',
			'dtoGenerator' => ItemDtoGenerator::class,
			'entityTypeId' => OwnerType::QUOTE,
		],
		'crm.smartInvoice.add' => [
			'controller' => Item::class,
			'method' => 'add',
			'dtoGenerator' => ItemDtoGenerator::class,
			'entityTypeId' => OwnerType::SMART_INVOICE,
		],
		'crm.deal.addFieldValue' => [
			'controller' => Item::class,
			'method' => 'addFieldValue',
			'dtoGenerator' => ItemDtoGenerator::class,
			'entityTypeId' => OwnerType::DEAL,
		],
		'crm.lead.addFieldValue' => [
			'controller' => Item::class,
			'method' => 'addFieldValue',
			'dtoGenerator' => ItemDtoGenerator::class,
			'entityTypeId' => OwnerType::LEAD,
		],
		'crm.quote.addFieldValue' => [
			'controller' => Item::class,
			'method' => 'addFieldValue',
			'dtoGenerator' => ItemDtoGenerator::class,
			'entityTypeId' => OwnerType::QUOTE,
		],
		'crm.smartInvoice.addFieldValue' => [
			'controller' => Item::class,
			'method' => 'addFieldValue',
			'dtoGenerator' => ItemDtoGenerator::class,
			'entityTypeId' => OwnerType::SMART_INVOICE,
		],
		'crm.contact.addFieldValue' => [
			'controller' => Item::class,
			'method' => 'addFieldValue',
			'dtoGenerator' => ItemDtoGenerator::class,
			'entityTypeId' => OwnerType::CONTACT,
		],
		'crm.company.addFieldValue' => [
			'controller' => Item::class,
			'method' => 'addFieldValue',
			'dtoGenerator' => ItemDtoGenerator::class,
			'entityTypeId' => OwnerType::COMPANY,
		],
		'crm.deal.update' => [
			'controller' => Item::class,
			'method' => 'update',
			'dtoGenerator' => ItemDtoGenerator::class,
			'entityTypeId' => OwnerType::DEAL,
		],
		'crm.contact.update' => [
			'controller' => Item::class,
			'method' => 'update',
			'dtoGenerator' => ItemDtoGenerator::class,
			'entityTypeId' => OwnerType::CONTACT,
		],
		'crm.lead.update' => [
			'controller' => Item::class,
			'method' => 'update',
			'dtoGenerator' => ItemDtoGenerator::class,
			'entityTypeId' => OwnerType::LEAD,
		],
		'crm.company.update' => [
			'controller' => Item::class,
			'method' => 'update',
			'dtoGenerator' => ItemDtoGenerator::class,
			'entityTypeId' => OwnerType::COMPANY,
		],
		'crm.quote.update' => [
			'controller' => Item::class,
			'method' => 'update',
			'dtoGenerator' => ItemDtoGenerator::class,
			'entityTypeId' => OwnerType::QUOTE,
		],
		'crm.smartInvoice.update' => [
			'controller' => Item::class,
			'method' => 'update',
			'dtoGenerator' => ItemDtoGenerator::class,
			'entityTypeId' => OwnerType::SMART_INVOICE,
		],
		'crm.deal.delete' => [
			'controller' => Item::class,
			'method' => 'delete',
			'dtoGenerator' => ItemDtoGenerator::class,
			'entityTypeId' => OwnerType::DEAL,
		],
		'crm.lead.delete' => [
			'controller' => Item::class,
			'method' => 'delete',
			'dtoGenerator' => ItemDtoGenerator::class,
			'entityTypeId' => OwnerType::LEAD,
		],
		'crm.quote.delete' => [
			'controller' => Item::class,
			'method' => 'delete',
			'dtoGenerator' => ItemDtoGenerator::class,
			'entityTypeId' => OwnerType::QUOTE,
		],
		'crm.smartInvoice.delete' => [
			'controller' => Item::class,
			'method' => 'delete',
			'dtoGenerator' => ItemDtoGenerator::class,
			'entityTypeId' => OwnerType::SMART_INVOICE,
		],
		'crm.deal.deleteFieldValue' => [
			'controller' => Item::class,
			'method' => 'deleteFieldValue',
			'dtoGenerator' => ItemDtoGenerator::class,
			'entityTypeId' => OwnerType::DEAL,
		],
		'crm.lead.deleteFieldValue' => [
			'controller' => Item::class,
			'method' => 'deleteFieldValue',
			'dtoGenerator' => ItemDtoGenerator::class,
			'entityTypeId' => OwnerType::LEAD,
		],
		'crm.quote.deleteFieldValue' => [
			'controller' => Item::class,
			'method' => 'deleteFieldValue',
			'dtoGenerator' => ItemDtoGenerator::class,
			'entityTypeId' => OwnerType::QUOTE,
		],
		'crm.smartInvoice.deleteFieldValue' => [
			'controller' => Item::class,
			'method' => 'deleteFieldValue',
			'dtoGenerator' => ItemDtoGenerator::class,
			'entityTypeId' => OwnerType::SMART_INVOICE,
		],
		'crm.contact.delete' => [
			'controller' => Item::class,
			'method' => 'delete',
			'dtoGenerator' => ItemDtoGenerator::class,
			'entityTypeId' => OwnerType::CONTACT,
		],
		'crm.company.delete' => [
			'controller' => Item::class,
			'method' => 'delete',
			'dtoGenerator' => ItemDtoGenerator::class,
			'entityTypeId' => OwnerType::COMPANY,
		],
		'crm.contact.deleteFieldValue' => [
			'controller' => Item::class,
			'method' => 'deleteFieldValue',
			'dtoGenerator' => ItemDtoGenerator::class,
			'entityTypeId' => OwnerType::CONTACT,
		],
		'crm.company.deleteFieldValue' => [
			'controller' => Item::class,
			'method' => 'deleteFieldValue',
			'dtoGenerator' => ItemDtoGenerator::class,
			'entityTypeId' => OwnerType::COMPANY,
		],
		'crm.deal.get' => [
			'controller' => Item::class,
			'method' => 'get',
			'dtoGenerator' => ItemDtoGenerator::class,
			'entityTypeId' => OwnerType::DEAL,
		],
		'crm.contact.get' => [
			'controller' => Item::class,
			'method' => 'get',
			'dtoGenerator' => ItemDtoGenerator::class,
			'entityTypeId' => OwnerType::CONTACT,
		],
		'crm.company.get' => [
			'controller' => Item::class,
			'method' => 'get',
			'dtoGenerator' => ItemDtoGenerator::class,
			'entityTypeId' => OwnerType::COMPANY,
		],
		'crm.lead.get' => [
			'controller' => Item::class,
			'method' => 'get',
			'dtoGenerator' => ItemDtoGenerator::class,
			'entityTypeId' => OwnerType::LEAD,
		],
		'crm.quote.get' => [
			'controller' => Item::class,
			'method' => 'get',
			'dtoGenerator' => ItemDtoGenerator::class,
			'entityTypeId' => OwnerType::QUOTE,
		],
		'crm.smartInvoice.get' => [
			'controller' => Item::class,
			'method' => 'get',
			'dtoGenerator' => ItemDtoGenerator::class,
			'entityTypeId' => OwnerType::SMART_INVOICE,
		],
		'crm.deal.list' => [
			'controller' => Item::class,
			'method' => 'list',
			'dtoGenerator' => ItemDtoGenerator::class,
			'entityTypeId' => OwnerType::DEAL,
		],
		'crm.contact.list' => [
			'controller' => Item::class,
			'method' => 'list',
			'dtoGenerator' => ItemDtoGenerator::class,
			'entityTypeId' => OwnerType::CONTACT,
		],
		'crm.company.list' => [
			'controller' => Item::class,
			'method' => 'list',
			'dtoGenerator' => ItemDtoGenerator::class,
			'entityTypeId' => OwnerType::COMPANY,
		],
		'crm.lead.list' => [
			'controller' => Item::class,
			'method' => 'list',
			'dtoGenerator' => ItemDtoGenerator::class,
			'entityTypeId' => OwnerType::LEAD,
		],
		'crm.quote.list' => [
			'controller' => Item::class,
			'method' => 'list',
			'dtoGenerator' => ItemDtoGenerator::class,
			'entityTypeId' => OwnerType::QUOTE,
		],
		'crm.smartInvoice.list' => [
			'controller' => Item::class,
			'method' => 'list',
			'dtoGenerator' => ItemDtoGenerator::class,
			'entityTypeId' => OwnerType::SMART_INVOICE,
		],
		'crm.deal.field.list' => [
			'controller' => Field::class,
			'method' => 'list',
			'dtoFqcn' => CrmFieldMetadataDto::class,
			'entityTypeId' => OwnerType::DEAL,
		],
		'crm.deal.field.get' => [
			'controller' => Field::class,
			'method' => 'get',
			'dtoFqcn' => CrmFieldMetadataDto::class,
			'entityTypeId' => OwnerType::DEAL,
		],
		'crm.contact.field.list' => [
			'controller' => Field::class,
			'method' => 'list',
			'dtoFqcn' => CrmFieldMetadataDto::class,
			'entityTypeId' => OwnerType::CONTACT,
		],
		'crm.contact.field.get' => [
			'controller' => Field::class,
			'method' => 'get',
			'dtoFqcn' => CrmFieldMetadataDto::class,
			'entityTypeId' => OwnerType::CONTACT,
		],
		'crm.lead.field.list' => [
			'controller' => Field::class,
			'method' => 'list',
			'dtoFqcn' => CrmFieldMetadataDto::class,
			'entityTypeId' => OwnerType::LEAD,
		],
		'crm.lead.field.get' => [
			'controller' => Field::class,
			'method' => 'get',
			'dtoFqcn' => CrmFieldMetadataDto::class,
			'entityTypeId' => OwnerType::LEAD,
		],
		'crm.company.field.list' => [
			'controller' => Field::class,
			'method' => 'list',
			'dtoFqcn' => CrmFieldMetadataDto::class,
			'entityTypeId' => OwnerType::COMPANY,
		],
		'crm.company.field.get' => [
			'controller' => Field::class,
			'method' => 'get',
			'dtoFqcn' => CrmFieldMetadataDto::class,
			'entityTypeId' => OwnerType::COMPANY,
		],
		'crm.quote.field.list' => [
			'controller' => Field::class,
			'method' => 'list',
			'dtoFqcn' => CrmFieldMetadataDto::class,
			'entityTypeId' => OwnerType::QUOTE,
		],
		'crm.quote.field.get' => [
			'controller' => Field::class,
			'method' => 'get',
			'dtoFqcn' => CrmFieldMetadataDto::class,
			'entityTypeId' => OwnerType::QUOTE,
		],
		'crm.smartInvoice.field.list' => [
			'controller' => Field::class,
			'method' => 'list',
			'dtoFqcn' => CrmFieldMetadataDto::class,
			'entityTypeId' => OwnerType::SMART_INVOICE,
		],
		'crm.smartInvoice.field.get' => [
			'controller' => Field::class,
			'method' => 'get',
			'dtoFqcn' => CrmFieldMetadataDto::class,
			'entityTypeId' => OwnerType::SMART_INVOICE,
		],
		'crm.dictionary.entityType.get' => [
			'controller' => Dictionary\EntityType::class,
			'method' => 'get',
			'dtoFqcn' => EntityTypeDto::class,
		],
		'crm.dictionary.entityType.list' => [
			'controller' => Dictionary\EntityType::class,
			'method' => 'list',
			'dtoFqcn' => EntityTypeDto::class,
		],
		'crm.dictionary.stageSemantic.get' => [
			'controller' => StageSemantic::class,
			'method' => 'get',
			'dtoFqcn' => StageSemanticDto::class,
		],
		'crm.dictionary.stageSemantic.list' => [
			'controller' => StageSemantic::class,
			'method' => 'list',
			'dtoFqcn' => StageSemanticDto::class,
		],
		'crm.dictionary.addressType.get' => [
			'controller' => AddressType::class,
			'method' => 'get',
			'dtoFqcn' => AddressTypeDto::class,
		],
		'crm.dictionary.addressType.list' => [
			'controller' => AddressType::class,
			'method' => 'list',
			'dtoFqcn' => AddressTypeDto::class,
		],
		'crm.lead.address.get' => [
			'controller' => Address::class,
			'method' => 'get',
			'dtoFqcn' => AddressDto::class,
			'scopes' => ['crm'],
		],
		'crm.lead.address.set' => [
			'controller' => Address::class,
			'method' => 'set',
			'dtoFqcn' => AddressDto::class,
			'scopes' => ['crm'],
		],
		'crm.lead.address.delete' => [
			'controller' => Address::class,
			'method' => 'delete',
			'dtoFqcn' => AddressDto::class,
			'scopes' => ['crm'],
		],
		'crm.activity.mail.getContent' => [
			'controller' => Mail::class,
			'method' => 'getContent',
			'dtoFqcn' => EmailActivityDto::class,
		],
		'crm.activity.mail.getThread' => [
			'controller' => Mail::class,
			'method' => 'getThread',
			'dtoFqcn' => EmailActivityDto::class,
		],
		'crm.activity.mail.reply' => [
			'controller' => Mail::class,
			'method' => 'reply',
			'dtoFqcn' => EmailActivityDto::class,
		],
		'crm.deal.timeline.activity.email.list' => [
			'controller' => Email::class,
			'method' => 'list',
			'dtoFqcn' => EmailActivityDto::class,
			'entityTypeId' => OwnerType::DEAL,
		],
		'crm.deal.timeline.activity.email.send' => [
			'controller' => Email::class,
			'method' => 'send',
			'dtoFqcn' => EmailActivityDto::class,
			'entityTypeId' => OwnerType::DEAL,
		],
		'crm.lead.timeline.activity.email.list' => [
			'controller' => Email::class,
			'method' => 'list',
			'dtoFqcn' => EmailActivityDto::class,
			'entityTypeId' => OwnerType::LEAD,
		],
		'crm.lead.timeline.activity.email.send' => [
			'controller' => Email::class,
			'method' => 'send',
			'dtoFqcn' => EmailActivityDto::class,
			'entityTypeId' => OwnerType::LEAD,
		],
		'crm.contact.timeline.activity.email.list' => [
			'controller' => Email::class,
			'method' => 'list',
			'dtoFqcn' => EmailActivityDto::class,
			'entityTypeId' => OwnerType::CONTACT,
		],
		'crm.contact.timeline.activity.email.send' => [
			'controller' => Email::class,
			'method' => 'send',
			'dtoFqcn' => EmailActivityDto::class,
			'entityTypeId' => OwnerType::CONTACT,
		],
		'crm.company.timeline.activity.email.list' => [
			'controller' => Email::class,
			'method' => 'list',
			'dtoFqcn' => EmailActivityDto::class,
			'entityTypeId' => OwnerType::COMPANY,
		],
		'crm.company.timeline.activity.email.send' => [
			'controller' => Email::class,
			'method' => 'send',
			'dtoFqcn' => EmailActivityDto::class,
			'entityTypeId' => OwnerType::COMPANY,
		],
		'crm.lead.productRow.add' => [
			'controller' => ProductRow::class,
			'method' => 'add',
			'dtoFqcn' => ProductRowDto::class,
			'entityTypeId' => OwnerType::LEAD,
		],
		'crm.lead.productRow.update' => [
			'controller' => ProductRow::class,
			'method' => 'update',
			'dtoFqcn' => ProductRowDto::class,
			'entityTypeId' => OwnerType::LEAD,
		],
		'crm.lead.productRow.get' => [
			'controller' => ProductRow::class,
			'method' => 'get',
			'dtoFqcn' => ProductRowDto::class,
			'entityTypeId' => OwnerType::LEAD,
		],
		'crm.lead.productRow.delete' => [
			'controller' => ProductRow::class,
			'method' => 'delete',
			'dtoFqcn' => ProductRowDto::class,
			'entityTypeId' => OwnerType::LEAD,
		],
		'crm.lead.productRow.list' => [
			'controller' => ProductRow::class,
			'method' => 'list',
			'dtoFqcn' => ProductRowDto::class,
			'entityTypeId' => OwnerType::LEAD,
		],
		'crm.lead.productRow.replace' => [
			'controller' => ProductRow::class,
			'method' => 'replace',
			'dtoFqcn' => ProductRowDto::class,
			'entityTypeId' => OwnerType::LEAD,
		],
		'crm.lead.productRow.getAvailableForPayment' => [
			'controller' => ProductRow::class,
			'method' => 'getAvailableForPayment',
			'dtoFqcn' => ProductRowDto::class,
			'entityTypeId' => OwnerType::LEAD,
		],
		'crm.deal.productRow.add' => [
			'controller' => ProductRow::class,
			'method' => 'add',
			'dtoFqcn' => ProductRowDto::class,
			'entityTypeId' => OwnerType::DEAL,
		],
		'crm.deal.productRow.update' => [
			'controller' => ProductRow::class,
			'method' => 'update',
			'dtoFqcn' => ProductRowDto::class,
			'entityTypeId' => OwnerType::DEAL,
		],
		'crm.deal.productRow.get' => [
			'controller' => ProductRow::class,
			'method' => 'get',
			'dtoFqcn' => ProductRowDto::class,
			'entityTypeId' => OwnerType::DEAL,
		],
		'crm.deal.productRow.delete' => [
			'controller' => ProductRow::class,
			'method' => 'delete',
			'dtoFqcn' => ProductRowDto::class,
			'entityTypeId' => OwnerType::DEAL,
		],
		'crm.deal.productRow.list' => [
			'controller' => ProductRow::class,
			'method' => 'list',
			'dtoFqcn' => ProductRowDto::class,
			'entityTypeId' => OwnerType::DEAL,
		],
		'crm.deal.productRow.replace' => [
			'controller' => ProductRow::class,
			'method' => 'replace',
			'dtoFqcn' => ProductRowDto::class,
			'entityTypeId' => OwnerType::DEAL,
		],
		'crm.deal.productRow.getAvailableForPayment' => [
			'controller' => ProductRow::class,
			'method' => 'getAvailableForPayment',
			'dtoFqcn' => ProductRowDto::class,
			'entityTypeId' => OwnerType::DEAL,
		],
		'crm.quote.productRow.add' => [
			'controller' => ProductRow::class,
			'method' => 'add',
			'dtoFqcn' => ProductRowDto::class,
			'entityTypeId' => OwnerType::QUOTE,
		],
		'crm.quote.productRow.update' => [
			'controller' => ProductRow::class,
			'method' => 'update',
			'dtoFqcn' => ProductRowDto::class,
			'entityTypeId' => OwnerType::QUOTE,
		],
		'crm.quote.productRow.get' => [
			'controller' => ProductRow::class,
			'method' => 'get',
			'dtoFqcn' => ProductRowDto::class,
			'entityTypeId' => OwnerType::QUOTE,
		],
		'crm.quote.productRow.delete' => [
			'controller' => ProductRow::class,
			'method' => 'delete',
			'dtoFqcn' => ProductRowDto::class,
			'entityTypeId' => OwnerType::QUOTE,
		],
		'crm.quote.productRow.list' => [
			'controller' => ProductRow::class,
			'method' => 'list',
			'dtoFqcn' => ProductRowDto::class,
			'entityTypeId' => OwnerType::QUOTE,
		],
		'crm.quote.productRow.replace' => [
			'controller' => ProductRow::class,
			'method' => 'replace',
			'dtoFqcn' => ProductRowDto::class,
			'entityTypeId' => OwnerType::QUOTE,
		],
		'crm.quote.productRow.getAvailableForPayment' => [
			'controller' => ProductRow::class,
			'method' => 'getAvailableForPayment',
			'dtoFqcn' => ProductRowDto::class,
			'entityTypeId' => OwnerType::QUOTE,
		],
		// A help method carries two DTO: `dtoFqcn` is the one it answers with and its `select` names, while
		// the query param of the same name is the one it describes. The second is a service parameter of
		// the route on purpose - read from the request object, it would be the client's to choose.
		'crm.lead.productRow.field.list' => [
			'controller' => ProductRowField::class,
			'method' => 'list',
			'dtoFqcn' => CrmFieldMetadataDto::class,
			'entityTypeId' => OwnerType::LEAD,
			'queryParams' => ['dtoFqcn' => ProductRowDto::class],
		],
		'crm.lead.productRow.field.get' => [
			'controller' => ProductRowField::class,
			'method' => 'get',
			'dtoFqcn' => CrmFieldMetadataDto::class,
			'entityTypeId' => OwnerType::LEAD,
			'queryParams' => ['dtoFqcn' => ProductRowDto::class],
		],
		'crm.deal.productRow.field.list' => [
			'controller' => ProductRowField::class,
			'method' => 'list',
			'dtoFqcn' => CrmFieldMetadataDto::class,
			'entityTypeId' => OwnerType::DEAL,
			'queryParams' => ['dtoFqcn' => ProductRowDto::class],
		],
		'crm.deal.productRow.field.get' => [
			'controller' => ProductRowField::class,
			'method' => 'get',
			'dtoFqcn' => CrmFieldMetadataDto::class,
			'entityTypeId' => OwnerType::DEAL,
			'queryParams' => ['dtoFqcn' => ProductRowDto::class],
		],
		'crm.quote.productRow.field.list' => [
			'controller' => ProductRowField::class,
			'method' => 'list',
			'dtoFqcn' => CrmFieldMetadataDto::class,
			'entityTypeId' => OwnerType::QUOTE,
			'queryParams' => ['dtoFqcn' => ProductRowDto::class],
		],
		'crm.quote.productRow.field.get' => [
			'controller' => ProductRowField::class,
			'method' => 'get',
			'dtoFqcn' => CrmFieldMetadataDto::class,
			'entityTypeId' => OwnerType::QUOTE,
			'queryParams' => ['dtoFqcn' => ProductRowDto::class],
		],
		// The cards of the catalog are not addressed through an entity, so the route names no type and
		// carries no service parameter. The scopes are written out because the ones built from the
		// segments of the name would offer `crm.product` and `crm.product.list` as if the catalog were an
		// ordinary resource of the CRM; the method is an administrator's and answers to the scope of the
		// module alone.
		'crm.product.list' => [
			'controller' => Product::class,
			'method' => 'list',
			'dtoFqcn' => ProductDto::class,
			'scopes' => ['crm'],
		],
		// The requisites of the whole portal, so the route names no owner and carries no service
		// parameter. The scopes are written out for the same reason the catalog ones are: the ones built
		// from the segments of the name would offer `crm.requisite` and `crm.requisite.list`, and an
		// application holding either would look like a caller this administrative method accepts.
		'crm.requisite.list' => [
			'controller' => Requisite::class,
			'method' => 'list',
			'dtoFqcn' => RequisiteDto::class,
			'scopes' => ['crm'],
		],
	];

	/**
	 * The category family is not spelled out above: which entity types have it depends on the
	 * composition of the portal, so {@see RouteBuilder} builds those routes. Its gate is derived from
	 * the very names it returned - a route of the family that missed the map would be permanently
	 * open, since {@see Feature::enabled()} answers `true` for a route it knows nothing about.
	 *
	 * Building it is what walking the types of the portal costs, and every one of those routes is
	 * dropped again by {@see getAvailableRoutesConfig()} while the feature is off - so the gate is
	 * asked once before the walk instead. Nothing of the family reaches `routesConfig` then, which is
	 * the same schema the filter would have left behind.
	 */
	public function __construct()
	{
		if (!$this->isCategoryFamilyEnabled())
		{
			return;
		}

		$categoryRoutes = (new RouteBuilder())->buildRoutes();

		$this->routesConfig = array_merge($this->routesConfig, $categoryRoutes);
		foreach (array_keys($categoryRoutes) as $actionUri)
		{
			$this->featureByRoute[$actionUri] = RouteBuilder::FEATURE;
		}
	}

	public function getControllersData(): array
	{
		return $this->buildControllersDataFromRoutesConfig($this->getAvailableRoutesConfig());
	}

	public function getDataForDtoGeneration(): array
	{
		return [];
	}

	protected function getAvailableRoutesConfig(): array
	{
		$routesConfig = $this->routesConfig;
		$dynamicTypesMap = Container::getInstance()->getDynamicTypesMap()->load([
			'isLoadStages' => false,
			'isLoadCategories' => false,
		]);

		foreach ($dynamicTypesMap->getTypes() as $dynamicType)
		{
			$entityTypeId = $dynamicType->getEntityTypeId();
			if ($entityTypeId === null || !EntityType::isValid($entityTypeId))
			{
				continue;
			}

			foreach (['add', 'update', 'delete', 'get', 'list'] as $method)
			{
				$actionUri = 'crm.smartProcess' . $entityTypeId . '.' . $method;
				$routesConfig[$actionUri] = [
					'controller' => Item::class,
					'method' => $method,
					'dtoGenerator' => ItemDtoGenerator::class,
					'entityTypeId' => $entityTypeId,
				];
				$this->featureByRoute[$actionUri] = RestV3CrudDeal::class;
			}

			foreach (['list', 'get'] as $method)
			{
				$actionUri = 'crm.smartProcess' . $entityTypeId . '.field.' . $method;
				$routesConfig[$actionUri] = [
					'controller' => Field::class,
					'method' => $method,
					'dtoFqcn' => CrmFieldMetadataDto::class,
					'entityTypeId' => $entityTypeId,
				];
				$this->featureByRoute[$actionUri] = RestV3CrudDeal::class;
			}

			$actionUri = 'crm.smartProcess' . $entityTypeId . '.addFieldValue';
			$routesConfig[$actionUri] = [
				'controller' => Item::class,
				'method' => 'addFieldValue',
				'dtoGenerator' => ItemDtoGenerator::class,
				'entityTypeId' => $entityTypeId,
			];
			$this->featureByRoute[$actionUri] = RestV3CrudDeal::class;

			$actionUri = 'crm.smartProcess' . $entityTypeId . '.deleteFieldValue';
			$routesConfig[$actionUri] = [
				'controller' => Item::class,
				'method' => 'deleteFieldValue',
				'dtoGenerator' => ItemDtoGenerator::class,
				'entityTypeId' => $entityTypeId,
			];
			$this->featureByRoute[$actionUri] = RestV3CrudDeal::class;
		}

		$routesConfig = $this->addProductRowRoutesOfProductEnabledTypes($routesConfig);

		return array_filter(
			$routesConfig,
			fn(string $actionUri): bool => $this->isRouteAvailable($actionUri),
			ARRAY_FILTER_USE_KEY,
		);
	}

	protected function isCategoryFamilyEnabled(): bool
	{
		return Feature::enabled(RouteBuilder::FEATURE);
	}

	/**
	 * Routes of `crm.{entity}.productRow.*` for the smart-process-based types products are enabled for.
	 * Whether such a type works with products is a state of the portal, not a constant of the product, so
	 * routes declared literally would drift away from it; lead, deal and quote are literals for that
	 * very reason - their answer never changes.
	 *
	 * This provider is built without dependency injection, is called with no exception handling around it
	 * and is reachable before authorization, so a failed read of the types would break the lookup of a
	 * method for *every* module. It degrades to the routes declared literally instead.
	 *
	 * @param array<string, array> $routesConfig
	 * @return array<string, array>
	 */
	protected function addProductRowRoutesOfProductEnabledTypes(array $routesConfig): array
	{
		try
		{
			$entityTypeIds = $this->getProductEnabledTypeRepository()->getEntityTypeIds();
		}
		catch (\Throwable $exception)
		{
			Application::getInstance()->getExceptionHandler()->writeToLog($exception);

			return $routesConfig;
		}

		foreach ($entityTypeIds as $entityTypeId)
		{
			$entitySegment = $this->getProductRowEntitySegment($entityTypeId);
			if ($entitySegment === null)
			{
				continue;
			}

			foreach (static::PRODUCT_ROW_ACTIONS as $action)
			{
				$actionUri = 'crm.' . $entitySegment . '.productRow.' . $action;
				if (isset($routesConfig[$actionUri]))
				{
					continue;
				}

				$routesConfig[$actionUri] = [
					'controller' => ProductRow::class,
					'method' => $action,
					'dtoFqcn' => ProductRowDto::class,
					'entityTypeId' => $entityTypeId,
				];
				$this->featureByRoute[$actionUri] = RestV3CrudDeal::class;
			}

			$routesConfig = $this->addProductRowFieldRoutesOfType($routesConfig, $entitySegment, $entityTypeId);
		}

		return $routesConfig;
	}

	/**
	 * Routes of `crm.{entity}.productRow.field.*` of one type, next to the branch that gives the type its
	 * actions and under the same answer of the portal: a type whose actions are published gets the help on
	 * them, and hiding one without the other would publish a method describing an absent contract.
	 *
	 * The controller is another one - a help method is named after the group and served by a class of its
	 * own - and the DTO under description is a service parameter of the route, as it is in the routes
	 * written out literally.
	 *
	 * @param array<string, array> $routesConfig
	 * @return array<string, array>
	 */
	protected function addProductRowFieldRoutesOfType(
		array $routesConfig,
		string $entitySegment,
		int $entityTypeId,
	): array
	{
		foreach (static::PRODUCT_ROW_FIELD_ACTIONS as $action)
		{
			$actionUri = 'crm.' . $entitySegment . '.productRow.field.' . $action;
			if (isset($routesConfig[$actionUri]))
			{
				continue;
			}

			$routesConfig[$actionUri] = [
				'controller' => ProductRowField::class,
				'method' => $action,
				'dtoFqcn' => CrmFieldMetadataDto::class,
				'entityTypeId' => $entityTypeId,
				'queryParams' => ['dtoFqcn' => ProductRowDto::class],
			];
			$this->featureByRoute[$actionUri] = RestV3CrudDeal::class;
		}

		return $routesConfig;
	}

	/**
	 * The segment a type is named by in a method of the group, or null for a type the group publishes no
	 * routes for.
	 */
	protected function getProductRowEntitySegment(int $entityTypeId): ?string
	{
		return match (true)
		{
			$entityTypeId === OwnerType::SMART_INVOICE => 'smartInvoice',
			$entityTypeId === OwnerType::SMART_DOCUMENT => 'smartDocument',
			OwnerType::isPossibleSmartProcessTypeId($entityTypeId) => 'smartProcess' . $entityTypeId,
			default => null,
		};
	}

	/**
	 * Resolved through the locator so that a failing read can be substituted in a test: the degradation
	 * above is a requirement of the design and needs a way to be exercised.
	 */
	protected function getProductEnabledTypeRepository(): ProductEnabledTypeRepository
	{
		$serviceLocator = ServiceLocator::getInstance();

		return $serviceLocator->has(ProductEnabledTypeRepository::class)
			? $serviceLocator->get(ProductEnabledTypeRepository::class)
			: new ProductEnabledTypeRepository()
		;
	}

	protected function isRouteAvailable(string $actionUri): bool
	{
		$featureClass = $this->featureByRoute[$actionUri] ?? null;

		return $featureClass === null || Feature::enabled($featureClass);
	}

	protected function buildControllersDataFromRoutesConfig(array $routesConfig): array
	{
		$methodDescriptionsControllerMap = [];
		foreach ($routesConfig as $actionUri => $routeConfig)
		{
			if (!isset($routeConfig['controller'], $routeConfig['method']))
			{
				continue;
			}

			$controllerFqcn = $routeConfig['controller'];
			$generatedDto = $this->generateDtoFromRouteConfig($routeConfig);
			if ($generatedDto instanceof GeneratedDto)
			{
				$dtoFqcn = Generator::generateByDto($generatedDto);
			}
			else
			{
				$dtoFqcn = $routeConfig['dtoFqcn'] ?? null;
			}

			$methodDescriptionsControllerMap[$controllerFqcn][] = new MethodDescription(
				module: self::MODULE,
				controllerFqcn: $controllerFqcn,
				method: $routeConfig['method'],
				dtoFqcn: $dtoFqcn,
				scopes: $routeConfig['scopes'] ?? $this->buildScopes($actionUri),
				actionUri: $actionUri,
				queryParams: $this->buildQueryParamsFromRouteConfig($routeConfig),
			);

		}

		$controllersData = [];
		foreach ($methodDescriptionsControllerMap as $controllerFqcn => $methodDescriptions)
		{
			$controllersData[] = new ControllerData(
				module: self::MODULE,
				controllerFqcn: $controllerFqcn,
				methods: $methodDescriptions,
			);
		}

		return $controllersData;
	}

	protected function generateDtoFromRouteConfig(array $routeConfig): ?GeneratedDto
	{
		$isValidDtoGenerator =
			isset($routeConfig['dtoGenerator'])
			&& class_exists($routeConfig['dtoGenerator'])
			&& is_subclass_of($routeConfig['dtoGenerator'], DtoGeneratorInterface::class)
		;
		if (!$isValidDtoGenerator)
		{
			return null;
		}

		/** @var DtoGeneratorInterface $dtoGenerator */
		$dtoGenerator = new $routeConfig['dtoGenerator']();

		return $dtoGenerator->generate(...$this->buildDtoGeneratorArgsFromRouteConfig($routeConfig));
	}

	protected function buildDtoGeneratorArgsFromRouteConfig(array $routeConfig): array
	{
		$dtoGeneratorArgs = $routeConfig['dtoGeneratorArgs'] ?? [];
		if (isset($routeConfig['entityTypeId']) && !isset($dtoGeneratorArgs['entityTypeId']))
		{
			$dtoGeneratorArgs['entityTypeId'] = $routeConfig['entityTypeId'];
		}

		return $dtoGeneratorArgs;
	}

	protected function buildScopes(string $actionUri): array
	{
		$scopes = [];
		$parts = explode('.', $actionUri);
		$scope = '';
		foreach ($parts as $part)
		{
			if ($part === '')
			{
				continue;
			}

			$scope =
				$scope === ''
					? $part
					: $scope . '.' . $part
			;
			$scopes[] = $scope;
		}

		return $scopes;
	}

	protected function buildQueryParamsFromRouteConfig(array $routeConfig): ?array
	{
		$queryParams = $routeConfig['queryParams'] ?? [];
		if (isset($routeConfig['entityTypeId']))
		{
			$queryParams['entityTypeId'] = $routeConfig['entityTypeId'];
		}

		return $queryParams === [] ? null : $queryParams;
	}

}

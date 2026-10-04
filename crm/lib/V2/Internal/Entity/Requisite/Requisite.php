<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\Requisite;

use Bitrix\Main\Type\DateTime;

/**
 * A requisite of a CRM contact or company - the set of legal details a counterparty is billed and
 * contracted under, kept as a record of its own rather than as fields of its owner.
 *
 * One record carries the details of a single jurisdiction: the preset of the requisite decides which
 * of the fields below the interface shows and what it calls them, while the storage keeps a cell for
 * every jurisdiction the product knows. A field that means nothing to the preset of the record is
 * simply empty, so most of the fields of any single requisite are.
 *
 * Read-only by construction - {@see \Bitrix\Crm\V2\Internal\Repository\Requisite\RequisiteRepository}
 * is the only place that puts one together. A field the caller did not ask for is not read and is
 * `null`, which is indistinguishable from a cell the record keeps empty.
 *
 * The `FIELD_*` names are the vocabulary the repository, the request mapper and the published DTO
 * share: filters, ordering and the field set are all expressed in them, and none of them knows the
 * columns behind them.
 *
 * @internal
 */
final readonly class Requisite
{
	public const FIELD_ID = 'id';
	public const FIELD_OWNER_ID = 'ownerId';
	public const FIELD_OWNER_TYPE_ID = 'ownerTypeId';
	public const FIELD_PRESET_ID = 'presetId';
	public const FIELD_NAME = 'name';
	public const FIELD_ACTIVE = 'active';
	public const FIELD_XML_ID = 'xmlId';
	public const FIELD_ORIGINATOR_ID = 'originatorId';
	public const FIELD_SORT = 'sort';
	public const FIELD_CREATED_TIME = 'createdTime';
	public const FIELD_UPDATED_TIME = 'updatedTime';
	public const FIELD_CREATED_BY_ID = 'createdById';
	public const FIELD_UPDATED_BY_ID = 'updatedById';

	public const FIELD_FULL_NAME = 'fullName';
	public const FIELD_FIRST_NAME = 'firstName';
	public const FIELD_LAST_NAME = 'lastName';
	public const FIELD_SECOND_NAME = 'secondName';
	public const FIELD_COMPANY_TITLE = 'companyTitle';
	public const FIELD_COMPANY_FULL_NAME = 'companyFullName';
	public const FIELD_COMPANY_IDENTIFIER = 'companyIdentifier';
	public const FIELD_COMPANY_REGISTRATION_DATE = 'companyRegistrationDate';
	public const FIELD_DIRECTOR_NAME = 'directorName';
	public const FIELD_ACCOUNTANT_NAME = 'accountantName';
	public const FIELD_CEO_NAME = 'ceoName';
	public const FIELD_CEO_POSITION = 'ceoPosition';
	public const FIELD_CONTACT_PERSON = 'contactPerson';

	public const FIELD_EMAIL_ADDRESS = 'emailAddress';
	public const FIELD_PHONE_NUMBER = 'phoneNumber';
	public const FIELD_FAX_NUMBER = 'faxNumber';

	public const FIELD_IDENTIFICATION_TYPE_ID = 'identificationTypeId';
	public const FIELD_IDENTITY_DOCUMENT_TYPE = 'identityDocumentType';
	public const FIELD_IDENTITY_DOCUMENT_SERIES = 'identityDocumentSeries';
	public const FIELD_IDENTITY_DOCUMENT_NUMBER = 'identityDocumentNumber';
	public const FIELD_IDENTITY_DOCUMENT_PERSONAL_NUMBER = 'identityDocumentPersonalNumber';
	public const FIELD_IDENTITY_DOCUMENT_ISSUE_DATE = 'identityDocumentIssueDate';
	public const FIELD_IDENTITY_DOCUMENT_ISSUED_BY = 'identityDocumentIssuedBy';
	public const FIELD_IDENTITY_DOCUMENT_DEPARTMENT_CODE = 'identityDocumentDepartmentCode';

	public const FIELD_INN = 'inn';
	public const FIELD_KPP = 'kpp';
	public const FIELD_USRLE = 'usrle';
	public const FIELD_IFNS = 'ifns';
	public const FIELD_OGRN = 'ogrn';
	public const FIELD_OGRNIP = 'ogrnip';
	public const FIELD_OKPO = 'okpo';
	public const FIELD_OKTMO = 'oktmo';
	public const FIELD_OKVED = 'okved';
	public const FIELD_EDRPOU = 'edrpou';
	public const FIELD_DRFO = 'drfo';
	public const FIELD_KBE = 'kbe';
	public const FIELD_IIN = 'iin';
	public const FIELD_BIN = 'bin';
	public const FIELD_REGON = 'regon';
	public const FIELD_KRS = 'krs';
	public const FIELD_PESEL = 'pesel';
	public const FIELD_SIRET = 'siret';
	public const FIELD_SIREN = 'siren';
	public const FIELD_RCS = 'rcs';
	public const FIELD_CNPJ = 'cnpj';
	public const FIELD_CPF = 'cpf';
	public const FIELD_STATE_REGISTRATION = 'stateRegistration';
	public const FIELD_MUNICIPAL_REGISTRATION = 'municipalRegistration';
	public const FIELD_LEGAL_FORM = 'legalForm';
	public const FIELD_SHARE_CAPITAL = 'shareCapital';
	public const FIELD_TAX_REGIME_ID = 'taxRegimeId';
	public const FIELD_RESIDENCE_COUNTRY = 'residenceCountry';
	public const FIELD_BASIS_DOCUMENT = 'basisDocument';

	public const FIELD_REGISTRATION_CERTIFICATE_SERIES = 'registrationCertificateSeries';
	public const FIELD_REGISTRATION_CERTIFICATE_NUMBER = 'registrationCertificateNumber';
	public const FIELD_REGISTRATION_CERTIFICATE_DATE = 'registrationCertificateDate';
	public const FIELD_VAT_PAYER = 'vatPayer';
	public const FIELD_VAT_NUMBER = 'vatNumber';
	public const FIELD_VAT_CERTIFICATE_SERIES = 'vatCertificateSeries';
	public const FIELD_VAT_CERTIFICATE_NUMBER = 'vatCertificateNumber';
	public const FIELD_VAT_CERTIFICATE_DATE = 'vatCertificateDate';

	/**
	 * The identifier is the only field that is always read: the page of a read is keyed by it. Every
	 * other field is `null` when it was left out of the field set, which is indistinguishable from a
	 * cell the record keeps empty - the caller asked not to know.
	 *
	 * @param int|null $ownerTypeId type of the owner of the record; only a contact or a company, as the
	 *        read that assembles a requisite answers for no other owner.
	 * @param string|null $companyIdentifier a free-form identifier of the organization, not a reference
	 *        to a company of the CRM.
	 * @param string|null $companyRegistrationDate a date the storage keeps as a free-form string rather
	 *        than as a moment in time, as `identityDocumentIssueDate`, `registrationCertificateDate` and
	 *        `vatCertificateDate` below are too.
	 */
	public function __construct(
		public int $id,
		public ?int $ownerId,
		public ?int $ownerTypeId,
		public ?int $presetId,
		public ?string $name,
		public ?bool $active,
		public ?string $xmlId,
		public ?string $originatorId,
		public ?int $sort,
		public ?DateTime $createdTime,
		public ?DateTime $updatedTime,
		public ?int $createdById,
		public ?int $updatedById,
		public ?string $fullName,
		public ?string $firstName,
		public ?string $lastName,
		public ?string $secondName,
		public ?string $companyTitle,
		public ?string $companyFullName,
		public ?string $companyIdentifier,
		public ?string $companyRegistrationDate,
		public ?string $directorName,
		public ?string $accountantName,
		public ?string $ceoName,
		public ?string $ceoPosition,
		public ?string $contactPerson,
		public ?string $emailAddress,
		public ?string $phoneNumber,
		public ?string $faxNumber,
		public ?string $identificationTypeId,
		public ?string $identityDocumentType,
		public ?string $identityDocumentSeries,
		public ?string $identityDocumentNumber,
		public ?string $identityDocumentPersonalNumber,
		public ?string $identityDocumentIssueDate,
		public ?string $identityDocumentIssuedBy,
		public ?string $identityDocumentDepartmentCode,
		public ?string $inn,
		public ?string $kpp,
		public ?string $usrle,
		public ?string $ifns,
		public ?string $ogrn,
		public ?string $ogrnip,
		public ?string $okpo,
		public ?string $oktmo,
		public ?string $okved,
		public ?string $edrpou,
		public ?string $drfo,
		public ?string $kbe,
		public ?string $iin,
		public ?string $bin,
		public ?string $regon,
		public ?string $krs,
		public ?string $pesel,
		public ?string $siret,
		public ?string $siren,
		public ?string $rcs,
		public ?string $cnpj,
		public ?string $cpf,
		public ?string $stateRegistration,
		public ?string $municipalRegistration,
		public ?string $legalForm,
		public ?string $shareCapital,
		public ?string $taxRegimeId,
		public ?string $residenceCountry,
		public ?string $basisDocument,
		public ?string $registrationCertificateSeries,
		public ?string $registrationCertificateNumber,
		public ?string $registrationCertificateDate,
		public ?bool $vatPayer,
		public ?string $vatNumber,
		public ?string $vatCertificateSeries,
		public ?string $vatCertificateNumber,
		public ?string $vatCertificateDate,
	)
	{
	}
}

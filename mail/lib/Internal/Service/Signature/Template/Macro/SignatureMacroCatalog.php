<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\Signature\Template\Macro;

final class SignatureMacroCatalog
{
	public const VERSION = 1;

	private const GROUPS = [
		[
			'id' => 'employee',
			'items' => [
				[
					'id' => 'employee.name',
					'name' => 'NAME',
					'wireValue' => 'имя',
					'labelKey' => 'MAIL_SIGNATURE_MACRO_NAME',
				],
				[
					'id' => 'employee.lastName',
					'name' => 'LAST_NAME',
					'wireValue' => 'фамилия',
					'labelKey' => 'MAIL_SIGNATURE_MACRO_LAST_NAME',
				],
				[
					'id' => 'employee.secondName',
					'name' => 'SECOND_NAME',
					'wireValue' => 'отчество',
					'labelKey' => 'MAIL_SIGNATURE_MACRO_SECOND_NAME',
				],
				[
					'id' => 'employee.fullName',
					'name' => 'FULL_NAME',
					'wireValue' => 'фио',
					'labelKey' => 'MAIL_SIGNATURE_MACRO_FULL_NAME',
				],
				[
					'id' => 'employee.firstLastName',
					'name' => 'FIRST_LAST_NAME',
					'wireValue' => 'имя_фамилия',
					'labelKey' => 'MAIL_SIGNATURE_MACRO_FIRST_LAST_NAME',
				],
				[
					'id' => 'employee.position',
					'name' => 'WORK_POSITION',
					'wireValue' => 'должность',
					'labelKey' => 'MAIL_SIGNATURE_MACRO_POSITION',
				],
				[
					'id' => 'employee.department',
					'name' => 'DEPARTMENT',
					'wireValue' => 'отдел',
					'labelKey' => 'MAIL_SIGNATURE_MACRO_DEPARTMENT',
				],
				[
					'id' => 'employee.workPhone',
					'name' => 'WORK_PHONE',
					'wireValue' => 'рабочий_телефон',
					'labelKey' => 'MAIL_SIGNATURE_MACRO_WORK_PHONE',
				],
				[
					'id' => 'employee.mobilePhone',
					'name' => 'PERSONAL_MOBILE',
					'wireValue' => 'мобильный_телефон',
					'labelKey' => 'MAIL_SIGNATURE_MACRO_MOBILE_PHONE',
				],
				[
					'id' => 'employee.email',
					'name' => 'EMAIL',
					'wireValue' => 'email',
					'labelKey' => 'MAIL_SIGNATURE_MACRO_EMAIL',
				],
				[
					'id' => 'employee.website',
					'name' => 'PERSONAL_WWW',
					'wireValue' => 'сайт',
					'labelKey' => 'MAIL_SIGNATURE_MACRO_WEBSITE',
				],
			],
		],
		[
			'id' => 'company',
			'items' => [
				[
					'id' => 'company.name',
					'name' => 'TITLE',
					'wireValue' => 'компания',
					'labelKey' => 'MAIL_SIGNATURE_MACRO_COMPANY',
				],
				[
					'id' => 'company.legalAddress',
					'name' => 'REGISTERED_ADDRESS',
					'wireValue' => 'юр_адрес',
					'labelKey' => 'MAIL_SIGNATURE_MACRO_LEGAL_ADDRESS',
				],
				[
					'id' => 'company.actualAddress',
					'name' => 'PRIMARY_ADDRESS',
					'wireValue' => 'физ_адрес',
					'labelKey' => 'MAIL_SIGNATURE_MACRO_ACTUAL_ADDRESS',
				],
			],
		],
	];

	public function getGroups(): array
	{
		return self::GROUPS;
	}

	public function getDto(): array
	{
		return [
			'version' => self::VERSION,
			'groups' => array_map(
				static fn(array $group): array => [
					'id' => $group['id'],
					'items' => array_map(
						static fn(array $item): array => [
							'id' => $item['id'],
							'token' => self::makeToken($item['wireValue']),
							'labelKey' => $item['labelKey'],
						],
						$group['items'],
					),
				],
				self::GROUPS,
			),
		];
	}

	public function getTokenById(string $id): ?string
	{
		foreach (self::GROUPS as $group)
		{
			foreach ($group['items'] as $item)
			{
				if ($item['id'] === $id)
				{
					return self::makeToken($item['wireValue']);
				}
			}
		}

		return null;
	}

	/** @return array<string, string> */
	public function getTokensById(): array
	{
		$tokens = [];
		foreach (self::GROUPS as $group)
		{
			foreach ($group['items'] as $item)
			{
				$tokens[$item['id']] = self::makeToken($item['wireValue']);
			}
		}

		return $tokens;
	}

	private static function makeToken(string $wireValue): string
	{
		return '{{' . $wireValue . '}}';
	}
}

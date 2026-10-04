<?php

namespace Bitrix\Main\Mail\Internal;

use Bitrix\Main\Config;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Mail\Address;
use Bitrix\Main\Security;
use Bitrix\Main\ORM\Fields;
use Bitrix\Main\ORM\Data\DataManager;

/**
 * Class SenderTable
 * @package Bitrix\Main\Mail\Internal
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_Sender_Query query()
 * @method static EO_Sender_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_Sender_Result getById($id)
 * @method static EO_Sender_Result getList(array $parameters = [])
 * @method static EO_Sender_Entity getEntity()
 * @method static \Bitrix\Main\Mail\Internal\Sender createObject($setDefaultValues = true)
 * @method static \Bitrix\Main\Mail\Internal\EO_Sender_Collection createCollection()
 * @method static \Bitrix\Main\Mail\Internal\Sender wakeUpObject($row)
 * @method static \Bitrix\Main\Mail\Internal\EO_Sender_Collection wakeUpCollection($rows)
 */
class SenderTable extends DataManager
{
	public static function getTableName()
	{
		return 'b_main_mail_sender';
	}

	public static function add(array $data)
	{
		$result = parent::add($data);

		if ($result->isSuccess())
		{
			$senderId = (int)$result->getId();
			\Bitrix\Main\Mail\Sender::clearSenderCache($senderId, $data['EMAIL'] ?? null);
			\Bitrix\Main\Mail\Sender::clearIdentitySenderCache(
				$senderId,
				$data['PARENT_MODULE_ID'] ?? 'main',
				isset($data['PARENT_ID']) ? (int)$data['PARENT_ID'] : null,
			);
		}

		return $result;
	}

	public static function update($primary, array $data)
	{
		$current = static::getCurrentRow($primary);

		$result = parent::update($primary, $data);

		if ($result->isSuccess())
		{
			// the address of the record may change, so both the old and the new one lose their fallback cache
			\Bitrix\Main\Mail\Sender::clearSenderCache((int)$current['ID'], $current['EMAIL']);
			\Bitrix\Main\Mail\Sender::clearSenderCache((int)$current['ID'], $data['EMAIL'] ?? null);
			\Bitrix\Main\Mail\Sender::clearIdentitySenderCache(
				(int)$current['ID'],
				$current['PARENT_MODULE_ID'],
				$current['PARENT_ID'],
			);
			\Bitrix\Main\Mail\Sender::clearIdentitySenderCache(
				(int)$current['ID'],
				$data['PARENT_MODULE_ID'] ?? $current['PARENT_MODULE_ID'],
				array_key_exists('PARENT_ID', $data) ? (int)$data['PARENT_ID'] : $current['PARENT_ID'],
			);
		}

		return $result;
	}

	public static function delete($primary)
	{
		$current = static::getCurrentRow($primary);

		$result = parent::delete($primary);

		if ($result->isSuccess())
		{
			\Bitrix\Main\Mail\Sender::clearSenderCache((int)$current['ID'], $current['EMAIL']);
			\Bitrix\Main\Mail\Sender::clearIdentitySenderCache(
				(int)$current['ID'],
				$current['PARENT_MODULE_ID'],
				$current['PARENT_ID'],
			);
		}

		return $result;
	}

	private static function getCurrentRow($primary): array
	{
		$row = static::getByPrimary(
			$primary,
			['select' => ['ID', 'EMAIL', 'PARENT_MODULE_ID', 'PARENT_ID']],
		)->fetch();

		return [
			'ID' => $row['ID'] ?? 0,
			'EMAIL' => $row['EMAIL'] ?? null,
			'PARENT_MODULE_ID' => $row['PARENT_MODULE_ID'] ?? null,
			'PARENT_ID' => isset($row['PARENT_ID']) ? (int)$row['PARENT_ID'] : null,
		];
	}

	public static function getObjectClass()
	{
		return Sender::class;
	}

	public static function getMap()
	{
		return [
			(new Fields\IntegerField("ID"))
				->configurePrimary(true)
				->configureAutocomplete(true)
				->configureTitle(Loc::getMessage("main_mail_sender_id_title")),

			(new Fields\StringField("EMAIL"))
				->configureRequired(true)
				->configureTitle(Loc::getMessage("main_mail_sender_email_title"))
				// the form of the stored address is a property of the field, so every write path of the
				// entity passes through it: add, addMulti, update and updateMulti alike
				->addSaveDataModifier(static function ($value)
					{
						return is_string($value) ? Address::normalizeEmail($value) : $value;
					}),

			(new Fields\StringField("NAME"))
				->configureTitle(Loc::getMessage("main_mail_sender_name_title")),

			(new Fields\IntegerField("USER_ID"))
				->configureAutocomplete(true)
				->configureTitle(Loc::getMessage("main_mail_sender_user_id_title")),

			(new Fields\BooleanField("IS_CONFIRMED"))
				->configureStorageValues("0", "1")
				->configureDefaultValue("0")
				->configureTitle(Loc::getMessage("main_mail_sender_is_confirmed_title")),

			(new Fields\BooleanField("IS_PUBLIC"))
				->configureStorageValues("0", "1")
				->configureDefaultValue("0")
				->configureTitle(Loc::getMessage("main_mail_sender_is_public_title")),

			(new Fields\ArrayField("OPTIONS"))
				->configureSerializationPhp()
				->configureRequired(false)
				->configureTitle(Loc::getMessage("main_mail_sender_options_title"))
				->addSaveDataModifier(function($value)
					{
						$value = unserialize($value, ['allowed_classes' => false]);
						if (!empty($value['smtp']['password']))
						{
							$value['smtp']['encrypted'] = false;

							$cryptoOptions = Config\Configuration::getValue('crypto');
							if (!empty($cryptoOptions['crypto_key']))
							{
								try
								{
									$cipher = new Security\Cipher();

									$value['smtp']['password'] = $cipher->encrypt(
										$value['smtp']['password'],
										$cryptoOptions['crypto_key']
									);
									$value['smtp']['encrypted'] = true;
								}
								catch (Security\SecurityException $e)
								{
								}
							}

							$value['smtp']['password'] = base64_encode($value['smtp']['password']);
						}

						return serialize($value);
					})
				->addFetchDataModifier(function($value)
				{
					if (!empty($value['smtp']['password']))
					{
						$value['smtp']['password'] = base64_decode($value['smtp']['password']);

						if (!empty($value['smtp']['encrypted']))
						{
							$cryptoOptions = Config\Configuration::getValue('crypto');
							if (!empty($cryptoOptions['crypto_key']))
							{
								try
								{
									$cipher = new Security\Cipher();

									$value['smtp']['password'] = $cipher->decrypt(
										$value['smtp']['password'],
										$cryptoOptions['crypto_key']
									);
									unset($value['smtp']['encrypted']);
								} catch (Security\SecurityException $e)
								{
								}
							}
						}
					}

					if (!empty($value['smtp']) && is_array($value['smtp']))
					{
						if (empty($value['smtp']['protocol']))
						{
							if (465 == $value['smtp']['port'])
							{
								$value['smtp']['protocol'] = 'smtps';
							} else if (587 == $value['smtp']['port'])
							{
								$value['smtp']['protocol'] = 'smtp';
							}
						}
					}

					return $value;
				}),
			(new Fields\StringField('PARENT_MODULE_ID'))
				->configureDefaultValue('main')
				->configureSize(50),
			(new Fields\IntegerField('PARENT_ID'))
				->configureNullable(),
		];
	}
}

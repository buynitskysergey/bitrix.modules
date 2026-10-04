<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\AI\Agent\Generator\AgentConfig;

final class ConstantConfig
{
	/** Closed list of constant-level keys (TPL-05). */
	private const KEYS = [
		'label',
		'description',
		'type',
		'multiple',
		'required',
		'default',
		'options',
		'settings',
		'show_in_wizard',
		'wizard_title',
		'wizard_description',
		'wizard_required',
		'wizard_default',
	];

	/** The only alias of the format: 'showInWizard' is the older spelling of 'show_in_wizard'. */
	private const KEY_ALIASES = ['showInWizard'];

	/** Keys of a constant the format states the shape of, and what each is written as: see assertValueShapes(). */
	private const VALUE_SHAPES = [
		'options' => 'a map of the value of an option to the lang key of its label',
		'settings' => 'a map of the settings of the field type',
		'label' => 'a string, the lang key of the name of the constant',
		'type' => 'a string, the type of the field the constant holds',
		'description' => 'a string, the lang key of the hint',
		'wizard_title' => 'a string, the lang key of the title of the block of the wizard',
		'wizard_description' => 'a string, the lang key of the description of that block',
		'default' => 'a string, an array or null - the value as the template carries it',
		'multiple' => 'a flag - whether the constant holds several values',
		'required' => 'a flag - whether the constant has to be filled in',
		'show_in_wizard' => 'a flag - whether the wizard asks for the constant',
		'showInWizard' => 'a flag - whether the wizard asks for the constant',
		'wizard_required' => 'a flag - whether the wizard requires its element to be filled in',
		'wizard_default' => 'a string, an array or null - the value as the element of the wizard carries it',
	];

	/** Keys of a constant written as a lang key or another string. */
	private const STRING_KEYS = ['label', 'type', 'description', 'wizard_title', 'wizard_description'];

	/** Keys of a constant written as a flag, the alias of 'show_in_wizard' among them. */
	private const FLAG_KEYS = ['multiple', 'required', 'show_in_wizard', 'showInWizard', 'wizard_required'];

	/** Keys the format states as the value the template carries: a string, an array or an explicit null. */
	private const TEMPLATE_VALUE_KEYS = ['default', 'wizard_default'];

	public function __construct(
		public readonly string $key,
		public readonly string $label,
		public readonly string $type,
		public readonly bool $multiple = false,
		public readonly bool $required = false,
		/**
		 * Default value of the constant as the template carries it: a string, a list or a map of them, or null.
		 * A constant the source states no default for gets an empty string, the way the build has always written
		 * it - an explicit null is a value of its own and reaches the template as null. A number and a flag are
		 * refused rather than carried: the wizard of the setup activity states its own default as a string or an
		 * array too (\Bitrix\Bizproc\Internal\Entity\Activity\SetupTemplateActivity\Constant), so a scalar of
		 * another type has nowhere to go.
		 */
		public readonly string|array|null $default = '',
		/**
		 * Options of the constant, the value of an option mapped to the lang key of its label. No key at all
		 * writes 'Options' as null, the way the build always did; an empty map writes an empty one - five of the
		 * shipped agents carry exactly that, and the difference stands in their bytes.
		 */
		public readonly ?array $options = null,
		public readonly bool $showInWizard = true,
		public readonly ?string $wizardTitle = null,
		public readonly ?string $wizardDescription = null,
		/** Lang key of the hint the constant carries beside its label; no key at all means no hint. */
		public readonly ?string $description = null,
		/**
		 * Settings of the field type - the selector of an entity, for one - written into the template as they
		 * are. No key at all leaves the template without a 'Settings' key; an empty map writes an empty one,
		 * the way 'bitrix_ai_open_lines_operator' carries it.
		 */
		public readonly ?array $settings = null,
		/**
		 * Whether the element of the wizard is required, where the wizard asks for it otherwise than the constant
		 * of the template does. No key at all - null here - means the element states what the constant states, so
		 * a source written before this key builds the wizard exactly as it did.
		 */
		public readonly ?bool $wizardRequired = null,
		/**
		 * Whether the source states a default for the element of the wizard at all. Kept apart from the value
		 * because null is a value here the way it is for 'default': no key means the element of the wizard shows
		 * the default of the constant.
		 */
		public readonly bool $hasWizardDefault = false,
		/** Default of the element of the wizard, stated only where it differs from the one of the constant. */
		public readonly string|array|null $wizardDefault = null,
	) {}

	/**
	 * Whether the element of the wizard is required: what the wizard states about it, and what the constant
	 * states where the wizard states nothing. The two live in different places of the template - the element in
	 * the 'blocks' of the setup activity, the constant in 'CONSTANTS' - and six of the shipped agents ask for a
	 * field in the wizard the constant calls optional, 19 fields in all.
	 */
	public function isRequiredInWizard(): bool
	{
		return $this->wizardRequired ?? $this->required;
	}

	/**
	 * Default of the element of the wizard: the one stated for it, the one of the constant otherwise. An element
	 * carries no null - \Bitrix\Bizproc\Internal\Entity\Activity\SetupTemplateActivity\Constant states its
	 * default as a string or an array - so a null of either level reaches the wizard as an empty value.
	 */
	public function getDefaultInWizard(): string|array
	{
		return ($this->hasWizardDefault ? $this->wizardDefault : $this->default) ?? '';
	}

	public static function fromArray(string $key, array $data): self
	{
		AgentConfig::assertKnownKeys("Constant '$key'", $data, [...self::KEYS, ...self::KEY_ALIASES]);

		if (empty($data['label']) || empty($data['type']))
		{
			throw new \InvalidArgumentException("Constant '{$key}' must have 'label' and 'type'");
		}

		self::assertValueShapes($key, $data);

		return new self(
			key: $key,
			label: $data['label'],
			type: $data['type'],
			multiple: $data['multiple'] ?? false,
			required: $data['required'] ?? false,
			default: array_key_exists('default', $data) ? $data['default'] : '',
			options: array_key_exists('options', $data) ? $data['options'] : null,
			showInWizard: $data['show_in_wizard'] ?? $data['showInWizard'] ?? true,
			wizardTitle: $data['wizard_title'] ?? null,
			wizardDescription: $data['wizard_description'] ?? null,
			description: $data['description'] ?? null,
			settings: array_key_exists('settings', $data) ? $data['settings'] : null,
			wizardRequired: $data['wizard_required'] ?? null,
			hasWizardDefault: array_key_exists('wizard_default', $data),
			wizardDefault: $data['wizard_default'] ?? null,
		);
	}

	/**
	 * Refuses a key of a constant written as something the format never writes it as. Such a value used to reach
	 * the constructor and be answered by a TypeError about its arguments instead of by the format - the same
	 * silence the closed list of keys removed. An explicit null is refused with the rest: the format tells the
	 * absence of a key from a value, so null is a value here and not a way of saying nothing.
	 *
	 * Every key of the level is asked about, the flags and the lang keys included: a flag written as a string
	 * reached the constructor and answered with a TypeError, and a flag written as null was read as the key
	 * standing nowhere - 'multiple' became false, and 'show_in_wizard' fell through to its alias and became true.
	 *
	 * @param array<string, mixed> $data
	 */
	private static function assertValueShapes(string $key, array $data): void
	{
		foreach (['options', 'settings'] as $mapKey)
		{
			if (array_key_exists($mapKey, $data) && !is_array($data[$mapKey]))
			{
				self::refuse($key, $mapKey, $data[$mapKey]);
			}
		}

		foreach (self::STRING_KEYS as $stringKey)
		{
			if (array_key_exists($stringKey, $data) && !is_string($data[$stringKey]))
			{
				self::refuse($key, $stringKey, $data[$stringKey]);
			}
		}

		foreach (self::FLAG_KEYS as $flagKey)
		{
			if (array_key_exists($flagKey, $data) && !is_bool($data[$flagKey]))
			{
				self::refuse($key, $flagKey, $data[$flagKey]);
			}
		}

		foreach (self::TEMPLATE_VALUE_KEYS as $valueKey)
		{
			if (
				array_key_exists($valueKey, $data)
				&& $data[$valueKey] !== null
				&& !is_string($data[$valueKey])
				&& !is_array($data[$valueKey])
			)
			{
				self::refuse($key, $valueKey, $data[$valueKey]);
			}
		}
	}

	private static function refuse(string $key, string $valueKey, mixed $value): never
	{
		throw new \InvalidArgumentException(sprintf(
			"Constant '%s': '%s' must be %s, got %s",
			$key,
			$valueKey,
			self::VALUE_SHAPES[$valueKey],
			get_debug_type($value),
		));
	}
}

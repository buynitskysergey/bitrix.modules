<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\App;

use Bitrix\Main\Config\Feature;
use Bitrix\Note\Internal\Config\Feature\DocumentNotifications;
use Bitrix\Note\Internal\Config\Feature\ExternalAccess;
use Bitrix\Note\Internal\Config\Feature\VideoEmbedLink;
use Bitrix\Note\Internal\Configuration;
use Bitrix\Note\Internal\Integration\AiAssistant\AiChatAvailability;
use Bitrix\Note\Internal\Integration\AiAssistant\EditorCopilotAvailability;

/**
 * Builds the mount payload of the knowledge-base application for both contours: the workspace one
 * (bitrix:note.editor) and the public one behind an external-access link (bitrix:note.public).
 *
 * The rule that keeps the contours from drifting apart: everything shared is assembled here, and a
 * component only resolves what is genuinely its own - the tariff verdict, the ACL check, the
 * CUserOptions preferences, the welcome document, the link scope. A new mount parameter added here
 * therefore reaches both contours by itself; a parameter that would need a "workspace only" branch
 * belongs to the component instead.
 */
final class AppBootstrapService
{
	private const SIDEBAR_DEFAULT_WIDTH = 280;
	private const SIDEBAR_MIN_WIDTH = 220;
	private const SIDEBAR_MAX_WIDTH = 540;

	private const THEME_LIGHT = 'light';
	private const THEME_DARK = 'dark';

	private AiChatAvailability $aiChatAvailability;
	private EditorCopilotAvailability $editorCopilotAvailability;

	public function __construct(
		?AiChatAvailability $aiChatAvailability = null,
		?EditorCopilotAvailability $editorCopilotAvailability = null,
	)
	{
		$this->aiChatAvailability = $aiChatAvailability ?? new AiChatAvailability();
		$this->editorCopilotAvailability = $editorCopilotAvailability ?? new EditorCopilotAvailability();
	}

	/**
	 * @return array Component arResult; the template turns it into the app.mount() argument.
	 */
	public function build(AppBootstrapContext $context): array
	{
		$isMobile = $this->isMobileHit();

		$result = [
			'ROUTER_BASE' => $context->routerBase,
			'IS_PUBLIC_MODE' => $context->mode->isPublic(),
			'DOCUMENT_ID' => $context->documentId,
			'DIRECT_LINK' => $context->isDirectLink ? 'Y' : 'N',
			'INITIAL_COLLECTIONS' => $context->initialCollections,
			'INITIAL_SIDEBAR_CONTEXT' => $context->initialSidebarContext,
			'SIDEBAR_OPTIONS' => $this->resolveSidebarOptions($context->rawSidebarOptions, $isMobile),
			'IS_MOBILE' => $isMobile,
			'THEME' => $this->resolveTheme($context->rawTheme),
			// Non-null when the tariff/tool blocks access: the template opens the corresponding
			// slider on top of the mounted interface.
			'TARIFF_SLIDER_CODE' => $context->tariffSliderCode,
			'DISK_ATTACHMENTS_ENABLED' => $this->isDiskAttachmentsEnabled($context),
		];

		if ($context->mode->isPublic())
		{
			// Ground for the password prompt drawn on top of the skeleton.
			$result['PASSWORD_REQUIRED'] = $context->passwordRequired;
			// The visitor has no personal channel, so the channel of the link travels with the page.
			$result['PULL_CONFIG'] = $context->pullConfig;

			return $result;
		}

		// Personal and portal-wide surfaces of the workspace contour. A visitor gets none of them:
		// there is no account to own favorites, history or the chat, and the server-side attachment
		// package makes the rest unnecessary.
		$aiChatEnabled = !$context->isTariffBlocked() && $this->aiChatAvailability->isAvailable();
		$aiEditorEnabled = !$context->isTariffBlocked()
			&& !$isMobile
			&& $this->editorCopilotAvailability->isTextAvailable()
		;

		// The width of the document body is a personal preference stored in CUserOptions, and saving it
		// back needs an authorized session - a visitor has no account to write it against.
		$result['DOCUMENT_FULL_WIDTH'] = $context->rawDocumentFullWidth === 'Y';
		$result['INITIAL_FAVORITES'] = $context->initialFavorites;
		// DTO-02, resolved in the same server pass as the first page above: whether the user follows
		// anything at all. Without it the sidebar block hides itself with the favorites empty and the
		// notifications mode is out of reach.
		$result['HAS_SUBSCRIPTIONS'] = $context->hasSubscriptions;
		$result['INITIAL_WELCOME_DOC_ID'] = $context->welcomeDocumentId;
		// Hides every subscription surface at once - the bells and the "only notified" filter.
		// Delivery is stopped by the same feature in the drainer, not from here.
		$result['NOTIFICATIONS_ENABLED'] = Feature::isEnabled(DocumentNotifications::class);
		// Hides the "External access" item of the knowledge-base actions menu, and with it the only
		// way into the settings window. A visitor never sees the menu, hence the workspace-only place.
		$result['EXTERNAL_ACCESS_ENABLED'] = Feature::isEnabled(ExternalAccess::class);
		// Gates the three ways to turn a link into a player: the toolbar item, the paste of a bare
		// link and the source panel of a standing player. Players already in a document keep
		// rendering, and markdown keeps building them. The visitor gets no key:
		// every one of the three needs an editable document.
		$result['VIDEO_EMBED_LINK_ENABLED'] = Feature::isEnabled(VideoEmbedLink::class);
		// Both keys stay empty unless the chat is actually reachable, so the front end
		// cannot tell "flag off" from "conditions did not hold" - and must not try to.
		$result['AI_CHAT_ENABLED'] = $aiChatEnabled;
		$result['AI_CHAT_NAME'] = $aiChatEnabled ? $this->aiChatAvailability->getName() : '';
		// The editor verdict is independent of the chat verdict. Every reason for refusal remains on
		// the server, so the client only receives an enabled flag and the data needed by the adapters.
		$result['AI_EDITOR_ENABLED'] = $aiEditorEnabled;
		$result['AI_EDITOR_IMAGE_ENABLED'] = $aiEditorEnabled
			&& $this->editorCopilotAvailability->isImageAvailable()
		;
		$result['AI_EDITOR_NAME'] = $aiEditorEnabled ? $this->editorCopilotAvailability->getName() : '';
		$result['AI_EDITOR_CATEGORY'] = $aiEditorEnabled
			? $this->editorCopilotAvailability->getEditPromptCategory()
			: ''
		;
		$result['AI_EDITOR_READONLY_CATEGORY'] = $aiEditorEnabled
			? $this->editorCopilotAvailability->getReadonlyPromptCategory()
			: ''
		;

		return $result;
	}

	/**
	 * Gates the "pick from Disk" entry point in the workspace contour. Documents that already carry
	 * Disk attachments keep rendering them with the flag off - see Configuration.
	 *
	 * Which of the two ways to pick is offered is the page's own answer, not this one: Disk's popup
	 * has a hard 980x538 minimum and is shown only where it fits, and inside the native shell the
	 * app's own file list takes its place. Deciding it here is not possible anyway - a page reloaded
	 * inside the shell arrives without the mobile prefix the entry point stripped, and this hit stops
	 * looking mobile while the screen is the same phone.
	 *
	 * The public contour reads existing attachments and never picks new ones, so the option does not
	 * apply to it.
	 */
	private function isDiskAttachmentsEnabled(AppBootstrapContext $context): bool
	{
		if ($context->mode->isPublic())
		{
			return true;
		}

		return Configuration::isDiskAttachmentsEnabled();
	}

	private function resolveTheme(mixed $rawTheme): string
	{
		$value = is_string($rawTheme) ? strtolower($rawTheme) : self::THEME_LIGHT;

		return $value === self::THEME_DARK ? self::THEME_DARK : self::THEME_LIGHT;
	}

	private function resolveSidebarOptions(mixed $rawOptions, bool $isMobile): array
	{
		$options = is_array($rawOptions) ? $rawOptions : [];
		$rawWidth = $options['width'] ?? null;
		$rawCollapsed = $options['collapsed'] ?? null;
		$width = is_numeric($rawWidth) ? (int)$rawWidth : self::SIDEBAR_DEFAULT_WIDTH;
		$width = max(self::SIDEBAR_MIN_WIDTH, min(self::SIDEBAR_MAX_WIDTH, $width));

		return [
			'width' => $width,
			// On mobile hits the sidebar is force-expanded so the user lands on the collections list,
			// regardless of the saved preference. On desktop the saved preference wins.
			'collapsed' => $rawCollapsed === 'Y' && !$isMobile,
			// Which blocks of the panel stand open. Read here rather than left to the client so the
			// panel is drawn the way it was left: taken from the page, the blocks are at their state
			// in the first frame instead of closing themselves once a preference arrives.
			'favoritesOpen' => $this->resolveSidebarSection($options, 'favoritesOpen'),
			'collectionsOpen' => $this->resolveSidebarSection($options, 'collectionsOpen'),
		];
	}

	/**
	 * A block of the panel is open unless it was explicitly closed: everyone who never touched a
	 * section header keeps the panel they have had all along.
	 */
	private function resolveSidebarSection(array $options, string $key): bool
	{
		return ($options[$key] ?? null) !== 'N';
	}

	private function isMobileHit(): bool
	{
		return str_starts_with((string)($_SERVER['REQUEST_URI'] ?? ''), '/mobile/');
	}
}

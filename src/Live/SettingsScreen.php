<?php

/**
 * This file is part of milpa/desktop-app — a Milpa app hosts itself as a desktop app.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/desktop-app
 */

declare(strict_types=1);

namespace Milpa\DesktopApp\Live;

use Milpa\DesktopApp\Data\DesktopData;
use Milpa\DesktopApp\I18n\Catalog;
use Milpa\Interfaces\Event\MilpaEventDispatcherInterface;
use Milpa\Live\Security\HmacStateSigner;
use Milpa\Live\Security\SignedXhtmlStateTransferCodec;
use Milpa\Live\Transport\XhtmlStateTransferCodec;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\StateSnapshot;

/**
 * Renders the Desktop's Settings screen as a {@see SettingsScreenComponent} (greenhouse decisions/0211,
 * phase B7) — the screen that used to be raw HTML in the shell's template.
 *
 * It mounts the component, produces the four cards (model and provider, default autonomy, context and
 * storage, appearance) and the Save / Discard row, carries the signed state envelope, and emits
 * `desktop.settings.before_render` / `after_render` so plugins can extend it.
 *
 * The endpoint shown is the PERSISTED one when the app has saved one, else the configured one
 * ({@see DesktopData::settings()}, {@see DesktopData::model()}). The badge's copy comes from the
 * catalog, so the server and the client say the same word (greenhouse decisions/0209).
 */
final class SettingsScreen
{
    public const string COMPONENT_ID = 'settings';
    public const string BEFORE_RENDER = 'desktop.settings.before_render';
    public const string AFTER_RENDER = 'desktop.settings.after_render';

    /** The default endpoint a Desktop with no configuration talks to. */
    private const string DEFAULT_ENDPOINT = 'http://llama.local:11438';

    private readonly SignedXhtmlStateTransferCodec $codec;

    private readonly Catalog $catalog;

    /**
     * @param Catalog|null $catalog the copy the badge speaks; null answers in English
     */
    public function __construct(
        string $signingSecret,
        private readonly ?DesktopData $data = null,
        private readonly ?MilpaEventDispatcherInterface $events = null,
        ?Catalog $catalog = null,
    ) {
        $this->codec = new SignedXhtmlStateTransferCodec(new XhtmlStateTransferCodec(), new HmacStateSigner($signingSecret), null);
        $this->catalog = $catalog ?? new Catalog();
    }

    /** The screen's server-rendered HTML — a component with its signed envelope and a signal-bound badge. */
    public function render(): string
    {
        $component = new SettingsScreenComponent();
        $subject = new ComposerRender([
            'endpoint' => $this->endpoint(),
            'sessionsPath' => '.milpa/sessions/',
            'savedLabel' => $this->catalog->tr('settings.saved'),
        ]);
        $this->events?->dispatch(self::BEFORE_RENDER, ['settings' => $subject]);

        $context = new ComponentContext(componentId: self::COMPONENT_ID);
        $state = $component->mount($subject->props, $context);
        $subject->html = $this->markup($subject->props) . $this->envelope($state);

        $this->events?->dispatch(self::AFTER_RENDER, ['settings' => $subject]);

        return $subject->html;
    }

    /** The model endpoint: the persisted setting if one was saved (0483), else the configured one. */
    private function endpoint(): string
    {
        $settings = $this->data?->settings() ?? [];
        $saved = $settings['endpoint'] ?? null;

        return \is_string($saved) && $saved !== '' ? $saved : ($this->data?->model()['endpoint'] ?? self::DEFAULT_ENDPOINT);
    }

    /** @param array<string, mixed> $props */
    private function markup(array $props): string
    {
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);

        // A DECLARED VIEW (greenhouse decisions/0211): the look is `desktop-settings.css`, the behaviour is
        // the `desktopSettings` factory of `desktop-settings.js` — Save posts and reports what the door
        // answered, Discard reloads the persisted values, and the theme buttons set the SHARED `ui.theme`
        // signal the topbar's module owns, so there is one theme, not two.
        return '<div class="view milpa-settings" data-view="settings" data-milpa-runtime="alpine"'
            . ' data-milpa-component="desktop-settings" data-milpa-component-id="' . self::COMPONENT_ID . '" x-data="desktopSettings()" hidden>'
            . '<div class="milpa-settings__grid">'
            . $this->modelCard($e((string) ($props['endpoint'] ?? '')))
            . $this->autonomyCard()
            . $this->storageCard($e((string) ($props['sessionsPath'] ?? '.milpa/sessions/')))
            . $this->appearanceCard()
            . '</div>'
            . $this->actions($e((string) ($props['savedLabel'] ?? 'Saved')))
            . '</div>';
    }

    /**
     * One catalog message, escaped for HTML text — every human string on this screen goes through here, so
     * a `desktop.locale = es` app reads a Spanish Settings screen and not an English island in it.
     */
    private function t(string $key, string ...$args): string
    {
        return htmlspecialchars($this->catalog->tr($key, ...$args), ENT_QUOTES);
    }

    private function modelCard(string $endpoint): string
    {
        return '<div class="mui-card mui-card--raised">'
            . '<div class="mui-card__header"><h2 class="mui-card__title">' . $this->t('settings.model.title') . '</h2></div>'
            . '<div class="mui-card__body mui-stack">'
            . '<div class="mui-field"><label class="mui-field__label" for="set-prov">' . $this->t('settings.model.provider') . '</label><span class="mui-select-wrap"><select id="set-prov" class="mui-select">'
            . '<option>' . $this->t('settings.provider.local') . '</option><option>' . $this->t('settings.provider.lan') . '</option><option>' . $this->t('settings.provider.external') . '</option></select></span></div>'
            . '<div class="mui-field"><label class="mui-field__label" for="set-end">' . $this->t('settings.model.endpoint') . '</label><input id="set-end" class="mui-input milpa-settings__mono" value="' . $endpoint . '"><span class="mui-field__hint">' . $this->t('settings.model.endpoint_hint') . '</span></div>'
            . '<div class="mui-field mui-field--row milpa-settings__row"><label class="mui-field__label" for="set-stream">' . $this->t('settings.model.stream') . '</label><input class="mui-switch" type="checkbox" id="set-stream" checked="checked"></div>'
            . '</div></div>';
    }

    /**
     * The three autonomy choices. The BADGES («ask», «acknowledge», «auto») are the mode's own values —
     * what `/mode` takes and what the turn carries — so they are not copy and are not translated.
     */
    private function autonomyCard(): string
    {
        $choice = fn (string $mode, bool $checked): string => '<label class="mui-choice"><input class="mui-radio" type="radio" name="set-mode" value="' . $mode . '"' . ($checked ? ' checked="checked"' : '') . '>'
            . '<span class="mui-choice__text">' . $this->t('settings.autonomy.' . $mode) . ' <span class="mui-badge milpa-settings__badge">' . $mode . '</span>'
            . '<span class="mui-choice__hint">' . $this->t('settings.autonomy.' . $mode . '_hint') . '</span></span></label>';

        return '<div class="mui-card mui-card--raised">'
            . '<div class="mui-card__header"><h2 class="mui-card__title">' . $this->t('settings.autonomy.title') . '</h2></div>'
            . '<div class="mui-card__body mui-stack mui-stack--sm">'
            . $choice('ask', true) . $choice('acknowledge', false) . $choice('auto', false)
            . '<div class="mui-alert mui-alert--info" role="note"><span class="mui-alert__icon" aria-hidden="true">i</span><div class="mui-alert__content"><p class="mui-alert__desc">' . $this->t('settings.autonomy.note') . '</p></div></div>'
            . '</div></div>';
    }

    private function storageCard(string $sessionsPath): string
    {
        return '<div class="mui-card">'
            . '<div class="mui-card__header"><h2 class="mui-card__title">' . $this->t('settings.storage.title') . '</h2></div>'
            . '<div class="mui-card__body mui-stack mui-stack--sm">'
            . '<div class="mui-field mui-field--row milpa-settings__row"><label class="mui-field__label" for="set-comp">' . $this->t('settings.storage.compact') . '</label><input class="mui-switch" id="set-comp" type="checkbox" checked="checked"></div>'
            . '<p class="milpa-settings__note">' . $this->t('settings.storage.compact_note') . '</p>'
            . '<div class="mui-field"><label class="mui-field__label" for="set-path">' . $this->t('settings.storage.folder') . '</label><input id="set-path" class="mui-input mui-input--sm milpa-settings__mono" value="' . $sessionsPath . '" readonly="readonly"></div>'
            . '</div></div>';
    }

    /**
     * Appearance: the three theme buttons set the SHARED `ui.theme` signal, and `aria-pressed` BINDS to it
     * — so the chrome's toggle and these buttons can never disagree about what the shell is showing.
     */
    private function appearanceCard(): string
    {
        $buttons = '';
        foreach (['system', 'dark', 'light'] as $key) {
            $buttons .= sprintf(
                '<button type="button" class="mui-btn mui-btn--sm" data-theme-set="%s"%s @click="setTheme(\'%s\')" :aria-pressed="isTheme(\'%s\')">%s</button>',
                $key,
                $key === 'dark' ? ' aria-pressed="true"' : '',
                $key,
                $key,
                $this->t('settings.theme.' . $key),
            );
        }

        return '<div class="mui-card">'
            . '<div class="mui-card__header"><h2 class="mui-card__title">' . $this->t('settings.appearance.title') . '</h2></div>'
            . '<div class="mui-card__body mui-stack mui-stack--sm">'
            . '<div class="mui-field"><span class="mui-field__label">' . $this->t('settings.appearance.theme') . '</span><div class="mui-cluster mui-cluster--sm">' . $buttons . '</div></div>'
            . '<div class="mui-field"><span class="mui-field__label">' . $this->t('settings.appearance.scale') . '</span><div class="mui-cluster mui-cluster--sm"><button type="button" class="mui-btn mui-btn--sm" aria-pressed="true">100%</button><button type="button" class="mui-btn mui-btn--sm">115%</button><button type="button" class="mui-btn mui-btn--sm">130%</button></div></div>'
            . '</div></div>';
    }

    /**
     * The action row. The badge is the `settings.saved` signal: hidden until a save is reported, green on
     * a 2xx, a warning naming the status on anything else — never a «Saved» the server did not say.
     */
    private function actions(string $savedLabel): string
    {
        return '<div class="mui-cluster milpa-settings__actions">'
            . '<span id="milpa-settings-saved" class="mui-badge mui-badge--success" hidden'
            . ' x-text="savedText" :hidden="!saved" :class="{ \'mui-badge--success\': savedOk, \'mui-badge--warning\': !savedOk }">' . $savedLabel . '</span>'
            . '<button type="button" class="mui-btn" id="milpa-discard" @click="discard()">' . $this->t('settings.discard') . '</button>'
            . '<button type="button" class="mui-btn mui-btn--primary" id="milpa-save-settings" @click="save()">' . $this->t('settings.save') . '</button>'
            . '</div>';
    }

    private function envelope(StateSnapshot $state): string
    {
        return '<script type="application/milpa+xhtml" data-milpa-state="' . self::COMPONENT_ID . '">' . $this->codec->encodeState($state) . '</script>';
    }
}

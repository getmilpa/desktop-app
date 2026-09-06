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
 * Renders the Desktop's entry overlay as an {@see AuthOverlayComponent} (greenhouse decisions/0211, phase B5)
 * — the surface that used to be raw HTML in the shell's template, now a declared view like every other.
 *
 * It mounts the component, produces the overlay (the app it reads, what the decision identity is worth,
 * the model provider, and «Open workspace»), carries the signed state envelope, binds its visibility to
 * the shared `desktop.auth.open` signal, and emits `desktop.auth.before_render` / `after_render` so
 * plugins can extend it.
 *
 * The model provider's first option names the REAL local model and endpoint the app is configured with
 * ({@see DesktopData::model()}), so the overlay never claims a provider the app does not have.
 *
 * Every human string comes from the {@see Catalog} — the sentence this overlay exists to say most of all
 * included. A shell that speaks Spanish in the topbar and English in its entry screen is a shell that has
 * not decided what it speaks (greenhouse decisions/0138).
 */
final class AuthOverlay
{
    public const string COMPONENT_ID = 'auth';
    public const string BEFORE_RENDER = 'desktop.auth.before_render';
    public const string AFTER_RENDER = 'desktop.auth.after_render';

    /** The overlay's element id — what the shell's other surfaces open through the client module. */
    public const string ID = 'milpa-auth';

    private readonly SignedXhtmlStateTransferCodec $codec;

    private readonly Catalog $catalog;

    /**
     * @param Catalog|null $catalog the copy the overlay speaks; null answers in English
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

    /** The overlay's server-rendered HTML — a component with its signed envelope, hidden until asked for. */
    public function render(): string
    {
        $component = new AuthOverlayComponent();
        $subject = new ComposerRender(['open' => false, 'app' => 'getmilpa/framework', 'provider' => $this->providerLabel()]);
        $this->events?->dispatch(self::BEFORE_RENDER, ['auth' => $subject]);

        $context = new ComponentContext(componentId: self::COMPONENT_ID);
        $state = $component->mount($subject->props, $context);
        $subject->html = $this->markup($subject->props) . $this->envelope($state);

        $this->events?->dispatch(self::AFTER_RENDER, ['auth' => $subject]);

        return $subject->html;
    }

    /** The real model label for the provider option: "Local model · <model> (<endpoint>)", in the locale. */
    private function providerLabel(): string
    {
        $model = $this->data?->model() ?? ['model' => 'qwen3.8-27b', 'endpoint' => 'http://llama.local:11438'];

        return $this->catalog->tr('auth.provider.local', (string) $model['model'], (string) $model['endpoint']);
    }

    /**
     * One catalog message, escaped for HTML text. Every human string of the overlay comes from here — the
     * one it exists to say most of all («your system user is not a verified identity») included, so an
     * app running in Spanish reads that sentence in Spanish instead of not reading it.
     */
    private function t(string $key, string ...$args): string
    {
        return htmlspecialchars($this->catalog->tr($key, ...$args), ENT_QUOTES);
    }

    /** @param array<string, mixed> $props */
    private function markup(array $props): string
    {
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
        $app = $e((string) ($props['app'] ?? 'getmilpa/framework'));
        $provider = $e((string) ($props['provider'] ?? ''));
        // The one message with markup in it: the file it reads is a path, not copy, so it is an argument.
        $appHint = $this->catalog->tr('auth.app_hint', '<code>.milpa/foundation.json</code>');

        // A DECLARED VIEW (greenhouse decisions/0211): the look is `desktop-auth.css`, the behaviour is the
        // `desktopAuth` factory of `desktop-auth.js`. The overlay is hidden by default (server) and its
        // visibility is the shared `desktop.auth.open` signal, so any surface can ask for it. Escape closes
        // it: `dismiss()` is the way out of a full-screen overlay that nothing else can dismiss.
        return '<div class="view milpa-auth" data-view="auth" id="' . self::ID . '" data-milpa-runtime="alpine"'
            . ' data-milpa-component="desktop-auth" data-milpa-component-id="' . self::COMPONENT_ID . '" x-data="desktopAuth()" hidden :hidden="!open" @keydown.escape.window="dismiss()">'
            . '<div class="milpa-auth__pitch">'
            . '<p class="mui-section__kicker milpa-auth__kicker">' . $this->t('auth.kicker') . '</p>'
            . '<h1 class="milpa-auth__title">Milpa Desktop</h1>'
            . '<p class="milpa-auth__lede">' . $this->t('auth.lede') . '</p>'
            . '</div>'
            . '<div class="milpa-auth__form">'
            . '<div class="mui-stack">'
            . '<div class="mui-field"><label class="mui-field__label" for="auth-app">' . $this->t('auth.app') . '</label><input id="auth-app" class="mui-input mui-input--lg milpa-auth__app" value="' . $app . '" readonly="readonly"><span class="mui-field__hint">' . $appHint . '</span></div>'
            . '<div class="mui-field"><span class="mui-field__label">' . $this->t('auth.identity') . '</span>'
            . '<label class="mui-choice"><input class="mui-radio" type="radio" name="auth-id" checked="checked"><span class="mui-choice__text">' . $this->t('auth.identity.system') . '<span class="mui-choice__hint">' . $this->t('auth.identity.system_hint') . '</span></span></label>'
            . '<label class="mui-choice"><input class="mui-radio" type="radio" name="auth-id"><span class="mui-choice__text">' . $this->t('auth.identity.verified') . '<span class="mui-choice__hint">' . $this->t('auth.identity.verified_hint') . '</span></span></label>'
            . '</div>'
            . '<div class="mui-field"><label class="mui-field__label" for="auth-prov">' . $this->t('auth.provider') . '</label><span class="mui-select-wrap"><select id="auth-prov" class="mui-select mui-select--lg"><option>' . $provider . '</option><option>' . $this->t('settings.provider.lan') . '</option><option>' . $this->t('settings.provider.external') . '</option></select></span></div>'
            . '</div>'
            . '<div class="mui-alert mui-alert--warning" role="note"><span class="mui-alert__icon" aria-hidden="true">!</span><div class="mui-alert__content"><p class="mui-alert__title">' . $this->t('auth.warning.title') . '</p><p class="mui-alert__desc">' . $this->t('auth.warning.desc') . '</p></div></div>'
            . '<div class="milpa-auth__actions"><button type="button" class="mui-btn mui-btn--primary mui-btn--full mui-btn--lg" id="milpa-auth-enter" @click="enter()">' . $this->t('auth.enter') . '</button></div>'
            . '</div></div>';
    }

    private function envelope(StateSnapshot $state): string
    {
        return '<script type="application/milpa+xhtml" data-milpa-state="' . self::COMPONENT_ID . '">' . $this->codec->encodeState($state) . '</script>';
    }
}

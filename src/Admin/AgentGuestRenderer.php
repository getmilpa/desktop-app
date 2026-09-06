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

namespace Milpa\DesktopApp\Admin;

use Milpa\DesktopApp\I18n\Catalog;
use Milpa\DesktopApp\Live\DesktopAssets;
use Milpa\Live\Contracts\Component\ComponentDefinitionInterface;
use Milpa\Live\Contracts\Rendering\ComponentRendererInterface;
use Milpa\Live\ValueObjects\RenderRequest;
use Milpa\Live\ValueObjects\RenderResult;
use Milpa\Live\ValueObjects\RenderTarget;
use Milpa\Live\ValueObjects\StateSnapshot;

/**
 * Paints {@see AgentGuestComponent} for HTML — the REGION only (greenhouse decisions/0210): the admin puts the
 * section header and whatever it says about who declared the section; this emits what goes inside main.
 *
 * Three states, one root `<div class="desktop-agent" id="…" data-desktop-agent="…">`:
 *
 *   - `live`: a same-origin `<iframe>` at the embed path filling the region (a small `<style>` scoped by the
 *     component id gives it the main's available height, computed from the host's own tokens — the topbar's
 *     height and the main's padding — with fallbacks), never `loading="lazy"` — the Agent is the point of the
 *     page, not a footer — plus a guest bar: the `gate: <label>` chip and a link that opens the full Desktop
 *     in a new tab (`rel="noopener"`);
 *   - `signed-out`: no frame; «Sign in to open the Agent» and the sign-in door with `next` pointing back at
 *     this section, so the human returns here once the passkey ceremony is done;
 *   - the contained error: whatever the Desktop ANSWERS — a 403, a 500, the sign-in door — loads inside the
 *     frame and stays there, the admin whole around it. Only when the frame gets NO document from the Desktop
 *     (the app is down or unreachable: the browser paints its own error page, which is cross-origin, so the
 *     frame's `contentDocument` is null) does the inline `onload` probe hide the frame and show «The Agent did
 *     not answer» inside the region. `onerror` is kept for the engines that fire it; browsers report a failed
 *     frame navigation as a `load` of their error page, never as `error`. No dependency on the admin's JS,
 *     nothing outside the region moves.
 *
 * Every word comes from the Desktop's {@see Catalog}: the request's locale when the context carries one the
 * catalog knows, else the catalog the Desktop plugin declared, else English.
 */
final class AgentGuestRenderer implements ComponentRendererInterface
{
    /** The component this region's declared files are served under, like every other Desktop surface. */
    public const string ASSETS = 'desktop-agent-guest';

    /**
     * @param Catalog|null $catalog the Desktop's copy in its declared locale; null answers in English
     */
    public function __construct(private readonly ?Catalog $catalog = null)
    {
    }

    /** HTML only: the admin is a web panel; a TUI has no frame to embed the Desktop in. */
    public function supportsTarget(RenderTarget $target): bool
    {
        return $target === RenderTarget::HTML;
    }

    /**
     * The region's HTML for the state given, or for a fresh mount from the request's props and context.
     *
     * @throws \InvalidArgumentException for a component other than {@see AgentGuestComponent}, or a target other than HTML
     */
    public function render(ComponentDefinitionInterface $component, RenderRequest $request): RenderResult
    {
        $name = $component::contract()->name;
        if ($name !== AgentGuestComponent::NAME) {
            throw new \InvalidArgumentException(\sprintf('%s renders only «%s», not «%s».', self::class, AgentGuestComponent::NAME, $name));
        }
        if (!$this->supportsTarget($request->target)) {
            throw new \InvalidArgumentException(\sprintf('%s renders HTML only, not «%s».', self::class, $request->target->value));
        }

        $state = $request->state ?? $component->mount($request->props, $request->context);
        $locale = $request->context->locale ?? $state->meta['locale'] ?? null;
        $catalog = $this->catalogFor(\is_string($locale) ? $locale : null);

        $html = ($state->data['state'] ?? null) === AgentGuestComponent::STATE_SIGNED_OUT
            ? $this->signedOut($state, $catalog)
            : $this->live($state, $catalog);

        return new RenderResult(output: $html, state: $state);
    }

    /** The catalog answering in the request's locale when the Desktop carries it, else the declared one, else English. */
    private function catalogFor(?string $locale): Catalog
    {
        if ($locale !== null && \in_array($locale, Catalog::locales(), true)) {
            return new Catalog($locale);
        }

        return $this->catalog ?? new Catalog();
    }

    /**
     * The two tags that carry this region's declared look and its probe.
     *
     * Emitted by the renderer itself — see the class docblock: milpa/admin is the host, and a guest has no
     * emitter to hand its files to. Both are package files on the Desktop's gate-free asset route, so a
     * JSON 401 can never break them in silence.
     */
    private static function declaredFiles(): string
    {
        return '<link rel="stylesheet" href="' . DesktopAssets::url(self::ASSETS, 'css') . '">'
            . '<script src="' . DesktopAssets::url(self::ASSETS, 'js') . '" defer></script>';
    }

    private function live(StateSnapshot $state, Catalog $catalog): string
    {
        $id = self::attr($state->componentId);
        $gate = self::meta($state, 'gate', 'loopback');
        $e = static fn (string $s): string => self::attr($s);

        return '<div class="desktop-agent" id="' . $id . '" data-desktop-agent="' . AgentGuestComponent::STATE_LIVE . '" data-gate="' . $e($gate) . '">'
            . self::declaredFiles()
            . '<div class="desktop-agent__bar">'
            . '<span class="mui-badge desktop-chip desktop-chip--gate" data-gate="' . $e($gate) . '">' . $e($catalog->tr('chip.gate', $catalog->tr('gate.kind.' . $gate))) . '</span>'
            . '<a class="mui-btn mui-btn--sm desktop-agent__open" href="' . $e(self::meta($state, 'open', AgentGuestComponent::DEFAULT_OPEN)) . '" target="_blank" rel="noopener">' . $e($catalog->tr('agent.open')) . '</a>'
            . '</div>'
            // No same-origin document after the load = the Desktop did not answer (the browser's own error page
            // is cross-origin). What the Desktop did answer — a 403, a 500, the sign-in door — stays in the
            // frame. The probe is `desktop-agent-guest.js`; the frame carries its `src` in the markup, so a
            // reader with no JavaScript still gets the Agent, exactly as before.
            . '<iframe class="desktop-agent__frame" id="' . $id . '-frame" src="' . $e(self::meta($state, 'embed', AgentGuestComponent::DEFAULT_EMBED)) . '" title="' . $e($catalog->tr('agent.frame')) . '"></iframe>'
            . '<p class="mui-alert mui-alert--warning desktop-agent__notice" id="' . $id . '-notice" role="alert" hidden>' . $e($catalog->tr('agent.unanswered')) . '</p>'
            . '</div>';
    }

    private function signedOut(StateSnapshot $state, Catalog $catalog): string
    {
        $id = self::attr($state->componentId);
        $gate = self::meta($state, 'gate', 'passkey');
        $href = self::meta($state, 'signin', AgentGuestComponent::DEFAULT_SIGNIN) . '?next=' . rawurlencode(self::meta($state, 'next', AgentGuestComponent::sectionPath(null)));
        $e = static fn (string $s): string => self::attr($s);

        return '<div class="desktop-agent" id="' . $id . '" data-desktop-agent="' . AgentGuestComponent::STATE_SIGNED_OUT . '" data-gate="' . $e($gate) . '">'
            . self::declaredFiles()
            . '<p class="mui-alert mui-alert--info desktop-agent__signin" role="note">'
            . '<span class="mui-alert__icon" aria-hidden="true">i</span>'
            . '<span class="mui-alert__content">' . $e($catalog->tr('agent.signin')) . '</span> '
            . '<a class="mui-btn mui-btn--primary mui-btn--sm desktop-agent__signin-link" href="' . $e($href) . '">' . $e($catalog->tr('agent.signin.action')) . '</a>'
            . '</p>'
            . '</div>';
    }

    private static function meta(StateSnapshot $state, string $key, string $default): string
    {
        $value = $state->meta[$key] ?? null;

        return \is_string($value) && $value !== '' ? $value : $default;
    }

    private static function attr(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

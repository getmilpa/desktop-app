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
    /**
     * The main's available height, from the host's own tokens: the viewport minus the admin topbar
     * (`--_topbar-h`, the shell's alias of `--header-h`) and the main's padding (the same `clamp()` milpa/admin's
     * bundle gives `.mui-shell__main`), each with a fallback to the values measured in milpa/admin 0.10.1.
     */
    private const string HEIGHT = 'calc(100dvh - var(--_topbar-h, var(--header-h, 3.5rem)) - 2*clamp(var(--space-5, 1.25rem), 3vw, var(--space-8, 2rem)))';

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

    private function live(StateSnapshot $state, Catalog $catalog): string
    {
        $id = self::attr($state->componentId);
        $gate = self::meta($state, 'gate', 'loopback');
        $e = static fn (string $s): string => self::attr($s);
        // The contained error, inside the region only: hide the frame, show the notice. The id is a JS string
        // literal inside an HTML attribute — escaped for both.
        $reveal = 'this.hidden=true;var n=document.getElementById(\'' . $e(addcslashes($state->componentId . '-notice', "\\'")) . '\');if(n){n.hidden=false}';

        return '<div class="desktop-agent" id="' . $id . '" data-desktop-agent="' . AgentGuestComponent::STATE_LIVE . '" data-gate="' . $e($gate) . '">'
            . '<style>'
            . '#' . $id . '{display:flex;flex-direction:column;gap:var(--space-3,.75rem);height:' . self::HEIGHT . ';min-height:24rem}'
            . '#' . $id . ' .desktop-agent__bar{display:flex;align-items:center;gap:var(--space-3,.75rem);flex:none}'
            . '#' . $id . ' .desktop-agent__open{margin-inline-start:auto}'
            . '#' . $id . ' .desktop-agent__frame{flex:1;min-height:0;width:100%;border:1px solid var(--border-subtle,#333);border-radius:var(--radius-md,.5rem);background:var(--surface,#111)}'
            . '#' . $id . ' .desktop-agent__notice{margin:0}'
            . '</style>'
            . '<div class="desktop-agent__bar">'
            . '<span class="mui-badge desktop-chip desktop-chip--gate" data-gate="' . $e($gate) . '">' . $e($catalog->tr('chip.gate', $catalog->tr('gate.kind.' . $gate))) . '</span>'
            . '<a class="mui-btn mui-btn--sm desktop-agent__open" href="' . $e(self::meta($state, 'open', AgentGuestComponent::DEFAULT_OPEN)) . '" target="_blank" rel="noopener">' . $e($catalog->tr('agent.open')) . '</a>'
            . '</div>'
            . '<iframe class="desktop-agent__frame" id="' . $id . '-frame" src="' . $e(self::meta($state, 'embed', AgentGuestComponent::DEFAULT_EMBED)) . '" title="' . $e($catalog->tr('agent.frame')) . '"'
            // No same-origin document after the load = the Desktop did not answer (the browser's own error page
            // is cross-origin). What the Desktop did answer — a 403, a 500, the sign-in door — stays in the frame.
            . ' onload="if(!this.contentDocument){' . $reveal . '}"'
            . ' onerror="' . $reveal . '"></iframe>'
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

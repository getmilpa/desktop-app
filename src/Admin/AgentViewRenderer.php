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

use Milpa\DesktopApp\Data\DesktopData;
use Milpa\DesktopApp\DesktopSettings;
use Milpa\DesktopApp\I18n\Catalog;
use Milpa\DesktopApp\Live\CommandListView;
use Milpa\DesktopApp\Live\ComposerField;
use Milpa\DesktopApp\Live\DesktopAssets;
use Milpa\DesktopApp\Live\DesktopComponents;
use Milpa\Live\Contracts\Component\ComponentDefinitionInterface;
use Milpa\Live\Contracts\Rendering\ComponentRendererInterface;
use Milpa\Live\Contracts\Rendering\DeclaresClientAssets;
use Milpa\Live\ValueObjects\ClientAssets;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\RenderRequest;
use Milpa\Live\ValueObjects\RenderResult;
use Milpa\Live\ValueObjects\RenderTarget;
use Milpa\Live\ValueObjects\StateSnapshot;

/**
 * Paints {@see AgentViewComponent} — the Desktop's conversation region, COMPOSED INSIDE the host's page
 * (greenhouse decisions/0211, slice 3, retiring the frame of 0210).
 *
 * There is no iframe here, and no second runtime. The region is compiled through the Desktop's ONE
 * component registry ({@see DesktopComponents} — the very instance the shell composes `/desktop` with, so
 * the definitions and the renderers are the same objects, never a second rendering path), and every file
 * those renderers DECLARE is handed back on {@see RenderResult::$clientAssets} for the HOST to emit once
 * through `LiveBoot::html()`. The Desktop loads no Alpine, no `milpa-live`, no boot of its own inside
 * somebody else's document: it declares, the host emits.
 *
 * What the region carries, in this order:
 *
 *   1. the guest bar — the `gate: <label>` chip and the link that opens the FULL Desktop in a new tab.
 *      It is what the frame's bar was, and it keeps its purpose: the panel shows the conversation, the
 *      Desktop's own page shows the rest (sidebar, sessions, screens);
 *   2. the session view — the tablist, the four panes (conversation + consent gate, work board, activity,
 *      context) and the composer docked below them: the same components, in the same layout, that
 *      `/desktop` paints;
 *   3. the message prototypes the conversation clones per message (greenhouse decisions/0191), each its
 *      own declared component, each inside the `<template>` the thread looks up by id;
 *   4. the DATA tags the Desktop's runtime modules read — the commands, the catalog, the guard's doors
 *      and the agent session. `type="application/json"`, never executable: the region writes no script.
 *
 * **Contained per surface.** Each surface is compiled on its own and a surface that throws while mounting
 * or rendering paints a small failure region NAMING it, inside the region, while the rest of the Agent
 * stands — the same rule milpa/admin applies per view root ({@see \Milpa\Admin\View\AdminShell}), applied
 * here per surface because the whole region is ONE root of the declared view.
 *
 * **Signed out.** When the Desktop's gate is the passkey gate and the admin authenticated nobody, the view
 * is NOT composed at all: no surface is mounted, no module is asked for, and the region is the sign-in
 * offer with `next` pointing back at this section (greenhouse decisions/0210 §2).
 *
 * **One region, ONE language.** Every word here — the bar, the composed surfaces, the client catalog the
 * modules read and the signals the page is seeded with — comes from the SAME {@see Catalog}: the one the
 * Desktop plugin declared (`desktop.locale`), exactly as `/desktop` does. The host's `?lang=` switches the
 * PANEL's chrome, not the guest's region: the surfaces are the shell's own instances, each holding the
 * declared catalog (that is what «reuse, do not fork» costs), and the seeds are declared with that catalog
 * too — so following the request's locale would translate the bar and leave the conversation, the mode
 * chip and the seeded labels in the other language, in one document. What DOES follow the request is the
 * way back: the sign-in `next` keeps `?lang=`, so the panel returns in the language the human was reading.
 * With no declared catalog at all (a host mounting this renderer by hand) there is nothing to disagree
 * with, and the locale the host resolved is used.
 */
final class AgentViewRenderer implements ComponentRendererInterface, DeclaresClientAssets
{
    /** The component whose declared files this region ships — its own stylesheet, like every Desktop surface. */
    public const string ASSETS = AgentViewComponent::NAME;

    /**
     * The surfaces the region composes, in document order — the Desktop's own conversation, said once.
     *
     * @var list<string>
     */
    public const array SURFACES = [
        'desktop-tabs',
        'desktop-conversation',
        'desktop-gate',
        'desktop-work-board',
        'desktop-activity',
        'desktop-context',
        'desktop-composer',
        'desktop-thinking',
        'desktop-agent-message',
        'desktop-user-message',
        'desktop-tool-call',
        'desktop-task',
        'desktop-system-notice',
        'desktop-result-claim',
    ];

    /**
     * The message kinds the conversation clones, and the `<template>` id each one is looked up by — the
     * ids the message modules resolve (`desktop-conversation.js`, `desktop-thinking.js`…).
     *
     * @var array<string, string> component name => template id
     */
    private const array PROTOTYPES = [
        'desktop-thinking' => 'milpa-thinking-proto',
        'desktop-agent-message' => 'milpa-agent-msg-proto',
        'desktop-user-message' => 'milpa-user-msg-proto',
        'desktop-tool-call' => 'milpa-tool-msg-proto',
        'desktop-task' => 'milpa-task-msg-proto',
        'desktop-system-notice' => 'milpa-system-msg-proto',
        'desktop-result-claim' => 'milpa-result-msg-proto',
    ];

    /**
     * The door is NOT a dependency here: which gate this Desktop stands behind, and where its sign-in
     * lives, are PROPS the section declared and the component put in its state — so the region says what
     * the plugin declared, never what it re-read.
     *
     * @param DesktopComponents $live    the Desktop's ONE registry — the same instance the shell composes with
     * @param DesktopData|null  $data    the session seam, for the commands the composer completes
     * @param Catalog|null      $catalog the Desktop's copy in its declared locale; null answers in English
     */
    public function __construct(
        private readonly DesktopComponents $live,
        private readonly ?DesktopData $data = null,
        private readonly ?Catalog $catalog = null,
    ) {
    }

    /** HTML only: the admin is a web panel; a TUI host would mount the Desktop's TUI target, not this. */
    public function supportsTarget(RenderTarget $target): bool
    {
        return $target === RenderTarget::HTML;
    }

    /** Exactly this component's own file — the region's layout. Every other file is a surface's, declared by its renderer. */
    public function clientAssets(): ClientAssets
    {
        return DesktopAssets::of(self::ASSETS);
    }

    /**
     * The region's HTML for the state given, or for a fresh mount from the request's props and context,
     * carrying on the result every file the composed surfaces declared plus the Desktop's runtime modules.
     *
     * @throws \InvalidArgumentException for a component other than {@see AgentViewComponent}, or a target other than HTML
     */
    public function render(ComponentDefinitionInterface $component, RenderRequest $request): RenderResult
    {
        $name = $component::contract()->name;
        if ($name !== AgentViewComponent::NAME) {
            throw new \InvalidArgumentException(\sprintf('%s renders only «%s», not «%s».', self::class, AgentViewComponent::NAME, $name));
        }
        if (!$this->supportsTarget($request->target)) {
            throw new \InvalidArgumentException(\sprintf('%s renders HTML only, not «%s».', self::class, $request->target->value));
        }

        $state = $request->state ?? $component->mount($request->props, $request->context);
        $catalog = $this->catalogFor($state);
        $assets = ClientAssets::empty();

        $html = ($state->data['state'] ?? null) === AgentViewComponent::STATE_SIGNED_OUT
            ? $this->signedOut($state, $catalog)
            : $this->live($state, $request->context, $catalog, $assets);

        return new RenderResult(output: $html, state: $state, clientAssets: $assets);
    }

    /**
     * The ONE catalog the whole region answers in: the Desktop's declared one.
     *
     * It is not the request's, on purpose. The composed surfaces are the shell's own instances, each built
     * with the declared catalog, and the page's seeds ({@see \Milpa\DesktopApp\Live\ShellSignals}) were
     * declared with it too — a bar that followed `?lang=` would be the only thing in the region that did.
     * Only when NO catalog was declared (a renderer built by hand, with no plugin behind it) is there
     * nothing to disagree with, and then the locale the host resolved is the best answer available.
     */
    private function catalogFor(StateSnapshot $state): Catalog
    {
        if ($this->catalog !== null) {
            return $this->catalog;
        }
        $locale = $state->meta['locale'] ?? null;

        return \is_string($locale) && \in_array($locale, Catalog::locales(), true) ? new Catalog($locale) : new Catalog();
    }

    /**
     * The live region: the guest bar, the session view, the prototypes and the data tags.
     *
     * The runtime modules the PAGE declares ({@see DesktopAssets::runtimeModules()} — the guard, the bus,
     * the hub, the turn and the commands, none of them a surface) lead the asset list exactly as they do
     * on `/desktop`, so the host emits them before the component modules that call them.
     */
    private function live(StateSnapshot $state, ComponentContext $context, Catalog $catalog, ClientAssets &$assets): string
    {
        $assets = $assets->merge(new ClientAssets(scripts: array_map(
            static fn (string $module): string => DesktopAssets::url($module, 'js'),
            DesktopAssets::runtimeModules(),
        )));

        $id = self::attr($state->componentId);
        $gate = self::meta($state, 'gate', DesktopSettings::GATE_LOOPBACK);
        // A closure that captures `$assets` BY REFERENCE: an arrow function captures by value, and the
        // files every surface declared would be merged into a copy the page never sees.
        $paint = function (string $component) use ($context, $catalog, &$assets): string {
            return $this->paint($component, $context, $catalog, $assets);
        };

        $panes = '<section class="tabpane milpa-chat" data-pane="chat" id="milpa-chat" data-milpa-component="desktop-conversation" data-milpa-component-id="conversation"'
                . ' x-data="desktopConversation()" @click="onClick($event)" :hidden="$store.milpa[\'desktop.tab\'] !== \'chat\'">'
                . $paint('desktop-conversation')
                . $paint('desktop-gate')
                . '</section>'
            . '<section class="tabpane" data-pane="work" hidden :hidden="$store.milpa[\'desktop.tab\'] !== \'work\'">' . $paint('desktop-work-board') . '</section>'
            . '<section class="tabpane tabpane--activity" data-pane="activity" hidden :hidden="$store.milpa[\'desktop.tab\'] !== \'activity\'">' . $paint('desktop-activity') . '</section>'
            . '<section class="tabpane" data-pane="context" hidden :hidden="$store.milpa[\'desktop.tab\'] !== \'context\'">' . $paint('desktop-context') . '</section>';

        $prototypes = '';
        foreach (self::PROTOTYPES as $component => $template) {
            $prototypes .= '<template id="' . $template . '">' . $paint($component) . '</template>';
        }

        return '<div class="desktop-agent" id="' . $id . '" data-desktop-agent="' . AgentViewComponent::STATE_LIVE . '" data-gate="' . self::attr($gate) . '">'
            . $this->bar($state, $catalog, $gate)
            . '<div class="view view--session" data-view="session" x-data>'
            . $paint('desktop-tabs')
            . '<div class="view--session__scroll">' . $panes . '</div>'
            . '<div id="milpa-composer-dock" class="view--session__dock" :hidden="$store.milpa[\'desktop.tab\'] !== \'chat\'">' . $paint('desktop-composer') . '</div>'
            . '</div>'
            . $prototypes
            . $this->dataTags($state, $catalog)
            . '</div>';
    }

    /** The guest bar: what door this Desktop stands behind, and the way out to its own page. */
    private function bar(StateSnapshot $state, Catalog $catalog, string $gate): string
    {
        return '<div class="desktop-agent__bar">'
            . '<span class="mui-badge desktop-chip desktop-chip--gate" data-gate="' . self::attr($gate) . '">' . self::attr($catalog->tr('chip.gate', $catalog->tr('gate.kind.' . $gate))) . '</span>'
            . '<a class="mui-btn mui-btn--sm desktop-agent__open" href="' . self::attr(self::meta($state, 'open', AgentViewComponent::DEFAULT_OPEN)) . '" target="_blank" rel="noopener">' . self::attr($catalog->tr('agent.open')) . '</a>'
            . '</div>';
    }

    /**
     * One surface, compiled through the Desktop's own registry and contained on its own.
     *
     * A surface that throws while mounting or rendering paints a failure region naming it, and the rest of
     * the Agent — and the whole panel around it — stands (greenhouse decisions/0211, «contained errors»).
     * Whatever a surface declared is collected only when it RENDERED: a throwing surface declares nothing,
     * because nothing of it is on the page to need a file.
     *
     * **The host's context is not dropped at the door.** Each surface mounts with the `principal` the
     * admin authenticated and the `meta` it handed over (the gate label, the active section, the request's
     * query — greenhouse decisions/0211, H5), so a surface that wants to know who is reading can ask. Two
     * fields are the region's own and not the host's: the component id (each surface mounts under its own,
     * inside the region) and the `route`, which is the Desktop's live wire — what its envelopes are bound
     * to — not the panel's URL. The locale is the region's ONE catalog, never the request's ({@see
     * self::catalogFor()}).
     */
    private function paint(string $component, ComponentContext $context, Catalog $catalog, ClientAssets &$assets): string
    {
        try {
            $compiled = $this->live->compiler($this->propsFor())->compileFragment(
                '<milpa-' . $component . '/>',
                new ComponentContext(
                    componentId: 'agent-' . $component,
                    principal: $context->principal,
                    locale: $catalog->locale(),
                    route: ComposerField::ROUTE,
                    meta: $context->meta,
                ),
            );
        } catch (\Throwable $broken) {
            return '<div class="mui-alert mui-alert--warning desktop-agent__failure" role="alert" data-failed-component="' . self::attr($component) . '">'
                . '<strong>' . self::attr($catalog->tr('agent.surface.failed', $component)) . '</strong> '
                . '<span class="desktop-agent__failure-why">' . self::attr($broken->getMessage()) . '</span>'
                . '</div>';
        }
        $assets = $assets->merge($compiled->clientAssets());

        return $compiled->output;
    }

    /**
     * The props the region's surfaces mount with — the Desktop's shell chrome FOLDED, because the host
     * brings its own: the context tab shows no plugin panels the panel did not compose, and the sidebar
     * and topbar are not part of the region at all.
     *
     * @return array<string, array<string, mixed>>
     */
    private function propsFor(): array
    {
        return ['desktop-context' => ['sections' => []]];
    }

    /**
     * The DATA the Desktop's modules read on this page — the same four tags `/desktop` writes, minus the
     * hub's.
     *
     * The hub is deliberately absent (greenhouse decisions/0211, slice 3): the browser presents the
     * Mercure subscriber JWT as a COOKIE, and only `GET /desktop` can set it — a component rendered inside
     * somebody else's response sets no cookie. `desktop-hub.js` reads no tag, says «offline» once and
     * opens nothing; a governed turn still answers, because the answer, the pause and the closure verdict
     * come back on the `POST /agent` response. What does NOT reach the panel is what only the hub carries:
     * the live reasoning of a turn in flight, and the activity stream. Named, not papered over.
     */
    private function dataTags(StateSnapshot $state, Catalog $catalog): string
    {
        $json = static fn (mixed $value): string => (string) json_encode($value, \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_HEX_APOS | \JSON_HEX_QUOT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        $gate = self::meta($state, 'gate', DesktopSettings::GATE_LOOPBACK);

        return '<script id="milpa-commands" type="application/json">' . CommandListView::json($this->data?->commands() ?? DesktopData::houseCommands()) . '</script>'
            . '<script id="milpa-desktop-i18n" type="application/json">' . $json($catalog->all()) . '</script>'
            . '<script id="milpa-desktop-guard" type="application/json">' . $json(['signin' => $gate === DesktopSettings::GATE_PASSKEY ? self::meta($state, 'signin', AgentViewComponent::DEFAULT_SIGNIN) : '']) . '</script>'
            . '<script id="milpa-desktop-session" type="application/json">' . $json(['agent' => self::agentSession($state)]) . '</script>';
    }

    /**
     * The agent session the region drives, DERIVED from who the admin authenticated.
     *
     * `/desktop` mints an id and keeps it in a cookie, so a reload continues the same governed session; a
     * component rendered inside the host's response cannot set one. Deriving it from the principal buys
     * the same continuity by other means — the same human returning to the panel returns to the same
     * session — and keeps two humans behind the same door out of each other's. With no principal (a panel
     * on the loopback gate, one operator by construction) the id is the panel's own, stable and shared.
     */
    private static function agentSession(StateSnapshot $state): string
    {
        $principal = $state->meta['principal'] ?? '';

        return 'desk-admin-' . substr(hash('sha256', 'milpa/admin|agent|' . (\is_string($principal) ? $principal : '')), 0, 16);
    }

    /** The signed-out region: no view is composed — the door, with the way back to this section. */
    private function signedOut(StateSnapshot $state, Catalog $catalog): string
    {
        $id = self::attr($state->componentId);
        $gate = self::meta($state, 'gate', DesktopSettings::GATE_PASSKEY);
        $href = self::meta($state, 'signin', AgentViewComponent::DEFAULT_SIGNIN) . '?next=' . rawurlencode(self::meta($state, 'next', AgentViewComponent::sectionPath(null)));

        return '<div class="desktop-agent" id="' . $id . '" data-desktop-agent="' . AgentViewComponent::STATE_SIGNED_OUT . '" data-gate="' . self::attr($gate) . '">'
            . '<p class="mui-alert mui-alert--info desktop-agent__signin" role="note">'
            . '<span class="mui-alert__icon" aria-hidden="true">i</span>'
            . '<span class="mui-alert__content">' . self::attr($catalog->tr('agent.signin')) . '</span> '
            . '<a class="mui-btn mui-btn--primary mui-btn--sm desktop-agent__signin-link" href="' . self::attr($href) . '">' . self::attr($catalog->tr('agent.signin.action')) . '</a>'
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

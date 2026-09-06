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
use Milpa\Interfaces\Event\MilpaEventDispatcherInterface;
use Milpa\Live\Security\HmacStateSigner;
use Milpa\Live\Security\SignedXhtmlStateTransferCodec;
use Milpa\Live\Transport\XhtmlStateTransferCodec;
use Milpa\Live\ValueObjects\ComponentContext;

/**
 * Renders the Desktop shell's sidebar as a {@see SidebarComponent} — the first surface of the "shell is pure
 * Milpa Components" migration (greenhouse decisions/0189). It mounts the component, produces the design-system
 * HTML (brand, nav, session list, actions) with a signal-driven active nav, carries the signed state envelope,
 * and emits `desktop.sidebar.before_render` / `after_render` so other plugins can extend it.
 */
final class Sidebar
{
    public const string COMPONENT_ID = 'sidebar';
    public const string BEFORE_RENDER = 'desktop.sidebar.before_render';
    public const string AFTER_RENDER = 'desktop.sidebar.after_render';

    /** @var list<array{key: string, label: string, icon: string}> */
    private const NAV = [
        ['key' => 'sessions', 'label' => 'Sessions', 'icon' => '▤'],
        ['key' => 'decisions', 'label' => 'Decisions', 'icon' => '◈'],
        ['key' => 'capabilities', 'label' => 'Capabilities', 'icon' => '▩'],
        ['key' => 'skills', 'label' => 'Skills', 'icon' => '✦'],
        ['key' => 'preview', 'label' => 'Preview', 'icon' => '◱'],
        ['key' => 'settings', 'label' => 'Settings', 'icon' => '⚙'],
    ];

    private const GRAIN = [[0, 0], [0, 12.5], [0, 25], [0, 37.5], [0, 50], [50, 0], [50, 12.5], [50, 25], [50, 37.5], [50, 50], [12.5, 12.5], [37.5, 12.5], [25, 25]];

    private readonly SignedXhtmlStateTransferCodec $codec;

    public function __construct(
        string $signingSecret,
        private readonly ?DesktopData $data = null,
        private readonly ?MilpaEventDispatcherInterface $events = null,
    ) {
        $this->codec = new SignedXhtmlStateTransferCodec(new XhtmlStateTransferCodec(), new HmacStateSigner($signingSecret), null);
    }

    /**
     * The sidebar's server-rendered HTML — a component with its signed envelope and a signal-driven nav.
     *
     * @param bool $chrome false in embed mode (greenhouse decisions/0210): the nav to the chrome screens
     *                     (Decisions, Capabilities, Skills, Preview, Settings) is not rendered — the host's
     *                     navigation stands — while the session list and the actions keep their ids
     */
    public function render(bool $chrome = true): string
    {
        $component = new SidebarComponent();
        $props = [
            'sessions' => $this->data?->sessions() ?? [],
            'activeSession' => $this->data?->currentSessionId() ?? '',
            'activeNav' => 'sessions',
            'decisions' => \count($this->data?->pendingDecisions() ?? []),
            'chrome' => $chrome,
        ];
        $subject = new ComposerRender($props);
        $this->events?->dispatch(self::BEFORE_RENDER, ['sidebar' => $subject]);

        $context = new ComponentContext(componentId: self::COMPONENT_ID);
        $state = $component->mount($subject->props, $context);
        $subject->html = $this->markup($subject->props) . $this->envelope($state);

        $this->events?->dispatch(self::AFTER_RENDER, ['sidebar' => $subject]);

        return $subject->html;
    }

    /** @param array<string, mixed> $props */
    private function markup(array $props): string
    {
        $active = (string) ($props['activeNav'] ?? 'sessions');
        $sessions = \is_array($props['sessions'] ?? null) ? $props['sessions'] : [];
        $current = (string) ($props['activeSession'] ?? '');
        $chrome = ($props['chrome'] ?? true) !== false;

        // A DECLARED VIEW (greenhouse decisions/0211): the look is `desktop-sidebar.css`, the behaviour is the
        // `desktopSidebar` factory of `desktop-sidebar.js` — navigation, the session search, «New session»
        // and the passkey probe, none of them written into the page.
        return '<nav class="mui-sidebar milpa-sidebar" aria-label="main" data-milpa-runtime="alpine" data-milpa-component="desktop-sidebar" data-milpa-component-id="' . self::COMPONENT_ID . '"'
            . ' x-data="desktopSidebar({ active: \'' . htmlspecialchars($active, ENT_QUOTES) . '\' })">'
            . $this->brand()
            . '<div class="mui-sidebar__nav"><div class="mui-sidebar__section">' . $this->navItems($active, (int) ($props['decisions'] ?? 0), $chrome) . '</div>'
            . '<div class="mui-sidebar__section" id="milpa-sessions"><span class="mui-sidebar__section-label">sessions · goal and state</span>' . $this->sessionsList($sessions, $current) . '</div></div>'
            . '<div class="mui-sidebar__footer milpa-sidebar__footer">'
            . '<button type="button" class="mui-btn mui-btn--subtle mui-btn--full" id="milpa-new-session" @click="newSession()">New session</button>'
            . '<a class="mui-btn mui-btn--ghost mui-btn--sm mui-btn--full" id="milpa-enroll-link" href="/webauthn/enroll" @click.prevent="enroll($event)">Register a passkey</a>'
            . '</div></nav>';
    }

    /**
     * The nav items — all of them, or only the sessions item when the chrome is folded (embed mode,
     * greenhouse decisions/0210): a link that would open a chrome screen is not shown inside the host.
     */
    private function navItems(string $active, int $decisions = 0, bool $chrome = true): string
    {
        $out = '';
        foreach (self::NAV as $item) {
            $key = $item['key'];
            if (!$chrome && $key !== 'sessions') {
                continue;
            }
            // The Decisions item carries a count badge when questions are parked (greenhouse decisions/0195):
            // the human sees there is a decision waiting without opening the pane. Hidden at zero.
            $badge = ($key === 'decisions' && $decisions > 0)
                ? '<span class="mui-sidebar__item-badge mui-badge mui-badge--warning">' . $decisions . '</span>'
                : '';
            // The active nav is the shared `desktop.nav` signal: the component's own factory sets it (instant
            // highlight) AND swaps the view the same key names, and aria-current tracks the signal — one
            // truth, and the page no longer hangs a click handler on every item.
            $out .= sprintf(
                '<a class="mui-sidebar__item" href="#" data-nav="%s"%s @click.prevent="go(\'%s\')" :aria-current="isCurrent(\'%s\') ? \'page\' : null"><span class="mui-sidebar__item-icon">%s</span><span class="mui-sidebar__item-label">%s</span>%s</a>',
                $key,
                $key === $active ? ' aria-current="page"' : '',
                $key,
                $key,
                $item['icon'],
                htmlspecialchars($item['label'], ENT_QUOTES),
                $badge,
            );
        }

        return $out;
    }

    /** @param list<array{id: string, goal: string, state: string}>|array<int, mixed> $sessions */
    private function sessionsList(array $sessions, string $current): string
    {
        if ($sessions === []) {
            return '<p class="mui-empty milpa-sidebar__empty">No sessions yet. Open a workspace to start one.</p>';
        }
        $out = '';
        foreach ($sessions as $s) {
            if (!\is_array($s)) {
                continue;
            }
            $id = (string) ($s['id'] ?? '');
            $out .= sprintf(
                '<a class="mui-sidebar__item milpa-session-item" data-session-id="%s" href="?session=%s"%s><span class="milpa-session-goal">%s</span><span class="mui-badge">%s</span></a>',
                htmlspecialchars($id, ENT_QUOTES),
                rawurlencode($id),
                $id === $current ? ' aria-current="page"' : '',
                htmlspecialchars((string) ($s['goal'] ?? ''), ENT_QUOTES),
                htmlspecialchars((string) ($s['state'] ?? ''), ENT_QUOTES),
            );
        }

        return $out;
    }

    private function brand(): string
    {
        $grains = '';
        foreach (self::GRAIN as $i => [$x, $y]) {
            // The stagger is per-kernel data, not a rule: it stays inline, the look of the mark does not.
            $grains .= sprintf('<rect class="g" x="%s" y="%s" width="10" height="10" rx="2.5" style="animation-delay:%ss"/>', $x, $y, $i * 0.045);
        }

        return '<span class="mui-sidebar__brand milpa-sidebar__brand">'
            . '<svg class="milpa-grainmark" viewBox="0 0 60 60" width="26" height="26" role="img" aria-label="Milpa"><g fill="#E8B14C">' . $grains . '</g></svg>'
            . '<span class="mui-sidebar__wordmark">Milpa</span></span>';
    }

    private function envelope(\Milpa\Live\ValueObjects\StateSnapshot $state): string
    {
        return '<script type="application/milpa+xhtml" data-milpa-state="' . self::COMPONENT_ID . '">' . $this->codec->encodeState($state) . '</script>';
    }
}

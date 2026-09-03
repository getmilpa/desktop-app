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
use Milpa\Live\ValueObjects\StateSnapshot;

/**
 * Renders the Desktop shell's topbar as a {@see TopbarComponent} — the second surface of the "shell is pure
 * Milpa Components" migration (greenhouse decisions/0189). It mounts the component, produces the design-system
 * header (session goal/id/mode on the left; live state, mode and Export on the right), carries the signed state
 * envelope, binds the shared signals, and emits `desktop.topbar.before_render` / `after_render` so other
 * plugins can extend it.
 */
final class Topbar
{
    public const string COMPONENT_ID = 'topbar';
    public const string BEFORE_RENDER = 'desktop.topbar.before_render';
    public const string AFTER_RENDER = 'desktop.topbar.after_render';

    /** @var array<string, string> */
    private const MODE_LABELS = ['ask' => 'Ask before changing', 'acknowledge' => 'Compatibility', 'auto' => 'Continue automatically'];

    private readonly SignedXhtmlStateTransferCodec $codec;

    public function __construct(
        string $signingSecret,
        private readonly ?DesktopData $data = null,
        private readonly ?MilpaEventDispatcherInterface $events = null,
    ) {
        $this->codec = new SignedXhtmlStateTransferCodec(new XhtmlStateTransferCodec(), new HmacStateSigner($signingSecret), null);
    }

    /** The topbar's server-rendered HTML — a component with its signed envelope and signal-bound badges. */
    public function render(): string
    {
        $component = new TopbarComponent();
        $subject = new ComposerRender($this->props());
        $this->events?->dispatch(self::BEFORE_RENDER, ['topbar' => $subject]);

        $context = new ComponentContext(componentId: self::COMPONENT_ID);
        $state = $component->mount($subject->props, $context);
        $subject->html = $this->markup($subject->props) . $this->envelope($state);

        $this->events?->dispatch(self::AFTER_RENDER, ['topbar' => $subject]);

        return $subject->html;
    }

    /** @return array<string, mixed> */
    private function props(): array
    {
        $id = $this->data?->currentSessionId() ?? '';
        $goal = '';
        foreach ($this->data?->sessions() ?? [] as $s) {
            if ((string) $s['id'] === $id) {
                $goal = (string) $s['goal'];

                break;
            }
        }
        $settings = $this->data?->settings() ?? [];
        $modeKey = \is_string($settings['mode'] ?? null) && $settings['mode'] !== '' ? (string) $settings['mode'] : 'ask';
        $counters = $this->data?->counters();
        $state = $counters !== null ? (string) $counters['state'] : 'idle';

        return [
            'goal' => $goal,
            'sessionId' => $id,
            'mode' => self::MODE_LABELS[$modeKey] ?? 'Ask before changing',
            'modeKey' => $modeKey,
            'state' => $state,
            'hasSession' => $id !== '',
        ];
    }

    /** @param array<string, mixed> $props */
    private function markup(array $props): string
    {
        return '<header class="mui-topbar" data-milpa-runtime="alpine" data-milpa-component="desktop-topbar" data-milpa-component-id="' . self::COMPONENT_ID . '" x-data style="grid-row:1;grid-column:2;min-height:64px">'
            . '<div class="mui-topbar__start" style="flex-direction:column;align-items:flex-start;gap:2px">' . $this->header($props) . '</div>'
            . '<div class="mui-topbar__end">' . $this->actions($props) . '</div>'
            . '</header>';
    }

    /** @param array<string, mixed> $props */
    private function header(array $props): string
    {
        if (($props['hasSession'] ?? false) === false) {
            return '<span style="font-size:var(--text-base);font-weight:var(--weight-medium)">No session open</span>'
                . '<span style="font-family:var(--font-mono);font-size:var(--text-2xs);color:var(--text-muted)">Open a workspace to start one</span>';
        }

        return sprintf(
            '<span style="font-size:var(--text-base);font-weight:var(--weight-medium)">%s</span>'
            // The derived `session.summary` signal ("{state} · {turns} turns") re-computes reactively.
            . '<span style="font-family:var(--font-mono);font-size:var(--text-2xs);color:var(--text-muted)">immutable goal · session %s · %s mode · <span x-text="$store.milpa[\'session.summary\']"></span></span>',
            htmlspecialchars(($props['goal'] ?? '') !== '' ? (string) $props['goal'] : '(no goal recorded)', ENT_QUOTES),
            htmlspecialchars((string) ($props['sessionId'] ?? ''), ENT_QUOTES),
            htmlspecialchars((string) ($props['modeKey'] ?? 'ask'), ENT_QUOTES),
        );
    }

    /** @param array<string, mixed> $props */
    private function actions(array $props): string
    {
        $state = (string) ($props['state'] ?? 'idle');
        $working = $state === 'working';
        $id = (string) ($props['sessionId'] ?? '');
        $href = '/desktop/export' . ($id !== '' ? '?session=' . rawurlencode($id) : '');

        return sprintf(
            // The session state is a SHARED signal — the badge reads it (a panel can read the same).
            '<span class="%s" id="milpa-topstate" x-text="$store.milpa[\'session.state.label\']">%s</span>'
            // The mode is a SHARED signal: this badge and the composer's chip read one truth (decisions/0189).
            . '<span class="mui-badge" x-text="$store.milpa[\'composer.mode.label\']">%s</span>'
            . '<a class="mui-btn mui-btn--sm" id="milpa-export" href="%s" download>Export session</a>',
            $working ? 'mui-badge mui-badge--accent mui-badge--dot' : 'mui-badge',
            htmlspecialchars(ucfirst($state), ENT_QUOTES),
            htmlspecialchars((string) ($props['mode'] ?? 'Ask before changing'), ENT_QUOTES),
            htmlspecialchars($href, ENT_QUOTES),
        );
    }

    private function envelope(StateSnapshot $state): string
    {
        return '<script type="application/milpa+xhtml" data-milpa-state="' . self::COMPONENT_ID . '">' . $this->codec->encodeState($state) . '</script>';
    }
}

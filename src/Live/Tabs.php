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

use Milpa\Interfaces\Event\MilpaEventDispatcherInterface;
use Milpa\Live\Security\HmacStateSigner;
use Milpa\Live\Security\SignedXhtmlStateTransferCodec;
use Milpa\Live\Transport\XhtmlStateTransferCodec;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\StateSnapshot;

/**
 * Renders the Desktop shell's tablist as a {@see TabsComponent} — the third surface of the "shell is pure Milpa
 * Components" migration (greenhouse decisions/0189). It mounts the component, produces the design-system
 * tablist, binds each tab to the shared `desktop.tab` signal (click sets it, aria-selected tracks it), carries
 * the signed state envelope, and emits `desktop.tabs.before_render` / `after_render` so plugins can extend it.
 *
 * The panes below and the composer dock read the same `desktop.tab` signal to show/hide — no imperative JS.
 */
final class Tabs
{
    public const string COMPONENT_ID = 'tabs';
    public const string BEFORE_RENDER = 'desktop.tabs.before_render';
    public const string AFTER_RENDER = 'desktop.tabs.after_render';

    /** @var list<array{key: string, label: string}> */
    private const TABS = [
        ['key' => 'chat', 'label' => 'Conversation'],
        ['key' => 'work', 'label' => 'Work'],
        ['key' => 'activity', 'label' => 'Activity'],
        ['key' => 'context', 'label' => 'Context'],
    ];

    private readonly SignedXhtmlStateTransferCodec $codec;

    public function __construct(
        string $signingSecret,
        private readonly ?MilpaEventDispatcherInterface $events = null,
    ) {
        $this->codec = new SignedXhtmlStateTransferCodec(new XhtmlStateTransferCodec(), new HmacStateSigner($signingSecret), null);
    }

    /** The tablist's server-rendered HTML — a component with its signed envelope and a signal-driven active tab. */
    public function render(): string
    {
        $component = new TabsComponent();
        $props = ['tabs' => self::TABS, 'activeTab' => 'chat'];
        $subject = new ComposerRender($props);
        $this->events?->dispatch(self::BEFORE_RENDER, ['tabs' => $subject]);

        $context = new ComponentContext(componentId: self::COMPONENT_ID);
        $state = $component->mount($subject->props, $context);
        $subject->html = $this->markup($subject->props) . $this->envelope($state);

        $this->events?->dispatch(self::AFTER_RENDER, ['tabs' => $subject]);

        return $subject->html;
    }

    /** @param array<string, mixed> $props */
    private function markup(array $props): string
    {
        $active = (string) ($props['activeTab'] ?? 'chat');
        /** @var list<array{key: string, label: string}> $tabs */
        $tabs = \is_array($props['tabs'] ?? null) ? $props['tabs'] : self::TABS;

        return '<div class="mui-tabs" role="tablist" data-milpa-runtime="alpine" data-milpa-component="desktop-tabs" data-milpa-component-id="' . self::COMPONENT_ID . '" x-data style="padding:0 var(--space-6);flex:none">'
            . $this->tabButtons($tabs, $active)
            . '</div>';
    }

    /**
     * @param list<array{key: string, label: string}> $tabs
     */
    private function tabButtons(array $tabs, string $active): string
    {
        $out = '';
        foreach ($tabs as $tab) {
            $key = $tab['key'];
            // The active tab is the shared `desktop.tab` signal: click sets it (instant switch), the panes and
            // the composer dock read the same signal, and aria-selected tracks it — one truth, no imperative JS.
            $out .= sprintf(
                '<button class="mui-tabs__tab" role="tab" type="button" data-tab="%s" @click="$store.milpa[\'%s\'] = \'%s\'" :aria-selected="$store.milpa[\'%s\'] === \'%s\'" aria-selected="%s">%s</button>',
                $key,
                TabsComponent::TAB_SIGNAL,
                $key,
                TabsComponent::TAB_SIGNAL,
                $key,
                $key === $active ? 'true' : 'false',
                htmlspecialchars($tab['label'], ENT_QUOTES),
            );
        }

        return $out;
    }

    private function envelope(StateSnapshot $state): string
    {
        return '<script type="application/milpa+xhtml" data-milpa-state="' . self::COMPONENT_ID . '">' . $this->codec->encodeState($state) . '</script>';
    }
}

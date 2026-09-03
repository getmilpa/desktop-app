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
 * Renders the Desktop shell's Activity tab as an {@see ActivityComponent} — the fifth surface of the "shell is
 * pure Milpa Components" migration (greenhouse decisions/0189). It mounts the component, produces the
 * design-system content (the live fact stream `<ol id="milpa-activity">` and the counter projection `<aside>`),
 * carries the signed state envelope, and emits `desktop.activity.before_render` / `after_render` so plugins can
 * extend it.
 *
 * The wrapper uses `display:contents` so its two children are direct grid items of the activity pane. New facts
 * arrive LIVE over the hub and are prepended to `#milpa-activity` by the shell's event client — unchanged.
 */
final class Activity
{
    public const string COMPONENT_ID = 'activity';
    public const string BEFORE_RENDER = 'desktop.activity.before_render';
    public const string AFTER_RENDER = 'desktop.activity.after_render';

    private readonly SignedXhtmlStateTransferCodec $codec;

    public function __construct(
        string $signingSecret,
        private readonly ?DesktopData $data = null,
        private readonly ?MilpaEventDispatcherInterface $events = null,
    ) {
        $this->codec = new SignedXhtmlStateTransferCodec(new XhtmlStateTransferCodec(), new HmacStateSigner($signingSecret), null);
    }

    /** The Activity tab's server-rendered HTML — a component with its signed envelope and the live stream. */
    public function render(): string
    {
        $component = new ActivityComponent();
        $props = [
            'audit' => $this->data?->audit() ?? [],
            'projection' => $this->projectionStats(),
        ];
        $subject = new ComposerRender($props);
        $this->events?->dispatch(self::BEFORE_RENDER, ['activity' => $subject]);

        $context = new ComponentContext(componentId: self::COMPONENT_ID);
        $state = $component->mount($subject->props, $context);
        $subject->html = $this->markup($subject->props) . $this->envelope($state);

        $this->events?->dispatch(self::AFTER_RENDER, ['activity' => $subject]);

        return $subject->html;
    }

    /** @param array<string, mixed> $props */
    private function markup(array $props): string
    {
        return '<div data-milpa-component="desktop-activity" data-milpa-component-id="' . self::COMPONENT_ID . '" style="display:contents">'
            . '<div>'
            . '<p style="color:var(--text-secondary);font-size:var(--text-sm);margin:0 0 var(--space-4)">A projection of the session\'s facts — not a full audit log. Live over the hub.</p>'
            . '<ol class="mui-replay__stream" id="milpa-activity" aria-live="polite">' . $this->stream($props) . '</ol>'
            . '</div>'
            . '<aside class="mui-replay__projection">' . $this->projection($props) . '</aside>'
            . '</div>';
    }

    /** @param array<string, mixed> $props */
    private function stream(array $props): string
    {
        /** @var list<array{seq: int, type: string, data: string}> $audit */
        $audit = \is_array($props['audit'] ?? null) ? $props['audit'] : [];
        if ($audit === []) {
            return '<li class="mui-replay__event"><span class="mui-replay__actor">no facts recorded yet</span></li>';
        }

        $out = '';
        foreach ($audit as $fact) {
            $out .= sprintf(
                '<li class="mui-replay__event"><span class="mui-replay__type">%s</span> <span class="mui-replay__actor">%s · seq %d</span></li>',
                htmlspecialchars($fact['type'], ENT_QUOTES),
                htmlspecialchars($fact['data'], ENT_QUOTES),
                $fact['seq'],
            );
        }

        return $out;
    }

    /** @param array<string, mixed> $props */
    private function projection(array $props): string
    {
        /** @var array<string, int|string> $stats */
        $stats = \is_array($props['projection'] ?? null) ? $props['projection'] : [];
        $out = '';
        foreach ($stats as $label => $value) {
            $out .= sprintf(
                '<p class="mui-replay__stat"><span class="mui-replay__stat-label">%s</span><span class="mui-replay__stat-value">%s</span></p>',
                htmlspecialchars($label, ENT_QUOTES),
                htmlspecialchars((string) $value, ENT_QUOTES),
            );
        }

        return $out;
    }

    /** @return array<string, int|string> */
    private function projectionStats(): array
    {
        $c = $this->data?->counters() ?? ['turns' => 0, 'steps' => 0, 'tokens' => 0, 'tool_calls' => 0, 'state' => 'idle'];

        return [
            'state' => $c['state'], 'turns' => $c['turns'], 'steps' => $c['steps'],
            'tokens' => $c['tokens'], 'tool calls' => $c['tool_calls'],
        ];
    }

    private function envelope(StateSnapshot $state): string
    {
        return '<script type="application/milpa+xhtml" data-milpa-state="' . self::COMPONENT_ID . '">' . $this->codec->encodeState($state) . '</script>';
    }
}

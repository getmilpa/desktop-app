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
use Milpa\Live\Security\SignedXhtmlStateTransferCodec;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\StateSnapshot;

/**
 * Renders the {@see StatusBarComponent} — the shell's bottom bar (greenhouse decisions/0211, phase D4).
 *
 * Three bindings and one fact. The connection binds `conn.label` and colours itself off `conn.state` —
 * the signals `MilpaShell.status()` writes, so the transport reports its state ONCE and every surface
 * that shows it binds instead of being poked by id. The counters bind the computed `session.status`
 * signal, the same truth the composer chips and the panels project. The model comes from the app's real
 * configuration ({@see DesktopData::model()}), not from a string typed into the template.
 *
 * Every span carries a SERVER-RENDERED seed, so the bar reads correctly before Alpine hydrates it.
 */
final class StatusBar
{
    public const string COMPONENT_ID = 'statusbar';

    /** Dispatched with a mutable {@see ComposerRender} BEFORE the render — a subscriber may change its props. */
    public const string BEFORE_RENDER = 'desktop.statusbar.before_render';

    /** Dispatched with a mutable {@see ComposerRender} AFTER the render — a subscriber may change its html. */
    public const string AFTER_RENDER = 'desktop.statusbar.after_render';

    /** The host this Desktop is, as its own identity line — the product's name, not copy. */
    private const string HOST = 'm4-core local-agent · v0.1.0';

    public function __construct(
        private readonly SignedXhtmlStateTransferCodec $codec,
        private readonly ?DesktopData $data = null,
        private readonly ?MilpaEventDispatcherInterface $events = null,
        private readonly ?Catalog $catalog = null,
    ) {
    }

    /** The bar, with its signed envelope, after the render events a plugin may extend it through. */
    public function render(): string
    {
        $subject = new ComposerRender(['model' => $this->data?->model()['model'] ?? 'qwen3.8-27b']);
        $this->events?->dispatch(self::BEFORE_RENDER, ['statusbar' => $subject]);

        $state = (new StatusBarComponent())->mount($subject->props, new ComponentContext(componentId: self::COMPONENT_ID));
        $subject->html = $this->markup($subject->props) . $this->envelope($state);

        $this->events?->dispatch(self::AFTER_RENDER, ['statusbar' => $subject]);

        return $subject->html;
    }

    /** @param array<string, mixed> $props */
    private function markup(array $props): string
    {
        $catalog = $this->catalog ?? new Catalog();
        $model = \is_string($props['model'] ?? null) && $props['model'] !== '' ? (string) $props['model'] : 'qwen3.8-27b';
        $counters = $this->data?->counters() ?? ['turns' => 0, 'steps' => 0, 'tokens' => 0, 'tool_calls' => 0, 'state' => 'idle'];
        $seed = \sprintf('%d turns · %d steps · %d tokens · %d tool calls', $counters['turns'], $counters['steps'], $counters['tokens'], $counters['tool_calls']);

        return '<div class="statusbar" data-milpa-component="desktop-statusbar" data-milpa-component-id="' . self::COMPONENT_ID . '">'
            . '<span class="statusbar__conn" x-data :class="{ \'statusbar__conn--live\': $store.milpa[\'conn.state\'] === \'live\' }" x-text="$store.milpa[\'conn.label\']">'
            . htmlspecialchars($catalog->tr('conn.connecting'), ENT_QUOTES) . '</span>'
            . '<span class="statusbar__model">' . htmlspecialchars($catalog->tr('statusbar.model', $model), ENT_QUOTES) . '</span>'
            . '<span class="statusbar__counters" x-data x-text="$store.milpa[\'session.status\']">' . htmlspecialchars($seed, ENT_QUOTES) . '</span>'
            . '<span class="statusbar__host">' . htmlspecialchars(self::HOST, ENT_QUOTES) . '</span>'
            . '</div>';
    }

    private function envelope(StateSnapshot $state): string
    {
        return '<script type="application/milpa+xhtml" data-milpa-state="' . self::COMPONENT_ID . '">' . $this->codec->encodeState($state) . '</script>';
    }
}

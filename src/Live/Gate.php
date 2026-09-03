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
 * Renders the Desktop shell's consent gate as a {@see GateComponent} — the seventh and last surface of the
 * "shell is pure Milpa Components" migration (greenhouse decisions/0189). It mounts the component, produces the
 * design-system gate card (hidden until an agent parks a question), binds its visibility to the shared
 * `desktop.gate.open` signal, carries the signed state envelope, and emits `desktop.gate.before_render` /
 * `after_render` so plugins can extend it.
 *
 * The `data-gate-*` hooks are preserved: the live `gate.opened` event fills the operation/arguments/action and
 * the passkey link, and sets the signal open — that live transport is unchanged.
 */
final class Gate
{
    public const string COMPONENT_ID = 'gate';
    public const string BEFORE_RENDER = 'desktop.gate.before_render';
    public const string AFTER_RENDER = 'desktop.gate.after_render';

    private readonly SignedXhtmlStateTransferCodec $codec;

    public function __construct(
        string $signingSecret,
        private readonly ?MilpaEventDispatcherInterface $events = null,
    ) {
        $this->codec = new SignedXhtmlStateTransferCodec(new XhtmlStateTransferCodec(), new HmacStateSigner($signingSecret), null);
    }

    /** The gate's server-rendered HTML — a component hidden until the `desktop.gate.open` signal is true. */
    public function render(): string
    {
        $component = new GateComponent();
        $subject = new ComposerRender(['open' => false]);
        $this->events?->dispatch(self::BEFORE_RENDER, ['gate' => $subject]);

        $context = new ComponentContext(componentId: self::COMPONENT_ID);
        $state = $component->mount($subject->props, $context);
        $subject->html = $this->markup() . $this->envelope($state);

        $this->events?->dispatch(self::AFTER_RENDER, ['gate' => $subject]);

        return $subject->html;
    }

    private function markup(): string
    {
        // The gate is hidden by default (server) and its visibility is the shared `desktop.gate.open` signal:
        // the live gate.opened event sets it true after filling the fields, dismiss sets it false — one truth.
        return '<div class="mui-card mui-card--raised" id="milpa-gate" data-milpa-component="desktop-gate" data-milpa-component-id="' . self::COMPONENT_ID . '" x-data hidden'
            . ' :hidden="!$store.milpa[\'' . GateComponent::OPEN_SIGNAL . '\']" style="border-color:var(--warning-border);background:var(--warning-bg)">'
            . '<div class="mui-card__body mui-gate">'
            . '<div class="mui-gate__request">'
            . '<p class="mui-gate__actor" style="margin:0">an agent stopped its turn · a durable question, not a modal</p>'
            . '<p class="mui-gate__action" style="margin:var(--space-2) 0;font-size:var(--text-base)" data-gate-action>An agent is asking to act.</p>'
            . '<p class="mui-gate__facts" style="margin:0">operation <strong data-gate-op></strong> · arguments <code data-gate-args></code></p>'
            . '</div>'
            . '<div class="mui-gate__decisions">'
            . '<a class="mui-btn mui-btn--primary" data-gate-approve href="#">Approve with passkey</a>'
            . '<button type="button" class="mui-btn" data-gate-dismiss @click="$store.milpa[\'' . GateComponent::OPEN_SIGNAL . '\'] = false">Dismiss</button>'
            . '</div>'
            . '<p style="margin:0;font-family:var(--font-mono);font-size:var(--text-2xs);color:var(--text-muted)">Answering keeps your answer; it does not resume the session. Continuing is another verb.</p>'
            . '</div></div>';
    }

    private function envelope(StateSnapshot $state): string
    {
        return '<script type="application/milpa+xhtml" data-milpa-state="' . self::COMPONENT_ID . '">' . $this->codec->encodeState($state) . '</script>';
    }
}

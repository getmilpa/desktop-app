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
 * Renders the {@see ThinkingComponent} as a PROTOTYPE for the conversation (greenhouse decisions/0191): the
 * server mounts+renders it once (firing `desktop.thinking.before_render` / `after_render` so a plugin can
 * extend the markup), and the shell puts the result in a `<template>`. The live feed clones the prototype per
 * turn and feeds this instance: the reasoning into `[data-thinking-body]`, the elapsed into
 * `[data-thinking-head]`. The block's collapse behaviour is its own, declared in the markup + CSS (the
 * `data-open` attribute drives it; a single delegated toggle on the conversation flips it) — not per-instance
 * imperative JS.
 */
final class Thinking
{
    public const string COMPONENT_ID = 'thinking';
    public const string BEFORE_RENDER = 'desktop.thinking.before_render';
    public const string AFTER_RENDER = 'desktop.thinking.after_render';

    private readonly SignedXhtmlStateTransferCodec $codec;

    public function __construct(
        string $signingSecret,
        private readonly ?MilpaEventDispatcherInterface $events = null,
    ) {
        $this->codec = new SignedXhtmlStateTransferCodec(new XhtmlStateTransferCodec(), new HmacStateSigner($signingSecret), null);
    }

    /** The thinking component's server-rendered prototype — declarative Alpine behaviour + a signed envelope. */
    public function render(): string
    {
        $component = new ThinkingComponent();
        $subject = new ComposerRender([]);
        $this->events?->dispatch(self::BEFORE_RENDER, ['thinking' => $subject]);

        $context = new ComponentContext(componentId: self::COMPONENT_ID);
        $state = $component->mount($subject->props, $context);
        $subject->html = $this->markup() . $this->envelope($state);

        $this->events?->dispatch(self::AFTER_RENDER, ['thinking' => $subject]);

        return $subject->html;
    }

    private function markup(): string
    {
        // The block IS the component: Alpine owns open/done/elapsed and the streaming body. The live feed only
        // supplies words — `thinking:delta` appends to `text`, `thinking:done` collapses and stamps the elapsed.
        // The block's behaviour is its own, declared in the markup + CSS: the collapse is driven by the
        // `data-open` attribute (`.milpa-think[data-open="0"] .milpa-think__body { display:none }`), and a
        // single delegated toggle handler on the conversation flips it — no per-instance imperative JS, and no
        // reliance on Alpine hydrating a dynamically-cloned node (which double-inits in this environment). The
        // conversation feeds this instance: the reasoning into `[data-thinking-body]`, the elapsed into
        // `[data-thinking-head]`. A server-rendered, contract'd, event-emitting component the shell clones.
        // `data-thinking-active="1"` is the LIVE flag the animation keys off (a breathing spark + typing dots +
        // an accent edge): the model is still reasoning. `endReasoning` flips it to "0" and the block settles.
        // The label lives in its OWN span so the elapsed («thought for Ns») replaces only the words, never the
        // animated spark/dots — those are the component's, not the feed's.
        return '<div class="msg msg--thinking milpa-think" data-milpa-component="desktop-thinking" data-milpa-component-id="' . self::COMPONENT_ID . '" data-open="1" data-thinking-active="1">'
            . '<button type="button" class="milpa-think__toggle" data-thinking-toggle data-thinking-head>'
            . '<span class="milpa-think__spark" data-thinking-spark aria-hidden="true">◈</span>'
            . '<span class="milpa-think__label" data-thinking-label>thinking</span>'
            . '<span class="milpa-think__dots" aria-hidden="true"><i></i><i></i><i></i></span>'
            . '</button>'
            . '<div class="milpa-think__body" data-thinking-body></div>'
            . '</div>';
    }

    private function envelope(StateSnapshot $state): string
    {
        return '<script type="application/milpa+xhtml" data-milpa-state="' . self::COMPONENT_ID . '">' . $this->codec->encodeState($state) . '</script>';
    }
}

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
 * Renders the {@see AgentMessageComponent} as a PROTOTYPE for the conversation (greenhouse decisions/0191):
 * the server mounts+renders it once (firing `desktop.agent_message.before_render` / `after_render` so a plugin
 * can add tools to EVERY agent message), and the shell puts the result in a `<template>`. The conversation
 * clones it per answer, fills the answer into `[data-agent-body]`, and the foot tools (copy, regenerate) act
 * through one delegated handler on the conversation.
 */
final class AgentMessage
{
    public const string COMPONENT_ID = 'agent-message';
    public const string BEFORE_RENDER = 'desktop.agent_message.before_render';
    public const string AFTER_RENDER = 'desktop.agent_message.after_render';

    private readonly SignedXhtmlStateTransferCodec $codec;

    public function __construct(
        string $signingSecret,
        private readonly ?MilpaEventDispatcherInterface $events = null,
    ) {
        $this->codec = new SignedXhtmlStateTransferCodec(new XhtmlStateTransferCodec(), new HmacStateSigner($signingSecret), null);
    }

    /** The agent-message component's server-rendered prototype — the answer body and its foot tools. */
    public function render(): string
    {
        $component = new AgentMessageComponent();
        $subject = new ComposerRender([]);
        $this->events?->dispatch(self::BEFORE_RENDER, ['agentMessage' => $subject]);

        $context = new ComponentContext(componentId: self::COMPONENT_ID);
        $state = $component->mount($subject->props, $context);
        $subject->html = $this->markup() . $this->envelope($state);

        $this->events?->dispatch(self::AFTER_RENDER, ['agentMessage' => $subject]);

        return $subject->html;
    }

    private function markup(): string
    {
        // The answer, then its tools at the foot (Rod's ask): copy and regenerate. The tools act through one
        // delegated handler on the conversation, so a cloned instance needs no per-instance wiring.
        return '<div class="msg msg--agent" data-milpa-component="desktop-agent-message" data-milpa-component-id="' . self::COMPONENT_ID . '">'
            . '<span class="msg__meta">agent · local</span>'
            . '<p data-agent-body></p>'
            . '<div class="msg__tools" role="group" aria-label="message tools">'
            . $this->tool('data-agent-copy', 'Copy', $this->copyIcon())
            . $this->tool('data-agent-regenerate', 'Regenerate', $this->regenerateIcon())
            . '</div>'
            . '</div>';
    }

    private function tool(string $hook, string $label, string $icon): string
    {
        return sprintf(
            '<button type="button" class="msg__tool-btn" %s title="%s" aria-label="%s">%s</button>',
            $hook,
            htmlspecialchars($label, ENT_QUOTES),
            htmlspecialchars($label . ' response', ENT_QUOTES),
            $icon,
        );
    }

    private function copyIcon(): string
    {
        return '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . '<rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/></svg>';
    }

    private function regenerateIcon(): string
    {
        return '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . '<path d="M21 12a9 9 0 1 1-2.64-6.36"/><path d="M21 3v5h-5"/></svg>';
    }

    private function envelope(StateSnapshot $state): string
    {
        return '<script type="application/milpa+xhtml" data-milpa-state="' . self::COMPONENT_ID . '">' . $this->codec->encodeState($state) . '</script>';
    }
}

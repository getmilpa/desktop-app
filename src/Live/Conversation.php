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
 * Renders the {@see ConversationComponent}'s inner content (greenhouse decisions/0191): the empty state and the
 * signed envelope, firing `desktop.conversation.before_render` / `after_render` so a plugin can extend the
 * thread. The `#milpa-chat` element carries the component's marker; this fills it. The empty state hides itself
 * once a message component is cloned in (`.milpa-chat:has(.msg) .milpa-empty-convo { display:none }`).
 */
final class Conversation
{
    public const string COMPONENT_ID = 'conversation';
    public const string BEFORE_RENDER = 'desktop.conversation.before_render';
    public const string AFTER_RENDER = 'desktop.conversation.after_render';

    private readonly SignedXhtmlStateTransferCodec $codec;

    public function __construct(
        string $signingSecret,
        private readonly ?MilpaEventDispatcherInterface $events = null,
    ) {
        $this->codec = new SignedXhtmlStateTransferCodec(new XhtmlStateTransferCodec(), new HmacStateSigner($signingSecret), null);
    }

    /** The conversation's inner content — the empty state and its signed envelope. */
    public function render(): string
    {
        $component = new ConversationComponent();
        $subject = new ComposerRender(['empty' => true]);
        $this->events?->dispatch(self::BEFORE_RENDER, ['conversation' => $subject]);

        $context = new ComponentContext(componentId: self::COMPONENT_ID);
        $state = $component->mount($subject->props, $context);
        $subject->html = $this->markup() . $this->envelope($state);

        $this->events?->dispatch(self::AFTER_RENDER, ['conversation' => $subject]);

        return $subject->html;
    }

    private function markup(): string
    {
        return '<p class="milpa-empty-convo" style="color:var(--text-muted);font-size:var(--text-sm)">'
            . 'No messages yet — write to the session to begin.</p>';
    }

    private function envelope(StateSnapshot $state): string
    {
        return '<script type="application/milpa+xhtml" data-milpa-state="' . self::COMPONENT_ID . '">' . $this->codec->encodeState($state) . '</script>';
    }
}

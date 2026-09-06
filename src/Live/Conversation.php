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
use Milpa\Live\Security\HmacStateSigner;
use Milpa\Live\Security\SignedXhtmlStateTransferCodec;
use Milpa\Live\Transport\XhtmlStateTransferCodec;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\StateSnapshot;

/**
 * Renders the {@see ConversationComponent}'s inner content (greenhouse decisions/0191): the interrupted-run
 * notice, the empty state and the signed envelope, firing `desktop.conversation.before_render` /
 * `after_render` so a plugin can extend the thread. The `#milpa-chat` element carries the component's marker;
 * this fills it. The empty state hides itself once a message component is cloned in
 * (`.milpa-chat:has(.msg) .milpa-empty-convo { display:none }`).
 *
 * THE INTERRUPTED NOTICE (greenhouse decisions/0196) is rendered HERE, not by the shell. A fresh page load
 * has no live run of its own, so a session whose recorded state is still a running one is a run that did not
 * finish — reported, never silently auto-resumed (the lesson of greenhouse decisions/0132). The shell used to
 * hand-write it into the thread while its stylesheet lived in `desktop-conversation.css`: the one rule in
 * the slice whose declaring renderer did not own its markup, and the one user-facing sentence phase D moved
 * without giving it a catalog key. Both are closed by moving the markup to the renderer that owns the rule.
 */
final class Conversation
{
    public const string COMPONENT_ID = 'conversation';

    /** The session states that mean a run was still going when the page was left (decisions/0196). */
    private const array RUNNING = ['working', 'thinking', 'running', 'busy'];
    public const string BEFORE_RENDER = 'desktop.conversation.before_render';
    public const string AFTER_RENDER = 'desktop.conversation.after_render';

    private readonly SignedXhtmlStateTransferCodec $codec;

    private readonly Catalog $catalog;

    public function __construct(
        string $signingSecret,
        private readonly ?MilpaEventDispatcherInterface $events = null,
        private readonly ?DesktopData $data = null,
        ?Catalog $catalog = null,
    ) {
        $this->codec = new SignedXhtmlStateTransferCodec(new XhtmlStateTransferCodec(), new HmacStateSigner($signingSecret), null);
        $this->catalog = $catalog ?? new Catalog();
    }

    /** The conversation's inner content — the empty state and its signed envelope. */
    public function render(): string
    {
        $component = new ConversationComponent();
        $subject = new ComposerRender(['empty' => true, 'interrupted' => $this->interrupted()]);
        $this->events?->dispatch(self::BEFORE_RENDER, ['conversation' => $subject]);

        $context = new ComponentContext(componentId: self::COMPONENT_ID);
        $state = $component->mount($subject->props, $context);
        $subject->html = $this->markup($subject->props) . $this->envelope($state);

        $this->events?->dispatch(self::AFTER_RENDER, ['conversation' => $subject]);

        return $subject->html;
    }

    /** Whether the session this page opened on was left mid-run. Empty data is a settled session. */
    private function interrupted(): bool
    {
        return \in_array(strtolower($this->data?->counters()['state'] ?? ''), self::RUNNING, true);
    }

    /** @param array<string, mixed> $props */
    private function markup(array $props): string
    {
        $notice = ($props['interrupted'] ?? false) === true
            ? '<div class="milpa-interrupted" role="note">'
                . '<span class="milpa-interrupted__mark" aria-hidden="true">⚠</span>'
                . '<span>' . htmlspecialchars($this->catalog->tr('conversation.interrupted'), ENT_QUOTES) . '</span>'
                . '</div>'
            : '';

        return $notice . '<p class="milpa-empty-convo">No messages yet — write to the session to begin.</p>';
    }

    private function envelope(StateSnapshot $state): string
    {
        return '<script type="application/milpa+xhtml" data-milpa-state="' . self::COMPONENT_ID . '">' . $this->codec->encodeState($state) . '</script>';
    }
}

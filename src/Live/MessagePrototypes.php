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
use Milpa\Live\Contracts\Component\ComponentDefinitionInterface;
use Milpa\Live\Security\HmacStateSigner;
use Milpa\Live\Security\SignedXhtmlStateTransferCodec;
use Milpa\Live\Transport\XhtmlStateTransferCodec;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\StateSnapshot;

/**
 * Renders the plainer conversation message components as PROTOTYPES (greenhouse decisions/0191): the user's
 * message, a tool call, a task row, a system notice. Each is mounted + rendered once (server-side) with a
 * signed envelope, and each fires its own `before_render` / `after_render` event carrying a mutable subject so
 * a plugin can extend that message type once, at its prototype. The conversation clones each per message and
 * fills its data regions. One service because each of these is small; the richer types (agent message,
 * thinking) keep their own services.
 */
final class MessagePrototypes
{
    public const string USER_BEFORE = 'desktop.user_message.before_render';
    public const string USER_AFTER = 'desktop.user_message.after_render';
    public const string TOOL_BEFORE = 'desktop.tool_call.before_render';
    public const string TOOL_AFTER = 'desktop.tool_call.after_render';
    public const string TASK_BEFORE = 'desktop.task.before_render';
    public const string TASK_AFTER = 'desktop.task.after_render';
    public const string SYSTEM_BEFORE = 'desktop.system_notice.before_render';
    public const string SYSTEM_AFTER = 'desktop.system_notice.after_render';
    public const string RESULT_BEFORE = 'desktop.result_claim.before_render';
    public const string RESULT_AFTER = 'desktop.result_claim.after_render';

    private readonly SignedXhtmlStateTransferCodec $codec;

    public function __construct(
        string $signingSecret,
        private readonly ?MilpaEventDispatcherInterface $events = null,
    ) {
        $this->codec = new SignedXhtmlStateTransferCodec(new XhtmlStateTransferCodec(), new HmacStateSigner($signingSecret), null);
    }

    /** The user-message prototype (`desktop-user-message`). */
    public function user(): string
    {
        $markup = '<div class="msg msg--user" data-milpa-component="desktop-user-message" data-milpa-component-id="user-message">'
            . '<div><span class="msg__meta">you · now</span>'
            . '<p data-user-body style="margin:var(--space-2) 0 0;font-size:var(--text-sm);white-space:pre-wrap"></p></div></div>';

        return $this->wrap(new UserMessageComponent(), 'user-message', $markup, self::USER_BEFORE, self::USER_AFTER, 'userMessage');
    }

    /** The tool-call prototype (`desktop-tool-call`) — the name and a summary, the raw result collapsed. */
    public function tool(): string
    {
        $markup = '<div class="msg msg--tool" data-milpa-component="desktop-tool-call" data-milpa-component-id="tool-call" data-open="0">'
            . '<button type="button" class="msg__tool-head" data-tool-toggle>'
            . '<span class="msg__tool-name" data-tool-name>tool</span>'
            . '<span class="msg__tool-summary" data-tool-summary></span></button>'
            . '<pre class="msg__tool-raw" data-tool-body></pre></div>';

        return $this->wrap(new ToolCallComponent(), 'tool-call', $markup, self::TOOL_BEFORE, self::TOOL_AFTER, 'toolCall');
    }

    /** The task prototype (`desktop-task`). */
    public function task(): string
    {
        $markup = '<div class="msg msg--task" data-milpa-component="desktop-task" data-milpa-component-id="task">'
            . '<div><span class="msg__mark">+</span><span class="msg__title" data-task-title></span>'
            . '<span class="mui-badge" data-task-status style="margin-inline-start:auto">todo</span></div></div>';

        return $this->wrap(new TaskComponent(), 'task', $markup, self::TASK_BEFORE, self::TASK_AFTER, 'task');
    }

    /** The system-notice prototype (`desktop-system-notice`). */
    public function system(): string
    {
        $markup = '<div class="msg msg--system" data-milpa-component="desktop-system-notice" data-milpa-component-id="system-notice" data-system-body></div>';

        return $this->wrap(new SystemNoticeComponent(), 'system-notice', $markup, self::SYSTEM_BEFORE, self::SYSTEM_AFTER, 'systemNotice');
    }

    /** The result-claim prototype (`desktop-result-claim`) — the closure verdict as a conversation message. */
    public function resultClaim(): string
    {
        $markup = '<div class="msg msg--result" data-milpa-component="desktop-result-claim" data-milpa-component-id="result-claim" data-verified="1">'
            . '<span class="msg__result-mark" data-result-mark>✓</span> <span data-result-text>verified</span></div>';

        return $this->wrap(new ResultClaimComponent(), 'result-claim', $markup, self::RESULT_BEFORE, self::RESULT_AFTER, 'resultClaim');
    }

    /** Mount the component, fire before/after render with a mutable subject, and cap with the signed envelope. */
    private function wrap(ComponentDefinitionInterface $component, string $id, string $markup, string $before, string $after, string $key): string
    {
        $subject = new ComposerRender([]);
        $this->events?->dispatch($before, [$key => $subject]);

        $state = $component->mount($subject->props, new ComponentContext(componentId: $id));
        $subject->html = $markup . $this->envelope($id, $state);

        $this->events?->dispatch($after, [$key => $subject]);

        return $subject->html;
    }

    private function envelope(string $id, StateSnapshot $state): string
    {
        return '<script type="application/milpa+xhtml" data-milpa-state="' . $id . '">' . $this->codec->encodeState($state) . '</script>';
    }
}

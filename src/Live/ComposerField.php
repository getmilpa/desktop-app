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

use Milpa\DesktopApp\I18n\Catalog;
use Milpa\Interfaces\Event\MilpaEventDispatcherInterface;
use Milpa\Live\Components\Form\InputComponent;
use Milpa\Live\Http\LiveEndpoint;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\RenderRequest;
use Milpa\Live\ValueObjects\RenderTarget;

/**
 * The Desktop's composer text field, built from a real milpa/live component — the first Desktop surface
 * composed of a Milpa component instead of hand-written HTML (greenhouse decisions/0189, evidence/0490).
 *
 * A `<milpa:textarea>` is server-rendered with Alpine local reactivity (state lives in the browser, zero
 * network per keystroke) AND a signed state envelope (the server's truth). Server-driven actions and submit
 * round-trip through {@see LiveEndpoint} on {@see self::ROUTE}, verified by HMAC signature + CSRF.
 *
 * milpa/live is the framework's official UI system, and it is EXTENSIBLE: any {@see \Milpa\Live\Contracts\Component\ComponentDefinitionInterface}
 * an agent or a human writes can be registered here the same way this registers the textarea.
 */
final class ComposerField
{
    public const string ROUTE = '/desktop/live';
    public const string COMPONENT = 'textarea';
    public const string COMPONENT_ID = 'composer-message';
    public const string STATUS_COMPONENT = 'input';
    public const string STATUS_ID = 'composer-status';
    /**
     * RETIRED (greenhouse decisions/0211): the page session travels in the boot the shell issues and the
     * runtime echoes in every request body, so the shell sets no such cookie and `POST /desktop/live` reads
     * none. Kept as the name a house that still sets it would use — and as the falsifier the suite points at.
     */
    public const string SESSION_COOKIE = 'milpa_live_sid';

    /** Dispatched with a mutable {@see ComposerRender} BEFORE the render — a subscriber may change its props. */
    public const string BEFORE_RENDER = 'desktop.composer.before_render';
    /** Dispatched with a mutable {@see ComposerRender} AFTER the render — a subscriber may change its html. */
    public const string AFTER_RENDER = 'desktop.composer.after_render';

    private readonly DesktopComponents $registry;

    /** The Desktop's copy in the declared locale — the field's placeholder is a human-facing sentence. */
    private readonly Catalog $catalog;

    /**
     * @param DesktopComponents|null $registry the Desktop's ONE component registry (greenhouse decisions/0211);
     *                                         a private one is built from the secrets when none is shared in,
     *                                         which is what a unit test that only wants the field gets
     */
    public function __construct(
        string $signingSecret,
        string $csrfSecret,
        private readonly ?MilpaEventDispatcherInterface $events = null,
        ?DesktopComponents $registry = null,
        ?Catalog $catalog = null,
    ) {
        $this->registry = $registry ?? new DesktopComponents($signingSecret, $csrfSecret, $events);
        $this->catalog = $catalog ?? new Catalog();
    }

    /**
     * The initial server-rendered HTML of the composer field: Alpine-bound, carrying its signed state
     * envelope. The render emits {@see self::BEFORE_RENDER} and {@see self::AFTER_RENDER} — Milpa is
     * event-driven, so another plugin can subscribe to extend the component (change its props, or its HTML).
     */
    public function render(): string
    {
        $subject = new ComposerRender(['name' => 'message', 'placeholder' => $this->catalog->tr('composer.placeholder'), 'rows' => 2]);
        $this->events?->dispatch(self::BEFORE_RENDER, ['composer' => $subject]);

        $component = new ComposerMessageComponent($this->events);
        $context = new ComponentContext(componentId: self::COMPONENT_ID, route: self::ROUTE);
        $state = $component->mount($subject->props, $context);

        $subject->html = $this->registry->formRenderer()->render($component, new RenderRequest(
            context: $context,
            // A local field: typing is zero-network. The char count lives in the composer footer now
            // (greenhouse decisions/0191, Rod's minimalist UX) — no separate status line under the box.
            props: ['endpoint' => self::ROUTE, 'remote' => false],
            state: $state,
            target: RenderTarget::HTML,
        ))->output;

        $this->events?->dispatch(self::AFTER_RENDER, ['composer' => $subject]);

        return $subject->html;
    }

    /** Render the read-only status input the composer field re-paints on blur (its id is the effect target). */
    public function renderStatus(string $message): string
    {
        $component = new InputComponent();
        $context = new ComponentContext(componentId: self::STATUS_ID, route: self::ROUTE);
        $state = $component->mount(['name' => 'status', 'value' => $message, 'disabled' => true], $context);

        return $this->registry->formRenderer()->render($component, new RenderRequest(
            context: $context,
            props: ['endpoint' => self::ROUTE],
            state: $state,
            target: RenderTarget::HTML,
        ))->output;
    }

    /** The CSRF token the client presents on an interaction, bound to this session and route. */
    public function csrfToken(string $sessionId): string
    {
        return $this->registry->csrfToken($sessionId);
    }

    /** The Desktop's ONE component registry, shared with the shell's compiler and the live endpoint. */
    public function registry(): DesktopComponents
    {
        return $this->registry;
    }

    /** The endpoint that verifies and handles an interaction (server actions, submit). */
    public function endpoint(): LiveEndpoint
    {
        return $this->registry->endpoint();
    }
}

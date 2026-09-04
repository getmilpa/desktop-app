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
use Milpa\Live\Adapters\Alpine\AlpineRuntimeAdapter;
use Milpa\Live\Components\Form\InputComponent;
use Milpa\Live\Http\LiveEndpoint;
use Milpa\Live\Rendering\FormPrimitiveHtmlRenderer;
use Milpa\Live\Runtime\InMemoryComponentRegistry;
use Milpa\Live\Security\ContractInteractionAuthorizer;
use Milpa\Live\Security\HmacCsrfGuard;
use Milpa\Live\Security\HmacStateSigner;
use Milpa\Live\Security\SignedXhtmlStateTransferCodec;
use Milpa\Live\Transport\XhtmlStateTransferCodec;
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
    public const string SESSION_COOKIE = 'milpa_live_sid';

    /** Dispatched with a mutable {@see ComposerRender} BEFORE the render — a subscriber may change its props. */
    public const string BEFORE_RENDER = 'desktop.composer.before_render';
    /** Dispatched with a mutable {@see ComposerRender} AFTER the render — a subscriber may change its html. */
    public const string AFTER_RENDER = 'desktop.composer.after_render';

    private readonly InMemoryComponentRegistry $components;
    private readonly SignedXhtmlStateTransferCodec $codec;
    private readonly HmacCsrfGuard $csrf;
    private readonly FormPrimitiveHtmlRenderer $renderer;

    public function __construct(string $signingSecret, string $csrfSecret, private readonly ?MilpaEventDispatcherInterface $events = null)
    {
        $this->components = new InMemoryComponentRegistry();
        // The message field validates on the server and re-paints the status; the status is a read-only input
        // that the field's blur re-paints via a cross-component RenderEffect (greenhouse evidence/0491).
        $this->components->register(self::COMPONENT, new ComposerMessageComponent($events));
        $this->components->register(self::STATUS_COMPONENT, new InputComponent());
        $this->codec = new SignedXhtmlStateTransferCodec(new XhtmlStateTransferCodec(), new HmacStateSigner($signingSecret), null);
        $this->csrf = new HmacCsrfGuard($csrfSecret);
        $this->renderer = new FormPrimitiveHtmlRenderer(new AlpineRuntimeAdapter(), $this->codec);
    }

    /**
     * The initial server-rendered HTML of the composer field: Alpine-bound, carrying its signed state
     * envelope. The render emits {@see self::BEFORE_RENDER} and {@see self::AFTER_RENDER} — Milpa is
     * event-driven, so another plugin can subscribe to extend the component (change its props, or its HTML).
     */
    public function render(): string
    {
        $subject = new ComposerRender(['name' => 'message', 'placeholder' => 'Write to the session…', 'rows' => 2]);
        $this->events?->dispatch(self::BEFORE_RENDER, ['composer' => $subject]);

        $component = new ComposerMessageComponent($this->events);
        $context = new ComponentContext(componentId: self::COMPONENT_ID, route: self::ROUTE);
        $state = $component->mount($subject->props, $context);

        $subject->html = $this->renderer->render($component, new RenderRequest(
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

        return $this->renderer->render($component, new RenderRequest(
            context: $context,
            props: ['endpoint' => self::ROUTE],
            state: $state,
            target: RenderTarget::HTML,
        ))->output;
    }

    /** The CSRF token the client presents on an interaction, bound to this session and route. */
    public function csrfToken(string $sessionId): string
    {
        return $this->csrf->issueToken($sessionId, self::ROUTE);
    }

    /** The endpoint that verifies and handles an interaction (server actions, submit). */
    public function endpoint(): LiveEndpoint
    {
        return new LiveEndpoint(
            components: $this->components,
            codec: $this->codec,
            authorizer: new ContractInteractionAuthorizer($this->components),
            csrf: $this->csrf,
            route: self::ROUTE,
            renderers: [self::COMPONENT => $this->renderer, self::STATUS_COMPONENT => $this->renderer],
            renderProps: [self::COMPONENT => ['endpoint' => self::ROUTE, 'remote' => true], self::STATUS_COMPONENT => ['endpoint' => self::ROUTE]],
        );
    }
}

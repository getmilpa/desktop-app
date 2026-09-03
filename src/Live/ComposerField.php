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

use Milpa\Live\Adapters\Alpine\AlpineRuntimeAdapter;
use Milpa\Live\Components\Form\TextareaComponent;
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
    public const string SESSION_COOKIE = 'milpa_live_sid';

    private readonly InMemoryComponentRegistry $components;
    private readonly SignedXhtmlStateTransferCodec $codec;
    private readonly HmacCsrfGuard $csrf;
    private readonly FormPrimitiveHtmlRenderer $renderer;

    public function __construct(string $signingSecret, string $csrfSecret)
    {
        $this->components = new InMemoryComponentRegistry();
        $this->components->register(self::COMPONENT, new TextareaComponent());
        $this->codec = new SignedXhtmlStateTransferCodec(new XhtmlStateTransferCodec(), new HmacStateSigner($signingSecret), null);
        $this->csrf = new HmacCsrfGuard($csrfSecret);
        $this->renderer = new FormPrimitiveHtmlRenderer(new AlpineRuntimeAdapter(), $this->codec);
    }

    /** The initial server-rendered HTML of the composer field: Alpine-bound, carrying its signed state envelope. */
    public function render(): string
    {
        $component = new TextareaComponent();
        $context = new ComponentContext(componentId: self::COMPONENT_ID, route: self::ROUTE);
        $state = $component->mount(['name' => 'message', 'placeholder' => 'Write to the session…', 'rows' => 2], $context);

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
            renderers: [self::COMPONENT => $this->renderer],
            renderProps: [self::COMPONENT => ['endpoint' => self::ROUTE]],
        );
    }
}

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
use Milpa\Live\Contracts\Component\ComponentDefinitionInterface;
use Milpa\Live\Contracts\Component\ComponentRegistryInterface;
use Milpa\Live\Http\LiveEndpoint;
use Milpa\Live\Rendering\ComponentRendererRegistry;
use Milpa\Live\Rendering\FormPrimitiveHtmlRenderer;
use Milpa\Live\Rendering\XhtmlComponentCompiler;
use Milpa\Live\Runtime\InMemoryComponentRegistry;
use Milpa\Live\Security\ContractInteractionAuthorizer;
use Milpa\Live\Security\HmacCsrfGuard;
use Milpa\Live\Security\HmacStateSigner;
use Milpa\Live\Security\SignedXhtmlStateTransferCodec;
use Milpa\Live\Transport\XhtmlStateTransferCodec;

/**
 * ONE component registry for the whole Desktop — the page and the wire (greenhouse decisions/0211).
 *
 * Before this, the Desktop had one registry for the composer field (two form primitives) and no registry
 * at all for its own surfaces: the shell called each surface service by hand and stitched the strings.
 * Now every `desktop-*` component is DECLARED here — its definition in one
 * {@see ComponentRegistryInterface}, its HTML renderer registered FOR its name in one
 * {@see ComponentRendererRegistry} — so:
 *
 *   - the shell composes the page through {@see compiler()}, an {@see XhtmlComponentCompiler} over this
 *     registry: `<milpa-desktop-sidebar/>` resolves to the definition and the renderer, and every
 *     declaring renderer's {@see \Milpa\Live\ValueObjects\ClientAssets} lands in the compile result, so
 *     `LiveBoot::html()` emits each file once;
 *   - {@see endpoint()} — what `POST /desktop/live` serves — is built over the SAME registry and the SAME
 *     renderer registry, so an interaction on any declared component re-renders through the renderer that
 *     painted it, and a cross-component `RenderEffect` resolves across the whole Desktop.
 *
 * One signing key, one CSRF key, one endpoint route: the codec and the guard live here and every surface
 * signs its envelope with them (greenhouse decisions/0211, "one signing key per page").
 *
 * The registry is mutable and shared by reference: a surface declared after the endpoint was built is
 * still resolved by it.
 */
final class DesktopComponents
{
    /** The route the endpoint is mounted on and every envelope is bound to. */
    public const string ROUTE = ComposerField::ROUTE;

    private readonly InMemoryComponentRegistry $components;

    private readonly ComponentRendererRegistry $renderers;

    private readonly SignedXhtmlStateTransferCodec $codec;

    private readonly HmacCsrfGuard $csrf;

    private readonly FormPrimitiveHtmlRenderer $formRenderer;

    /**
     * @param string $signingSecret the HMAC secret every component's state envelope is signed with
     * @param string $csrfSecret    the HMAC secret the CSRF tokens are issued with
     */
    public function __construct(
        string $signingSecret,
        string $csrfSecret,
        private readonly ?MilpaEventDispatcherInterface $events = null,
    ) {
        $this->codec = new SignedXhtmlStateTransferCodec(new XhtmlStateTransferCodec(), new HmacStateSigner($signingSecret), null);
        $this->csrf = new HmacCsrfGuard($csrfSecret);
        $this->formRenderer = new FormPrimitiveHtmlRenderer(new AlpineRuntimeAdapter(), $this->codec);

        // The composer's own primitives: the message field validates on the server and re-paints the
        // status through a cross-component RenderEffect (greenhouse evidence/0491).
        $this->components = new InMemoryComponentRegistry();
        $this->renderers = new ComponentRendererRegistry();
        $this->components->register(ComposerField::COMPONENT, new ComposerMessageComponent($events));
        $this->components->register(ComposerField::STATUS_COMPONENT, new InputComponent());
        $this->renderers->registerFor(ComposerField::COMPONENT, $this->formRenderer);
        $this->renderers->registerFor(ComposerField::STATUS_COMPONENT, $this->formRenderer);
    }

    /**
     * Declare one Desktop surface: its definition under its contract name, and the renderer that paints
     * it (which declares exactly that component's client files).
     *
     * Declaring a name twice replaces it, so a host that rebuilds a surface re-declares it rather than
     * ending up with two registries that disagree.
     *
     * @param callable(array<string, mixed>): string $paint produces the surface's HTML from the render request's props
     */
    public function declare(ComponentDefinitionInterface $definition, callable $paint): void
    {
        $name = $definition::contract()->name;
        $this->components->register($name, $definition);
        $this->renderers->registerFor($name, new DesktopComponentRenderer($name, $paint));
    }

    /** Whether a component is declared here. */
    public function has(string $name): bool
    {
        return $this->components->has($name);
    }

    /**
     * Every declared component name, in declaration order.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return $this->components->names();
    }

    /** The one registry: the composer's primitives plus every declared `desktop-*` surface. */
    public function components(): ComponentRegistryInterface
    {
        return $this->components;
    }

    /** The one renderer registry, answering per component name ({@see ComponentRendererRegistry::resolveFor()}). */
    public function renderers(): ComponentRendererRegistry
    {
        return $this->renderers;
    }

    /** The signed state codec every Desktop surface encodes its envelope with. */
    public function codec(): SignedXhtmlStateTransferCodec
    {
        return $this->codec;
    }

    /** The renderer of the shipped form primitives (the composer's field and its status). */
    public function formRenderer(): FormPrimitiveHtmlRenderer
    {
        return $this->formRenderer;
    }

    /** The CSRF token for a page session, bound to this session and {@see ROUTE}. */
    public function csrfToken(string $sessionId): string
    {
        return $this->csrf->issueToken($sessionId, self::ROUTE);
    }

    /** The CSRF guard itself — what {@see \Milpa\Live\Http\LiveBoot::issue()} mints a page boot with. */
    public function csrf(): HmacCsrfGuard
    {
        return $this->csrf;
    }

    /**
     * The compiler the shell composes the page with: `milpa-desktop-…` element markup in, rendered HTML
     * plus the declared client assets out.
     *
     * @param array<string, array<string, mixed>> $defaults component name => the props the page renders it with
     */
    public function compiler(array $defaults = []): XhtmlComponentCompiler
    {
        return new XhtmlComponentCompiler($this->components, $this->renderers, $defaults);
    }

    /**
     * The endpoint `POST /desktop/live` serves: one registry, one renderer registry, one codec, one CSRF
     * guard — so every declared component is reachable over the same wire.
     */
    public function endpoint(): LiveEndpoint
    {
        return new LiveEndpoint(
            components: $this->components,
            codec: $this->codec,
            authorizer: new ContractInteractionAuthorizer($this->components),
            csrf: $this->csrf,
            route: self::ROUTE,
            renderers: $this->renderers,
            renderProps: [
                ComposerField::COMPONENT => ['endpoint' => self::ROUTE, 'remote' => true],
                ComposerField::STATUS_COMPONENT => ['endpoint' => self::ROUTE],
            ],
            dispatcher: $this->events,
        );
    }
}

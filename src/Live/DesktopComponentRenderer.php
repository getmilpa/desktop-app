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

use Milpa\Live\Contracts\Component\ComponentDefinitionInterface;
use Milpa\Live\Contracts\Rendering\ComponentRendererInterface;
use Milpa\Live\Contracts\Rendering\DeclaresClientAssets;
use Milpa\Live\ValueObjects\ClientAssets;
use Milpa\Live\ValueObjects\RenderRequest;
use Milpa\Live\ValueObjects\RenderResult;
use Milpa\Live\ValueObjects\RenderTarget;

/**
 * The HTML renderer of ONE Desktop shell surface (greenhouse decisions/0211, "declared views").
 *
 * One instance per component — never one instance for several — because a renderer's
 * {@see DeclaresClientAssets::clientAssets()} is a property of the RENDERER, not of the instance being
 * painted: it must be stable and it must name exactly this component's files. The instance carries the
 * component name it answers for, the painter that produces its markup (the surface service that owns the
 * component's HTML, its lifecycle events and its signed envelope) and the assets that markup depends on.
 *
 * The compiler ({@see \Milpa\Live\Rendering\XhtmlComponentCompiler}) meets it through the Desktop's
 * {@see \Milpa\Live\Rendering\ComponentRendererRegistry} — `registerFor(<name>, <this>)` — collects its
 * assets into `RenderResult::clientAssets()`, and the shell hands the merged result to `LiveBoot::html()`,
 * which emits each URL once. The same instance answers the live endpoint when an interaction re-renders
 * the surface, so one registry serves the page and the wire.
 */
final class DesktopComponentRenderer implements ComponentRendererInterface, DeclaresClientAssets
{
    /** @var \Closure(array<string, mixed>): string */
    private readonly \Closure $paint;

    /** Exactly this component's files — resolved once, so the declaration never depends on the instance painted. */
    private readonly ClientAssets $assets;

    /**
     * @param string                                 $component the contract name this renderer answers for (e.g. `desktop-sidebar`)
     * @param callable(array<string, mixed>): string $paint     produces the surface's HTML from the render request's props
     * @param ClientAssets|null                      $assets    what that HTML depends on; {@see DesktopAssets::of()} for the component when null
     */
    public function __construct(
        private readonly string $component,
        callable $paint,
        ?ClientAssets $assets = null,
    ) {
        $this->paint = $paint instanceof \Closure ? $paint : \Closure::fromCallable($paint);
        $this->assets = $assets ?? DesktopAssets::of($component);
    }

    /** The contract name this renderer answers for. */
    public function component(): string
    {
        return $this->component;
    }

    /** HTML only: the Desktop is a page in a browser; a TUI renderer of the same component declares nothing. */
    public function supportsTarget(RenderTarget $target): bool
    {
        return $target === RenderTarget::HTML;
    }

    /** Exactly this component's own files — never another's, and never a runtime file the host emits. */
    public function clientAssets(): ClientAssets
    {
        return $this->assets;
    }

    /**
     * Paint the surface from the request's props, carrying its declared assets on the result.
     *
     * @throws \InvalidArgumentException for a component this renderer does not answer for, or a target other than HTML
     */
    public function render(ComponentDefinitionInterface $component, RenderRequest $request): RenderResult
    {
        $name = $component::contract()->name;
        if ($name !== $this->component) {
            throw new \InvalidArgumentException(\sprintf('%s renders only «%s», not «%s».', self::class, $this->component, $name));
        }
        if (!$this->supportsTarget($request->target)) {
            throw new \InvalidArgumentException(\sprintf('%s renders HTML only, not «%s».', self::class, $request->target->value));
        }

        return new RenderResult(
            output: ($this->paint)($request->props),
            state: $request->state,
            clientAssets: $this->assets,
        );
    }
}

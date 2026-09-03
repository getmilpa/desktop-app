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

/**
 * The mutable subject a component's render events carry, so other plugins can extend it (greenhouse
 * decisions/0189). Milpa is event-driven: a component MUST emit lifecycle events, and each carries a
 * subject a subscriber can change — the props before the render, the HTML after it.
 *
 * A subscriber to {@see ComposerField::BEFORE_RENDER} may change {@see $props} (e.g. the placeholder or
 * rows); a subscriber to {@see ComposerField::AFTER_RENDER} may change {@see $html} (e.g. wrap or append to
 * the field). Nothing is forced: leave both untouched and the render is exactly what the component produced.
 */
final class ComposerRender
{
    /**
     * @param array<string, mixed> $props the props the component mounts with — mutable before the render
     * @param string               $html  the rendered HTML — mutable after the render
     */
    public function __construct(
        public array $props,
        public string $html = '',
    ) {
    }
}

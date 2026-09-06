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

namespace Milpa\DesktopApp\Admin;

use Milpa\Admin\Section\DeclaredView;
use Milpa\DesktopApp\Data\DesktopData;
use Milpa\DesktopApp\DesktopSettings;
use Milpa\DesktopApp\I18n\Catalog;
use Milpa\DesktopApp\Live\DesktopComponents;
use Milpa\DesktopApp\Live\ShellSignals;
use Milpa\Live\Contracts\Component\ComponentDefinitionInterface;
use Milpa\Live\Contracts\Rendering\ComponentRendererInterface;
use Milpa\Live\ValueObjects\RenderTarget;

/**
 * The VIEW the Desktop declares to milpa/admin — the plugin declares, the runtime reconciles
 * (greenhouse decisions/0211, slice 3).
 *
 * The Desktop brings its own UI to somebody else's page and the host mounts it: the markup to compile,
 * the definitions and the renderers that tree needs, the props each component mounts with, and the
 * signals the page must seed for them. Nothing is framed, nothing is fetched as a second document, and
 * the host emits ONE runtime for the whole page.
 *
 * **The definitions are the SHELL's own instances.** They come out of {@see DesktopComponents} — the
 * registry `/desktop` composes with — so the panel and the Desktop's own page paint the same components
 * with the same renderers. That is what «reuse, do not fork» means here, and it is also what milpa/live
 * 0.18's conflict rule asks for: the same INSTANCE is one definition, two instances of a stateful class
 * are two, and a guest that built a second `TextareaComponent` would collide with the host the day the
 * host registered one.
 *
 * **What travels and what does not.** Only the `desktop-*` names travel: the Desktop's own namespace,
 * plus this region's root, {@see AgentViewComponent}. The two form primitives the Desktop's registry also
 * holds (`textarea`, `input`) are milpa/live's OWN, shared by every host — bringing them into the panel's
 * composite would be one plugin squatting a name the whole framework uses.
 *
 * **The seeds.** {@see ShellSignals} is the one authority for what a page mounting these components must
 * start with, so the panel seeds exactly what `/desktop` seeds; the host merges it with its own and a key
 * two declarers disagree about is an error naming both.
 */
final class AgentView
{
    /** The prefix that says a component is the Desktop's own — what may travel into a host's registry. */
    public const string PREFIX = 'desktop-';

    /**
     * The declared view for the Agent section: this region's root, every Desktop component behind it, and
     * the page's seeds.
     *
     * The MARKUP is one root — {@see AgentViewComponent::NAME} — because the region is one contained
     * whole: {@see AgentViewRenderer} composes the surfaces inside it, containing each on its own, and
     * refuses to compose any of them when nobody is signed in. The other definitions still travel so the
     * panel's composite registry and its live wire can resolve every surface the region painted.
     *
     * @param DesktopComponents $live     the Desktop's ONE registry — the shell's instances, not copies
     * @param DesktopSettings   $settings the judged door, for the gate chip and the guard's sign-in path
     * @param Catalog           $catalog  the Desktop's copy in its declared locale
     * @param DesktopData|null  $data     the session seam the composed surfaces read
     * @param string            $open     where «Open the Desktop» goes — the shell's own path
     * @param string            $signin   the app's sign-in door, for the signed-out state
     */
    public static function of(
        DesktopComponents $live,
        DesktopSettings $settings,
        Catalog $catalog,
        ?DesktopData $data,
        string $open,
        string $signin,
    ): DeclaredView {
        $definitions = [AgentViewComponent::NAME => new AgentViewComponent()];
        $renderers = [AgentViewComponent::NAME => new AgentViewRenderer($live, $data, $catalog)];

        foreach (self::surfaces($live) as $name => [$definition, $renderer]) {
            $definitions[$name] = $definition;
            $renderers[$name] = $renderer;
        }

        return new DeclaredView(
            markup: '<milpa:' . AgentViewComponent::NAME . ' id="' . AgentViewComponent::REGION_ID . '"/>',
            definitions: $definitions,
            renderers: $renderers,
            props: [AgentViewComponent::NAME => [
                'open' => $open,
                'gate' => $settings->gateLabel(),
                'signin' => $signin,
            ]],
            signals: ShellSignals::of($catalog, $data),
            computed: ShellSignals::computed(),
        );
    }

    /**
     * Every `desktop-*` component of the registry with the renderer that paints it — skipping any the
     * registry declared without an HTML renderer, which cannot be painted in a web panel and would only
     * be a definition the host could never resolve.
     *
     * @return array<string, array{0: ComponentDefinitionInterface, 1: ComponentRendererInterface}>
     */
    private static function surfaces(DesktopComponents $live): array
    {
        $surfaces = [];
        foreach ($live->names() as $name) {
            if (!str_starts_with($name, self::PREFIX)) {
                continue;
            }
            $renderer = $live->renderers()->resolveFor($name, RenderTarget::HTML);
            if ($renderer === null) {
                continue;
            }
            $surfaces[$name] = [$live->components()->get($name), $renderer];
        }

        return $surfaces;
    }
}

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

use Milpa\DesktopApp\DesktopSettings;
use Milpa\Live\Contracts\Component\ComponentDefinitionInterface;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\ComponentContract;
use Milpa\Live\ValueObjects\InteractionRequest;
use Milpa\Live\ValueObjects\InteractionResult;
use Milpa\Live\ValueObjects\StateSnapshot;

/**
 * The Agent region of the admin panel as a Milpa Component — the ROOT of the view the Desktop declares
 * (greenhouse decisions/0211, slice 3): the Desktop's conversation composed INLINE in the host's own
 * document, with no frame, no second runtime and no second document.
 *
 * This replaces the `desktop-agent` of decisions/0210, which mounted an `<iframe>`. The contract keeps its
 * name and its two states, and loses the one prop the frame needed (`embed`): what the region shows is not
 * a page any more, it is the Desktop's own components, painted by the Desktop's own renderers into the
 * host's page ({@see AgentViewRenderer}).
 *
 * The component owns the ONE decision the region turns on — whether it can show the Agent at all:
 *
 *   - `live`: the conversation region, composed inline;
 *   - `signed-out`: when the Desktop stands behind the passkey gate (`gate` prop `passkey`) and the admin
 *     authenticated nobody ({@see ComponentContext::$principal} null). Then the view is NOT composed —
 *     not hidden, not framed, not mounted — and the human is offered the sign-in door with `next`
 *     pointing back at this section (greenhouse decisions/0210 §2, unchanged by the retirement of the
 *     frame). The rule reads the PRINCIPAL THE ADMIN AUTHENTICATED, not the Desktop's own gate: both
 *     belong behind the same door (greenhouse decisions/0209).
 *
 * Every path it carries (`open`, `signin`) is a prop the Desktop plugin declares; the admin's mount point
 * comes from the context's `route`, which the admin shell fills with its own; `query` is the host's
 * reserved prop (the request's query params, milpa/admin's rule for every section), read here for `lang`
 * only, so the way back after sign-in keeps the language the human was reading the panel in.
 *
 * It has no actions of its own: the Desktop's surfaces inside the region act; the region only composes
 * them or refuses to.
 */
final class AgentViewComponent implements ComponentDefinitionInterface
{
    public const string NAME = 'desktop-agent';
    public const string VERSION = '2';

    /** The section id in the admin — its URL segment under `{route}/s/`. */
    public const string SECTION = 'agent';

    /**
     * The region's element id — the guest's own, stable, and not derived from the host's naming: the
     * compiler would otherwise mint one from the node's path, which changes with the markup around it.
     */
    public const string REGION_ID = 'milpa-agent';

    public const string STATE_LIVE = 'live';
    public const string STATE_SIGNED_OUT = 'signed-out';

    /**
     * The key milpa/admin puts the request's query params under in every node's
     * `ComponentContext::$meta` ({@see \Milpa\Admin\View\AdminShell::META_QUERY}). Named here as a
     * string, not imported: the Desktop takes no dependency on the admin.
     */
    public const string META_QUERY = 'query';

    /** milpa/admin's default mount point, used when the context carries no route. */
    public const string DEFAULT_ADMIN_ROUTE = '/milpa/admin';

    public const string DEFAULT_OPEN = '/desktop';
    public const string DEFAULT_SIGNIN = '/webauthn/signin';

    /**
     * The contract: the Desktop's paths and its gate as string props, the host's reserved `query` (the
     * request's params, as milpa/admin hands every section); the region's state as `live` | `signed-out`.
     */
    public static function contract(): ComponentContract
    {
        return new ComponentContract(
            name: self::NAME,
            contractVersion: self::VERSION,
            summary: "The Milpa Desktop's Agent as one region of the admin panel: the conversation composed inline, behind the same door.",
            propsSchema: [
                'open' => ['type' => 'string', 'default' => self::DEFAULT_OPEN],
                'gate' => ['type' => 'string', 'default' => DesktopSettings::GATE_LOOPBACK],
                'signin' => ['type' => 'string', 'default' => self::DEFAULT_SIGNIN],
                'query' => ['type' => 'array', 'default' => []],
            ],
            stateSchema: ['state' => ['type' => 'string', 'enum' => [self::STATE_LIVE, self::STATE_SIGNED_OUT]]],
        );
    }

    /**
     * Mount from props and context: `signed-out` only when the gate is the passkey gate AND the context
     * names no principal; `live` otherwise. The paths ride in meta, with the sign-in `next` derived from
     * the context's route (the admin's mount point) — keeping the request's `lang`, when it carried one —
     * the locale the host resolved, and the principal, which is what the region's own agent session is
     * derived from ({@see AgentViewRenderer}).
     */
    public function mount(array $props, ComponentContext $context): StateSnapshot
    {
        $gate = self::string($props, 'gate', DesktopSettings::GATE_LOOPBACK);
        $signedOut = $gate === DesktopSettings::GATE_PASSKEY && $context->principal === null;
        // The request's query reaches a VIEW through the context's meta, not through props: milpa/admin
        // fills `props['query']` only for the narrow one-component shape, and a view's props are its own
        // per component. `meta` is the channel the host opened for exactly this (greenhouse
        // decisions/0211, H5) — read here for `lang` only. The prop is still honoured, so a host that
        // mounts this component the narrow way keeps working.
        $meta = \is_array($context->meta[self::META_QUERY] ?? null) ? $context->meta[self::META_QUERY] : [];
        $query = $meta !== [] || !\is_array($props['query'] ?? null) ? $meta : $props['query'];
        $lang = $query['lang'] ?? null;

        return new StateSnapshot(
            $context->componentId,
            self::NAME,
            self::VERSION,
            ['state' => $signedOut ? self::STATE_SIGNED_OUT : self::STATE_LIVE],
            [
                'open' => self::string($props, 'open', self::DEFAULT_OPEN),
                'gate' => $gate,
                'signin' => self::string($props, 'signin', self::DEFAULT_SIGNIN),
                'next' => self::sectionPath($context->route) . (\is_string($lang) && $lang !== '' ? '?lang=' . rawurlencode($lang) : ''),
                'locale' => $context->locale,
                'principal' => $context->principal ?? '',
            ],
        );
    }

    /** No action is declared: the Desktop's own surfaces inside the region act, each through its own contract. */
    public function handle(InteractionRequest $request): InteractionResult
    {
        return new InteractionResult(
            state: $request->state,
            errors: ['action' => \sprintf('«%s» declares no actions: the Desktop\'s surfaces inside the region act, the region only composes them.', self::NAME)],
        );
    }

    /**
     * Where this section lives in the admin — `{route}/s/agent` — from the mount point the context carries,
     * else milpa/admin's default. What the sign-in door's `next` points back at.
     */
    public static function sectionPath(?string $route): string
    {
        $mount = \is_string($route) && trim($route, '/') !== '' ? '/' . trim($route, '/') : self::DEFAULT_ADMIN_ROUTE;

        return $mount . '/s/' . self::SECTION;
    }

    /**
     * @param array<string, mixed> $props
     */
    private static function string(array $props, string $name, string $default): string
    {
        $value = $props[$name] ?? null;

        return \is_string($value) && $value !== '' ? $value : $default;
    }
}

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
 * The Agent section of the admin panel as a Milpa Component (greenhouse decisions/0210): ONE region inside the
 * admin's main, behind the same door, holding the Desktop shell in embed mode.
 *
 * The component owns the decision the region turns on — whether it can show the Agent at all. Its state is
 * one of two: `live`, the embed frame plus a guest bar; or `signed-out`, when the Desktop stands behind the
 * passkey gate (`gate` prop `passkey`) and the admin authenticated nobody ({@see ComponentContext::$principal}
 * null) — then no frame is mounted, because the frame would only bounce to the sign-in page inside the region,
 * and the human is offered the sign-in door with `next` pointing back at this section instead. Every path it
 * carries (`embed`, `open`, `signin`) is a prop the Desktop plugin declares; the admin's mount point comes from
 * the context's `route`, which the admin shell fills with its own; `query` is the host's reserved prop (the
 * request's query params, milpa/admin's rule for every section), read here for `lang` only, so the way back
 * after sign-in keeps the language the human was reading the panel in.
 *
 * The rule reads the PRINCIPAL THE ADMIN AUTHENTICATED, as the decision names it — not the Desktop's own gate:
 * an admin whose door authenticates nobody (loopback, `[]`) leaves the region at the sign-in offer whatever
 * cookie the browser holds. Both belong behind the same door (greenhouse decisions/0209).
 *
 * It has no actions: the Desktop inside the frame acts; the region only shows or refuses to show it.
 */
final class AgentGuestComponent implements ComponentDefinitionInterface
{
    public const string NAME = 'desktop-agent';
    public const string VERSION = '1';

    /** The section id in the admin — its URL segment under `{route}/s/`. */
    public const string SECTION = 'agent';

    public const string STATE_LIVE = 'live';
    public const string STATE_SIGNED_OUT = 'signed-out';

    /** milpa/admin's default mount point, used when the context carries no route. */
    public const string DEFAULT_ADMIN_ROUTE = '/milpa/admin';

    public const string DEFAULT_EMBED = '/desktop?embed=1';
    public const string DEFAULT_OPEN = '/desktop';
    public const string DEFAULT_SIGNIN = '/webauthn/signin';

    /**
     * The contract: the Desktop's paths and its gate as string props, the host's reserved `query` (the request's
     * params, as milpa/admin hands every section); the region's state as `live` | `signed-out`.
     */
    public static function contract(): ComponentContract
    {
        return new ComponentContract(
            name: self::NAME,
            contractVersion: self::VERSION,
            summary: "The Milpa Desktop's Agent as one region of the admin panel: the shell in embed mode, behind the same door.",
            propsSchema: [
                'embed' => ['type' => 'string', 'default' => self::DEFAULT_EMBED],
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
     * names no principal; `live` otherwise. The paths ride in meta, with the sign-in `next` derived from the
     * context's route (the admin's mount point) — keeping the request's `lang`, when it carried one — and the
     * locale the host resolved.
     */
    public function mount(array $props, ComponentContext $context): StateSnapshot
    {
        $gate = self::string($props, 'gate', DesktopSettings::GATE_LOOPBACK);
        $signedOut = $gate === DesktopSettings::GATE_PASSKEY && $context->principal === null;
        $query = \is_array($props['query'] ?? null) ? $props['query'] : [];
        $lang = $query['lang'] ?? null;

        return new StateSnapshot(
            $context->componentId,
            self::NAME,
            self::VERSION,
            ['state' => $signedOut ? self::STATE_SIGNED_OUT : self::STATE_LIVE],
            [
                'embed' => self::string($props, 'embed', self::DEFAULT_EMBED),
                'open' => self::string($props, 'open', self::DEFAULT_OPEN),
                'gate' => $gate,
                'signin' => self::string($props, 'signin', self::DEFAULT_SIGNIN),
                'next' => self::sectionPath($context->route) . (\is_string($lang) && $lang !== '' ? '?lang=' . rawurlencode($lang) : ''),
                'locale' => $context->locale,
            ],
        );
    }

    /** No action is declared: the region shows the Desktop or refuses to; the Desktop inside the frame acts. */
    public function handle(InteractionRequest $request): InteractionResult
    {
        return new InteractionResult(
            state: $request->state,
            errors: ['action' => \sprintf('«%s» declares no actions: the Desktop inside the frame acts, the region only shows it.', self::NAME)],
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

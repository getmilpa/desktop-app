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

use Milpa\Runtime\Config;
use Milpa\Runtime\Stack\EnvVar;
use Milpa\Runtime\Stack\PortMapping;
use Milpa\Runtime\Stack\ServiceDeclaration;

/**
 * The Mercure hub the Desktop needs, as a stack declaration (greenhouse decisions/0201).
 *
 * {@see MercureConfig} is how the app TALKS to a hub; this is how the plugin SAYS which hub the host has to
 * run: `dunglas/mercure`, its container port published on the port of the URL the browser reaches, the JWT
 * keys as secrets that point at the app's own config and are never inlined, and the CORS + anonymous
 * directives the browser subscription needs. It reads the wiring's `desktop.mercure.*` keys plus ONE
 * optional key of its own, `desktop.mercure.cors_origin` — declaration-only, the wiring never reads it.
 * Pure data — nothing here starts a container. An admin panel, a CLI or the agent reads it to show the
 * service, probe its port and project a compose fragment.
 */
final class MercureServiceDeclaration
{
    /** The service name — how compose keys the hub and how the admin lists it. */
    public const NAME = 'mercure';

    /** The image the host runs: the official Mercure hub. */
    public const IMAGE = 'dunglas/mercure';

    /** The port the hub listens on inside the container; `SERVER_NAME` binds it. */
    public const CONTAINER_PORT = 80;

    /** The host port published when no loopback URL in config names one. */
    public const DEFAULT_HOST_PORT = 3000;

    /** The default `cors_origins`: BOTH spellings of the quickstart origin, since a credentialed EventSource needs an exact match. */
    public const DEFAULT_CORS_ORIGINS = 'http://127.0.0.1:8080 http://localhost:8080';

    /** The one-line summary an admin panel shows next to the service. */
    public const SUMMARY = 'The live feed of the Desktop shell and the agent sessions — without it the app falls back to the log feed.';

    /** The config keys whose URL may name the published host port, most preferred first. */
    private const HOST_PORT_KEYS = ['desktop.mercure.public_url', 'desktop.mercure.hub_url'];

    /** The hosts that mean «this machine» — the only ones whose port is a port the host publishes. */
    private const LOOPBACK_HOSTS = ['127.0.0.1', 'localhost', '::1', '[::1]'];

    /**
     * The declaration, read from the wiring's `desktop.mercure.*` keys so the hub it describes is the hub
     * the app publishes to — plus the optional `cors_origin`: the host port comes from the URL the browser
     * reaches ({@see hostPort()}), the CORS origins verbatim from `cors_origin` when declared, else both
     * quickstart origins. The JWT keys stay `configKey` references flagged secret — the projection reads
     * them from the app; the declaration never carries their values, and the runtime refuses one that does.
     */
    public static function fromConfig(?Config $config): ServiceDeclaration
    {
        return new ServiceDeclaration(
            name: self::NAME,
            image: self::IMAGE,
            ports: [new PortMapping(container: self::CONTAINER_PORT, host: self::hostPort($config))],
            env: [
                new EnvVar('SERVER_NAME', value: ':' . self::CONTAINER_PORT),
                new EnvVar('MERCURE_PUBLISHER_JWT_KEY', configKey: 'desktop.mercure.publisher_key', secret: true),
                new EnvVar('MERCURE_SUBSCRIBER_JWT_KEY', configKey: 'desktop.mercure.subscriber_key', secret: true),
                new EnvVar('MERCURE_EXTRA_DIRECTIVES', value: 'cors_origins ' . self::corsOrigins($config) . "\nanonymous"),
            ],
            summary: self::SUMMARY,
        );
    }

    /**
     * The host port to publish: the port of the URL the BROWSER reaches, because the published port is what
     * makes the hub reachable from the host. `desktop.mercure.public_url` names it first; absent, the browser
     * reaches the hub the app publishes to, so `desktop.mercure.hub_url` is read next; 3000 closes the chain.
     * A URL yields a port only when its host is loopback (`127.0.0.1`, `localhost`, `::1`): an in-network URL
     * such as `http://mercure:80/...` names a port INSIDE the container network, and publishing it as `80:80`
     * would be wrong, so it is skipped. A port outside 1–65535 (`:0` parses as 0, `:70000` makes parse_url
     * fail) or a non-string config value is skipped the same way.
     */
    private static function hostPort(?Config $config): int
    {
        foreach (self::HOST_PORT_KEYS as $key) {
            $port = self::loopbackPort($config?->get($key));
            if ($port !== null) {
                return $port;
            }
        }

        return self::DEFAULT_HOST_PORT;
    }

    /** The port `$url` carries when it is a string naming a loopback host and a publishable port; null otherwise. */
    private static function loopbackPort(mixed $url): ?int
    {
        if (!is_string($url) || $url === '') {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || !in_array(strtolower($host), self::LOOPBACK_HOSTS, true)) {
            return null;
        }

        // parse_url already refuses a port above 65535 (the whole parse fails); 0 parses, and no service publishes on it.
        $port = parse_url($url, PHP_URL_PORT);

        return is_int($port) && $port >= 1 ? $port : null;
    }

    /**
     * The origins the hub lets subscribe: `desktop.mercure.cors_origin` verbatim when declared — it may carry
     * several, space-separated, as Mercure's `cors_origins` reads them — else {@see DEFAULT_CORS_ORIGINS}.
     */
    private static function corsOrigins(?Config $config): string
    {
        $origins = $config?->get('desktop.mercure.cors_origin');

        return is_string($origins) && $origins !== '' ? $origins : self::DEFAULT_CORS_ORIGINS;
    }
}

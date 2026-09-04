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
 * run: `dunglas/mercure`, its container port published on the host port the app publishes to, the JWT keys
 * as secrets that point at the app's own config and are never inlined, and the CORS + anonymous directives
 * the browser subscription needs. Pure data — nothing here starts a container. An admin panel, a CLI or the
 * agent reads it to show the service, probe its port and project a compose fragment.
 */
final class MercureServiceDeclaration
{
    public const NAME = 'mercure';
    public const IMAGE = 'dunglas/mercure';
    public const CONTAINER_PORT = 80;
    public const DEFAULT_HOST_PORT = 3000;
    public const DEFAULT_CORS_ORIGIN = 'http://localhost:8080';
    public const SUMMARY = 'The live feed of the Desktop shell and the agent sessions — without it the app falls back to the log feed.';

    /**
     * The declaration, read from the same `desktop.mercure.*` keys the wiring uses so the hub it describes
     * is the hub the app publishes to: the host port comes from `hub_url` when that URL carries one (3000
     * otherwise), the CORS origin from `cors_origin` when declared. The keys stay `configKey` references
     * flagged secret — the projection reads them from the app, the declaration never carries their values.
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
                new EnvVar('MERCURE_EXTRA_DIRECTIVES', value: 'cors_origins ' . self::corsOrigin($config) . "\nanonymous"),
            ],
            summary: self::SUMMARY,
        );
    }

    /** The host port to publish: the one `desktop.mercure.hub_url` carries, else the default. */
    private static function hostPort(?Config $config): int
    {
        $hubUrl = $config?->get('desktop.mercure.hub_url');
        if (!is_string($hubUrl) || $hubUrl === '') {
            return self::DEFAULT_HOST_PORT;
        }

        // parse_url already bounds a port to 0–65535; 0 is not a port a service can publish on.
        $port = parse_url($hubUrl, PHP_URL_PORT);

        return is_int($port) && $port >= 1 ? $port : self::DEFAULT_HOST_PORT;
    }

    /** The origin the hub lets subscribe: `desktop.mercure.cors_origin` when declared, else the default. */
    private static function corsOrigin(?Config $config): string
    {
        $origin = $config?->get('desktop.mercure.cors_origin');

        return is_string($origin) && $origin !== '' ? $origin : self::DEFAULT_CORS_ORIGIN;
    }
}

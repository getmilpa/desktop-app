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

namespace Milpa\DesktopApp\Tests\Live;

use Milpa\DesktopApp\Live\MercureServiceDeclaration;
use Milpa\Runtime\Config;
use Milpa\Runtime\Stack\EnvVar;
use Milpa\Runtime\Stack\ServiceDeclaration;
use PHPUnit\Framework\TestCase;

/**
 * The Desktop declares the hub it needs as pure data (greenhouse decisions/0201): the image, the port the
 * app publishes to, the keys as secret config references that are never inlined, and the directives the
 * browser subscription needs. Nothing here starts a container.
 */
final class MercureServiceDeclarationTest extends TestCase
{
    public function testItDeclaresTheHubWithDefaultsWhenNothingIsConfigured(): void
    {
        foreach ([null, new Config([])] as $config) {
            $service = MercureServiceDeclaration::fromConfig($config);

            self::assertSame('mercure', $service->name);
            self::assertSame('dunglas/mercure', $service->image);
            self::assertCount(1, $service->ports);
            self::assertSame(80, $service->ports[0]->container);
            self::assertSame(3000, $service->ports[0]->host, 'no hub url: the default published port');
            self::assertSame('3000:80', $service->ports[0]->toCompose());
            self::assertSame(3000, $service->probePort(), 'the reachability probe tries the published port');
            self::assertSame([], $service->volumes);
            self::assertSame([], $service->command);
            self::assertSame(MercureServiceDeclaration::SUMMARY, $service->summary);
            self::assertStringContainsString('falls back to the log feed', $service->summary);
        }
    }

    public function testItPublishesThePortTheAppPublishesTo(): void
    {
        $service = MercureServiceDeclaration::fromConfig(new Config([
            'desktop' => ['mercure' => ['hub_url' => 'http://127.0.0.1:3010/.well-known/mercure']],
        ]));

        self::assertSame(3010, $service->ports[0]->host, 'the host port is parsed from the hub url');
        self::assertSame(80, $service->ports[0]->container, 'the container port does not move');
        self::assertSame('3010:80', $service->ports[0]->toCompose());
        self::assertSame(3010, $service->probePort());
    }

    public function testAHubUrlWithoutAPortKeepsTheDefault(): void
    {
        $service = MercureServiceDeclaration::fromConfig(new Config([
            'desktop' => ['mercure' => ['hub_url' => 'http://hub/.well-known/mercure']],
        ]));

        self::assertSame(3000, $service->ports[0]->host);
    }

    public function testTheKeysAreSecretConfigReferencesNeverInlined(): void
    {
        $env = self::envByName(MercureServiceDeclaration::fromConfig(new Config([
            'desktop' => ['mercure' => [
                'hub_url' => 'http://127.0.0.1:3000/.well-known/mercure',
                'publisher_key' => 'the-publisher-key',
                'subscriber_key' => 'the-subscriber-key',
            ]],
        ])));

        self::assertSame(
            ['SERVER_NAME', 'MERCURE_PUBLISHER_JWT_KEY', 'MERCURE_SUBSCRIBER_JWT_KEY', 'MERCURE_EXTRA_DIRECTIVES'],
            array_keys($env),
        );

        $publisher = $env['MERCURE_PUBLISHER_JWT_KEY'];
        self::assertTrue($publisher->secret);
        self::assertSame('desktop.mercure.publisher_key', $publisher->configKey);
        self::assertNull($publisher->value, 'a secret is never inlined, even when config holds it');

        $subscriber = $env['MERCURE_SUBSCRIBER_JWT_KEY'];
        self::assertTrue($subscriber->secret);
        self::assertSame('desktop.mercure.subscriber_key', $subscriber->configKey);
        self::assertNull($subscriber->value, 'a secret is never inlined, even when config holds it');

        $serverName = $env['SERVER_NAME'];
        self::assertSame(':80', $serverName->value, 'the hub listens on its container port');
        self::assertFalse($serverName->secret);
        self::assertNull($serverName->configKey);
    }

    public function testTheExtraDirectivesAreMultiLineWithTheDefaultOrigin(): void
    {
        $directives = self::envByName(MercureServiceDeclaration::fromConfig(null))['MERCURE_EXTRA_DIRECTIVES'];

        self::assertSame("cors_origins http://localhost:8080\nanonymous", $directives->value);
        self::assertFalse($directives->secret);
        self::assertNull($directives->configKey);
    }

    public function testTheCorsOriginComesFromConfigWhenDeclared(): void
    {
        $directives = self::envByName(MercureServiceDeclaration::fromConfig(new Config([
            'desktop' => ['mercure' => ['cors_origin' => 'https://desktop.example']],
        ])))['MERCURE_EXTRA_DIRECTIVES'];

        self::assertSame("cors_origins https://desktop.example\nanonymous", $directives->value);
    }

    /** @return array<string, EnvVar> */
    private static function envByName(ServiceDeclaration $service): array
    {
        $byName = [];
        foreach ($service->env as $variable) {
            $byName[$variable->name] = $variable;
        }

        return $byName;
    }
}

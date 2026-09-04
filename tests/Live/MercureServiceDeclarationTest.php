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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The Desktop declares the hub it needs as pure data (greenhouse decisions/0201): the image, the port of
 * the URL the browser reaches, the keys as secret config references that are never inlined, and the
 * directives the browser subscription needs. Nothing here starts a container.
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
            self::assertSame(3000, $service->ports[0]->host, 'no url in config: the default published port');
            self::assertSame('3000:80', $service->ports[0]->toCompose());
            self::assertSame(3000, $service->probePort(), 'the reachability probe tries the published port');
            self::assertSame([], $service->volumes);
            self::assertSame([], $service->command);
            self::assertSame(MercureServiceDeclaration::SUMMARY, $service->summary);
            self::assertStringContainsString('falls back to the log feed', $service->summary);
        }
    }

    public function testThePublicUrlPortWinsOverTheHubUrlPort(): void
    {
        $service = MercureServiceDeclaration::fromConfig(new Config([
            'desktop' => ['mercure' => [
                'hub_url' => 'http://127.0.0.1:3010/.well-known/mercure',
                'public_url' => 'http://localhost:3020/.well-known/mercure',
            ]],
        ]));

        self::assertSame(3020, $service->ports[0]->host, 'the browser reaches public_url: that is the port to publish');
        self::assertSame(80, $service->ports[0]->container, 'the container port does not move');
        self::assertSame('3020:80', $service->ports[0]->toCompose());
        self::assertSame(3020, $service->probePort());
    }

    public function testTheHubUrlPortIsUsedWhenThePublicUrlIsAbsent(): void
    {
        $service = MercureServiceDeclaration::fromConfig(new Config([
            'desktop' => ['mercure' => ['hub_url' => 'http://127.0.0.1:3010/.well-known/mercure']],
        ]));

        self::assertSame(3010, $service->ports[0]->host, 'no public_url: the browser reaches the hub the app publishes to');
        self::assertSame('3010:80', $service->ports[0]->toCompose());
        self::assertSame(3010, $service->probePort());
    }

    public function testAnInNetworkPublicUrlFallsThroughToALoopbackHubUrl(): void
    {
        $service = MercureServiceDeclaration::fromConfig(new Config([
            'desktop' => ['mercure' => [
                'hub_url' => 'http://127.0.0.1:3010/.well-known/mercure',
                'public_url' => 'https://hub.example.test/.well-known/mercure',
            ]],
        ]));

        self::assertSame(3010, $service->ports[0]->host, 'a non-loopback public_url names no host port; hub_url still does');
    }

    #[DataProvider('loopbackHosts')]
    public function testEverySpellingOfLoopbackYieldsItsPort(string $url, int $expectedPort): void
    {
        $service = MercureServiceDeclaration::fromConfig(new Config([
            'desktop' => ['mercure' => ['public_url' => $url]],
        ]));

        self::assertSame($expectedPort, $service->ports[0]->host, $url);
    }

    /** @return iterable<string, array{string, int}> */
    public static function loopbackHosts(): iterable
    {
        yield 'ipv4' => ['http://127.0.0.1:3010/.well-known/mercure', 3010];
        yield 'localhost' => ['http://localhost:3011/.well-known/mercure', 3011];
        yield 'localhost, upper case' => ['http://LOCALHOST:3012/.well-known/mercure', 3012];
        yield 'ipv6' => ['http://[::1]:3013/.well-known/mercure', 3013];
        yield 'top of the range' => ['http://127.0.0.1:65535/.well-known/mercure', 65535];
    }

    #[DataProvider('urlsThatNameNoHostPort')]
    public function testAUrlThatNamesNoHostPortKeepsTheDefault(mixed $hubUrl, mixed $publicUrl): void
    {
        $service = MercureServiceDeclaration::fromConfig(new Config([
            'desktop' => ['mercure' => array_filter(
                ['hub_url' => $hubUrl, 'public_url' => $publicUrl],
                static fn (mixed $value): bool => $value !== null,
            )],
        ]));

        self::assertSame(3000, $service->ports[0]->host);
        self::assertSame('3000:80', $service->ports[0]->toCompose());
    }

    /** @return iterable<string, array{mixed, mixed}> */
    public static function urlsThatNameNoHostPort(): iterable
    {
        yield 'in-network hub url: its port is inside the container network, not on the host' => ['http://mercure:80/.well-known/mercure', null];
        yield 'in-network public url' => [null, 'http://mercure:80/.well-known/mercure'];
        yield 'both in-network' => ['http://mercure:80/.well-known/mercure', 'https://hub.example.test/.well-known/mercure'];
        yield 'loopback without a port' => ['http://127.0.0.1/.well-known/mercure', null];
        yield 'port 0' => ['http://127.0.0.1:0/.well-known/mercure', null];
        yield 'port 70000, which parse_url refuses' => [null, 'http://127.0.0.1:70000/.well-known/mercure'];
        yield 'integer instead of a url' => [3010, null];
        yield 'array instead of a url' => [null, ['http://127.0.0.1:3010']];
        yield 'boolean instead of a url' => [true, false];
        yield 'empty strings' => ['', ''];
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

        foreach ($env as $variable) {
            if ($variable->secret) {
                self::assertNull($variable->value, $variable->name . ': the runtime refuses a secret that carries a literal value');
            }
        }

        $serverName = $env['SERVER_NAME'];
        self::assertSame(':80', $serverName->value, 'the hub listens on its container port');
        self::assertFalse($serverName->secret);
        self::assertNull($serverName->configKey);
    }

    public function testTheDefaultDirectivesNameBothQuickstartOrigins(): void
    {
        $directives = self::envByName(MercureServiceDeclaration::fromConfig(null))['MERCURE_EXTRA_DIRECTIVES'];

        self::assertSame("cors_origins http://127.0.0.1:8080 http://localhost:8080\nanonymous", $directives->value);
        self::assertFalse($directives->secret);
        self::assertNull($directives->configKey);

        $corsLine = explode("\n", (string) $directives->value)[0];
        $origins = explode(' ', $corsLine);
        self::assertSame('cors_origins', array_shift($origins));
        self::assertContains('http://127.0.0.1:8080', $origins, 'the quickstart serves at 127.0.0.1:8080');
        self::assertContains('http://localhost:8080', $origins, 'and the same page opened as localhost is a different origin');
    }

    public function testTheCorsOriginsComeFromConfigVerbatimWhenDeclared(): void
    {
        $directives = self::envByName(MercureServiceDeclaration::fromConfig(new Config([
            'desktop' => ['mercure' => ['cors_origin' => 'https://desktop.example https://desktop.example:8443']],
        ])))['MERCURE_EXTRA_DIRECTIVES'];

        self::assertSame("cors_origins https://desktop.example https://desktop.example:8443\nanonymous", $directives->value);
        self::assertStringNotContainsString('localhost', (string) $directives->value, 'a declared origin replaces the defaults, it does not add to them');
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

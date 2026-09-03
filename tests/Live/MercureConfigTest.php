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

use Milpa\DesktopApp\Live\MercureConfig;
use Milpa\Mercure\MercureService;
use Milpa\Runtime\Config;
use PHPUnit\Framework\TestCase;

/**
 * The Mercure wiring is opt-in (greenhouse decisions/0475): present only when the app configured the hub URL
 * and both keys, with sensible defaults for the public URL and topic. It builds the graduated MercureService
 * and mints a subscriber JWT for the browser.
 */
final class MercureConfigTest extends TestCase
{
    public function testItIsAbsentWithoutTheRequiredConfig(): void
    {
        self::assertNull(MercureConfig::fromConfig(new Config([])));
        self::assertNull(MercureConfig::fromConfig(new Config([
            'desktop' => ['mercure' => ['hub_url' => 'http://hub/.well-known/mercure']],
        ])), 'the keys are still missing');
    }

    public function testItReadsTheWiringAndDefaultsThePublicUrlAndTopic(): void
    {
        $mercure = MercureConfig::fromConfig(new Config([
            'desktop' => ['mercure' => [
                'hub_url' => 'http://hub/.well-known/mercure',
                'publisher_key' => 'pub',
                'subscriber_key' => 'sub',
            ]],
        ]));

        self::assertInstanceOf(MercureConfig::class, $mercure);
        self::assertSame('http://hub/.well-known/mercure', $mercure->hubUrl);
        self::assertSame('http://hub/.well-known/mercure', $mercure->publicUrl, 'public url defaults to the hub url');
        self::assertSame('desktop/shell', $mercure->topic, 'the topic defaults');
        self::assertInstanceOf(MercureService::class, $mercure->service());
    }

    public function testItHonorsAnExplicitPublicUrlAndTopicAndMintsASubscriberJwt(): void
    {
        $mercure = MercureConfig::fromConfig(new Config([
            'desktop' => ['mercure' => [
                'hub_url' => 'http://internal/.well-known/mercure',
                'public_url' => 'https://public.example/.well-known/mercure',
                'publisher_key' => 'pub',
                'subscriber_key' => 'sub',
                'topic' => 'desktop/custom',
            ]],
        ]));

        self::assertInstanceOf(MercureConfig::class, $mercure);
        self::assertSame('https://public.example/.well-known/mercure', $mercure->publicUrl);
        self::assertSame('desktop/custom', $mercure->topic);
        // A JWT is three base64url segments separated by dots.
        self::assertMatchesRegularExpression('/^[\w-]+\.[\w-]+\.[\w-]+$/', $mercure->subscriberJwt());
    }
}

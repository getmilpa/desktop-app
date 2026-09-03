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

namespace Milpa\DesktopApp\Tests\Data;

use Milpa\Container\DIContainer;
use Milpa\DesktopApp\Data\DesktopData;
use Milpa\DesktopApp\DesktopAppPlugin;
use Milpa\Runtime\Kernel;
use PHPUnit\Framework\TestCase;

/**
 * The data seam reads REAL runtime data (greenhouse decisions/0481): capabilities from the booted plugins'
 * #[PluginMetadata], the model from config — not mocks.
 */
final class DesktopDataTest extends TestCase
{
    public function testCapabilitiesAreTheBootedPluginsMetadata(): void
    {
        $kernel = Kernel::boot(['root' => sys_get_temp_dir(), 'plugins' => [DesktopAppPlugin::class]]);
        // Apps register the kernel in their container (the skeleton front controller does); the data reads it.
        $kernel->container()->registerService(Kernel::class, $kernel);

        $caps = (new DesktopData($kernel->container()))->capabilities();

        $names = array_column($caps, 'name');
        self::assertContains('DesktopApp', $names);
        $desktop = $caps[array_search('DesktopApp', $names, true)];
        self::assertSame('Web', $desktop['type']);
        self::assertSame('Rodrigo Vicente - TeamX Agency', $desktop['author']);
    }

    public function testCapabilitiesAreEmptyWithoutAKernel(): void
    {
        self::assertSame([], (new DesktopData(new DIContainer()))->capabilities());
    }

    public function testTheModelComesFromConfigWithEnvFallback(): void
    {
        $kernel = Kernel::boot([
            'root' => sys_get_temp_dir(),
            'plugins' => [],
            'config' => ['agent' => ['model' => 'qwen-test', 'base_url' => 'http://hub.test:9000']],
        ]);
        $kernel->container()->registerService(Kernel::class, $kernel);

        $model = (new DesktopData($kernel->container()))->model();

        self::assertSame('qwen-test', $model['model']);
        self::assertSame('http://hub.test:9000', $model['endpoint']);
    }

    public function testToArrayCarriesBothCapabilitiesAndModel(): void
    {
        $snapshot = (new DesktopData(new DIContainer()))->toArray();

        self::assertArrayHasKey('capabilities', $snapshot);
        self::assertArrayHasKey('model', $snapshot);
        self::assertArrayHasKey('endpoint', $snapshot['model']);
    }
}

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
use Milpa\DesktopApp\Live\ShellEvent;
use Milpa\DesktopApp\Live\ShellEventLog;
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

    public function testToArrayCarriesEveryDataSource(): void
    {
        $snapshot = (new DesktopData(new DIContainer()))->toArray();

        foreach (['capabilities', 'model', 'sessions', 'counters', 'work', 'audit'] as $key) {
            self::assertArrayHasKey($key, $snapshot);
        }
    }

    public function testSessionsCountersAndWorkComeFromTheSessionStore(): void
    {
        $dir = sys_get_temp_dir() . '/milpa-sessions-' . uniqid('', true);
        mkdir($dir);
        file_put_contents($dir . '/s1.json', json_encode([
            'id' => 's1', 'goal' => 'Publish the site', 'state' => 'working',
            'turns' => 2, 'steps' => 10, 'tokens' => 500, 'tool_calls' => 41,
            'work' => [
                ['title' => 'List plugins', 'status' => 'done', 'origin' => 'planned'],
                ['title' => 'Verify each', 'status' => 'in_progress', 'origin' => 'found'],
                'not-an-array-ignored',
            ],
        ], JSON_THROW_ON_ERROR));

        $data = new DesktopData(new DIContainer(), null, $dir);

        $sessions = $data->sessions();
        self::assertCount(1, $sessions);
        self::assertSame(['id' => 's1', 'goal' => 'Publish the site', 'state' => 'working'], $sessions[0]);

        $counters = $data->counters();
        self::assertSame(2, $counters['turns']);
        self::assertSame(41, $counters['tool_calls']);
        self::assertSame('working', $counters['state']);

        $work = $data->work();
        self::assertCount(2, $work);
        self::assertSame('List plugins', $work[0]['title']);
        self::assertSame('in_progress', $work[1]['status']);

        unlink($dir . '/s1.json');
        rmdir($dir);
    }

    public function testAuditComesFromTheEventLog(): void
    {
        $path = sys_get_temp_dir() . '/milpa-audit-' . uniqid('', true) . '.log';
        $log = new ShellEventLog($path);
        $log->append(new ShellEvent('gate.opened', ['operation' => 'capabilities.enable']));
        $log->append(new ShellEvent('badge.updated', ['text' => 'hi']));

        $audit = (new DesktopData(new DIContainer(), $log))->audit();

        self::assertCount(2, $audit);
        self::assertSame(1, $audit[0]['seq']);
        self::assertSame('gate.opened', $audit[0]['type']);
        self::assertStringContainsString('capabilities.enable', $audit[0]['data']);

        // steps defaults to the audit count when the session has no explicit counter.
        self::assertSame(2, (new DesktopData(new DIContainer(), $log))->counters()['steps']);

        unlink($path);
    }

    public function testEmptySourcesDegradeGracefully(): void
    {
        $data = new DesktopData(new DIContainer());

        self::assertSame([], $data->sessions());
        self::assertSame([], $data->work());
        self::assertSame([], $data->audit());
        self::assertSame(0, $data->counters()['turns']);
        self::assertSame('', $data->currentSessionId());
    }
}

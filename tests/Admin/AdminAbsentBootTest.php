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

namespace Milpa\DesktopApp\Tests\Admin;

use Milpa\Container\DIContainer;
use Milpa\DesktopApp\Admin\AdminGuest;
use Milpa\DesktopApp\DesktopAppPlugin;
use PHPUnit\Framework\TestCase;

/**
 * The floor the guest mechanism must keep (greenhouse decisions/0210): a fresh app WITHOUT milpa/admin boots
 * the Desktop and serves it. This suite runs with the admin installed (a dev dependency), so the measurement
 * is a separate process in which every `Milpa\Admin\*` name is unloadable ({@see tests/Fixtures/boot-without-admin.php}).
 * The positive control is this very process, where the admin IS loadable and the bridge extends its interface.
 */
final class AdminAbsentBootTest extends TestCase
{
    public function testAFreshAppWithoutTheAdminBootsThePluginAndServesEmbedMode(): void
    {
        $script = \dirname(__DIR__) . '/Fixtures/boot-without-admin.php';
        $output = [];
        $exit = 1;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1', $output, $exit);
        $stdout = implode("\n", $output);

        self::assertSame(0, $exit, 'the process must not fatal: ' . $stdout);
        $report = json_decode((string) end($output), true);
        self::assertIsArray($report, 'one JSON line: ' . $stdout);
        self::assertSame(['DesktopApp'], $report['booted'], 'the runtime booted the plugin');
        self::assertSame(200, $report['status'], '/desktop?embed=1 is served');
        self::assertTrue($report['embed'], 'in embed mode');
        self::assertFalse($report['admin_interface'], 'the control of the control: the admin really was unloadable in that process');
        self::assertFalse($report['admin_section']);
        self::assertTrue($report['guest'], 'the plugin is an AdminGuest — the standalone shape');
        self::assertFalse($report['provider'], 'and NOT an AdminSectionProvider: nothing to find, nothing fatal');
        self::assertContains(AdminGuest::class, $report['interfaces']);
        self::assertNotContains('Milpa\\Admin\\Section\\AdminSectionProvider', $report['interfaces']);
    }

    public function testWithTheAdminInstalledTheSameClassIsAnAdminSectionProvider(): void
    {
        // The positive control, in this process: the bridge extends the real interface, so the admin's
        // `instanceof` discovery finds the plugin with no registration step.
        $plugin = new DesktopAppPlugin(new DIContainer());

        self::assertTrue(interface_exists(\Milpa\Admin\Section\AdminSectionProvider::class));
        self::assertInstanceOf(AdminGuest::class, $plugin);
        self::assertInstanceOf(\Milpa\Admin\Section\AdminSectionProvider::class, $plugin);
        self::assertTrue((new \ReflectionClass(AdminGuest::class))->implementsInterface(\Milpa\Admin\Section\AdminSectionProvider::class), 'AdminGuest EXTENDS the admin\'s interface here');
    }
}

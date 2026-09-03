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
use Milpa\DesktopApp\Data\DesktopStore;
use PHPUnit\Framework\TestCase;

/**
 * The write side persists to the same real stores the read side reads (greenhouse decisions/0483): a saved
 * setting and a created session both survive — a reader sees them back.
 */
final class DesktopStoreTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/milpa-store-' . uniqid('', true);
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (['/sessions', '/nested'] as $subdir) {
            foreach (glob($this->dir . $subdir . '/*') ?: [] as $f) {
                unlink($f);
            }
            if (is_dir($this->dir . $subdir)) {
                rmdir($this->dir . $subdir);
            }
        }
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    public function testSettingsRoundTrip(): void
    {
        $store = new DesktopStore($this->dir . '/sessions', $this->dir . '/settings.json');

        self::assertSame([], $store->settings(), 'nothing saved yet');

        $store->saveSettings(['endpoint' => 'http://saved.test/v1', 'mode' => 'auto']);

        self::assertSame(['endpoint' => 'http://saved.test/v1', 'mode' => 'auto'], $store->settings());
        // And the read seam sees the persisted settings.
        self::assertSame('auto', (new DesktopData(new DIContainer(), null, '', $store))->settings()['mode']);
    }

    public function testCreatingASessionWritesItToTheStoreAndItIsRead(): void
    {
        $sessionsDir = $this->dir . '/sessions';
        $store = new DesktopStore($sessionsDir, $this->dir . '/settings.json');

        $id = $store->createSession('Ship the release');

        self::assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $id);
        self::assertFileExists($sessionsDir . '/' . $id . '.json');

        $sessions = (new DesktopData(new DIContainer(), null, $sessionsDir))->sessions();
        self::assertCount(1, $sessions);
        self::assertSame('Ship the release', $sessions[0]['goal']);
        self::assertSame('ready', $sessions[0]['state']);
    }

    public function testAnEmptyGoalStillCreatesARecord(): void
    {
        $store = new DesktopStore($this->dir . '/sessions', $this->dir . '/settings.json');
        $id = $store->createSession('');

        self::assertFileExists($this->dir . '/sessions/' . $id . '.json');
    }

    public function testSavingSettingsCreatesTheSettingsDirectory(): void
    {
        // The settings file's directory may not exist yet; saving creates it.
        $store = new DesktopStore($this->dir . '/sessions', $this->dir . '/nested/settings.json');
        $store->saveSettings(['ok' => true]);

        self::assertFileExists($this->dir . '/nested/settings.json');
    }
}

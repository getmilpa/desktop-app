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

namespace Milpa\DesktopApp\Tests;

use Milpa\DesktopApp\DesktopAppPlugin;
use Milpa\DesktopApp\Tests\Fixtures\DemoSectionPlugin;
use Milpa\Runtime\Http\RequestHandler;
use Milpa\Runtime\Kernel;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

/**
 * The 0188 seam, proved by execution: a plugin renders the shell and OTHER plugins modify that same UI
 * through the `desktop.shell.compose` event, decoupled — the shell never names the contributor. Both
 * plugins boot through the real runtime; the witness is the contributor's marker appearing in the
 * served page, and the negative control is that same marker being absent when the contributor is not
 * installed (so the section is proven to come from the contributor and nowhere else).
 */
final class ShellExtensionTest extends TestCase
{
    public function testASecondPluginContributesUiIntoTheShell(): void
    {
        $psr17 = new Psr17Factory();
        $kernel = Kernel::boot([
            'root' => sys_get_temp_dir(),
            'plugins' => [DesktopAppPlugin::class, DemoSectionPlugin::class],
        ]);

        $body = (string) (new RequestHandler($kernel, $psr17))
            ->handle(new ServerRequest('GET', '/desktop'))
            ->getBody();

        self::assertStringContainsString(DemoSectionPlugin::MARKER, $body, 'the foreign plugin modified the shell UI');
        self::assertStringContainsString('data-plugin="demo-section"', $body, 'the section is attributed to its contributor');
        // The base shell is still there — the contribution extends, it does not replace.
        self::assertStringContainsString('Milpa Desktop', $body);
    }

    public function testWithoutTheContributorTheShellCarriesNoSection(): void
    {
        $psr17 = new Psr17Factory();
        $kernel = Kernel::boot([
            'root' => sys_get_temp_dir(),
            'plugins' => [DesktopAppPlugin::class],
        ]);

        $body = (string) (new RequestHandler($kernel, $psr17))
            ->handle(new ServerRequest('GET', '/desktop'))
            ->getBody();

        self::assertStringNotContainsString(DemoSectionPlugin::MARKER, $body);
        self::assertStringNotContainsString('data-plugin=', $body);
    }
}

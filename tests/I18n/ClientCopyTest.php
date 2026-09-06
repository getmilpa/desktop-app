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

namespace Milpa\DesktopApp\Tests\I18n;

use Milpa\DesktopApp\I18n\Catalog;
use PHPUnit\Framework\TestCase;

/**
 * The copy the CLIENT says, against the copy the SERVER has (greenhouse decisions/0211, phase A4).
 *
 * A declared view keeps its behaviour in its own file, so the Desktop's human copy now leaves the server
 * twice: once as HTML a renderer wrote, and once as `tr('<key>')` inside eight separate `.js` files, fed
 * by the catalog the shell serializes into `#milpa-desktop-i18n`. Nothing in the PHP suite reads those
 * files and nothing in the node suite can read the catalog, so a key renamed on one side would print the
 * RAW KEY to a user with every gate green. That gap is what this closes.
 *
 * Two claims, both by reading the shipped artifacts and checking them against the running catalog:
 *
 *   1. every `tr('…')` key a shipped module asks for is a key the catalog answers;
 *   2. the node harness's hand-typed `CATALOG` — the copy those tests assert sentences against — says
 *      exactly what the English catalog says. It had already drifted («The call failed (%s)» against the
 *      shipped «The request failed (HTTP %s)»), which is precisely a test asserting a sentence no user
 *      ever sees.
 */
final class ClientCopyTest extends TestCase
{
    /** The package's own root — the shipped files, not a fixture of them. */
    private static function root(): string
    {
        return \dirname(__DIR__, 2);
    }

    public function testEveryKeyTheShippedModulesAskForIsOneTheCatalogAnswers(): void
    {
        $catalog = new Catalog();
        $modules = glob(self::root() . '/resources/components/*/*.js') ?: [];
        self::assertNotEmpty($modules, 'the instrument found no modules to read — it would pass on an empty package');

        $asked = [];
        foreach ($modules as $module) {
            $source = (string) file_get_contents($module);
            preg_match_all("/\btr\('([^']+)'/", $source, $matches);
            foreach ($matches[1] as $key) {
                $asked[$key][] = basename($module);
            }
        }

        // The positive control for the PARSER: if it stopped matching, every assertion below would pass
        // vacuously. It must find the keys the guard is built on.
        self::assertArrayHasKey('guard.forbidden', $asked, 'the parser reads real `tr()` calls');
        self::assertArrayHasKey('settings.saved', $asked);
        self::assertGreaterThanOrEqual(6, \count($asked));

        foreach ($asked as $key => $files) {
            self::assertTrue(
                $catalog->has($key),
                \sprintf('«%s» is asked for by %s and answered by nobody — the user would read the key', $key, implode(', ', array_unique($files))),
            );
        }

        // And the control for the CLAIM: a key nobody wrote is not answered, so `has()` discriminates.
        self::assertFalse($catalog->has('nobody.wrote.this'));
    }

    public function testTheNodeHarnessCopyOfTheCatalogSaysWhatTheCatalogSays(): void
    {
        $harness = self::root() . '/tests/js/support/page.mjs';
        self::assertFileExists($harness);
        $source = (string) file_get_contents($harness);

        self::assertSame(1, preg_match('/export const CATALOG = \{(.*?)\n\};/s', $source, $block), 'the harness still declares one CATALOG');
        preg_match_all("/^\s*'([^']+)':\s*'(.*)',$/m", $block[1], $entries, \PREG_SET_ORDER);
        self::assertNotEmpty($entries, 'the instrument read no entries — it would pass on an empty catalog');

        $catalog = new Catalog();
        foreach ($entries as [, $key, $value]) {
            self::assertTrue($catalog->has($key), \sprintf('the harness carries «%s», which the catalog does not', $key));
            self::assertSame(
                $catalog->tr($key),
                str_replace("\\'", "'", $value),
                \sprintf('the harness says something else for «%s» — a node test would assert a sentence no user reads', $key),
            );
        }
        self::assertGreaterThanOrEqual(7, \count($entries), 'the harness still carries the guard and settings copy');
    }
}

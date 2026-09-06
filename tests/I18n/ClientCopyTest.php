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

use Milpa\DesktopApp\Controllers\ShellController;
use Milpa\DesktopApp\I18n\Catalog;
use Milpa\Eventing\EventDispatcher;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

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
 *   1. every DOTTED LITERAL a shipped module carries is either a key the catalog answers, a signal the
 *      page seeds or computes, or one of the few declared names that are neither;
 *   2. the node harness's hand-typed `CATALOG` — the copy those tests assert sentences against — says
 *      exactly what the English catalog says. It had already drifted («The call failed (%s)» against the
 *      shipped «The request failed (HTTP %s)»), which is precisely a test asserting a sentence no user
 *      ever sees.
 *
 * (1) IS WIDER THAN IT WAS, and the widening is the point. The first parser here matched `\btr\('…'` and
 * therefore saw only a key written as the literal FIRST argument — so nine live keys were invisible to it:
 * `conn.live` / `conn.offline` / `conn.connecting` (the bus picks one into a variable and calls `tr(key)`),
 * `verdict.aria.verified` / `verdict.aria.disputed`, `command.mode.set` / `command.mode.set.auto`,
 * `command.goal.set` / `command.goal.unchanged` (each reached through a ternary INSIDE the call). Renaming
 * any of them in the catalog printed the raw key at a user with every gate green — exactly the failure this
 * test exists to stop. Reading every dotted literal instead means the parser cannot be walked around by
 * writing the call differently; the price is that the signals and the bus's fact types look the same, so
 * those are named — the signals READ OFF THE PAGE the shell serves, not hand-typed.
 */
final class ClientCopyTest extends TestCase
{
    /** The package's own root — the shipped files, not a fixture of them. */
    private static function root(): string
    {
        return \dirname(__DIR__, 2);
    }

    /**
     * The dotted names a module carries that are NOT copy, each with the reason it is not.
     *
     * The signals are not here: they are read off the page the shell actually serves (`#milpa-live-signals`
     * and `#milpa-live-computed`), so a signal renamed on the server is a signal renamed here too. What is
     * left is what no server tag declares — the bus's fact TYPES, the two connection signals the bus alone
     * writes, and the one browser storage key.
     *
     * @var array<string, string> name => why it is not a catalog key
     */
    private const array NOT_COPY = [
        'agent.message' => 'a bus fact type (the hub republishes it, the thread renders it)',
        'agent.reasoning' => 'a bus fact type',
        'agent.thinking' => 'a bus fact type',
        'decision.parked' => 'a bus fact type (the sidebar ticks its badge, the inbox adds its card)',
        'gate.opened' => 'a bus fact type',
        'session.state' => 'a bus fact type',
        'system.notice' => 'a bus fact type',
        'task.added' => 'a bus fact type',
        'tool.call' => 'a bus fact type',
        'conn.state' => 'a signal the bus alone writes and the status bar binds — the page seeds no value',
        'conn.label' => 'idem: the connection has no state until the transport says one',
        'milpa.theme' => "the localStorage key the viewer's own theme preference is remembered under",
    ];

    /** The signals the SERVED page declares — seeded and computed — read off the page itself. */
    private static function signalsOfThePage(): array
    {
        $page = (string) (new ShellController(new EventDispatcher(new NullLogger())))
            ->shell(new ServerRequest('GET', '/desktop'))->getBody();

        $names = [];
        foreach (['milpa-live-signals', 'milpa-live-computed'] as $id) {
            self::assertSame(1, preg_match('/<script id="' . $id . '" type="application\/json">(.*?)<\/script>/s', $page, $m), $id . ' is served');
            $read = json_decode($m[1], true);
            self::assertIsArray($read, $id . ' is JSON');
            foreach (array_keys($read) as $name) {
                $names[(string) $name] = $id;
            }
        }
        self::assertArrayHasKey('session.working', $names, 'the instrument read real signals');

        return $names;
    }

    public function testEveryDottedNameAModuleCarriesIsCopyTheCatalogAnswersOrADeclaredNonKey(): void
    {
        $catalog = new Catalog();
        $signals = self::signalsOfThePage();
        $modules = glob(self::root() . '/resources/components/*/*.js') ?: [];
        self::assertNotEmpty($modules, 'the instrument found no modules to read — it would pass on an empty package');

        $carried = [];
        foreach ($modules as $module) {
            $source = (string) file_get_contents($module);
            preg_match_all("/'([a-z][a-z0-9_]*(?:\\.[a-z0-9_]+)+)'/", $source, $matches);
            foreach ($matches[1] as $name) {
                $carried[$name][] = basename($module);
            }
        }

        // The positive control for the PARSER: if it stopped matching, every assertion below would pass
        // vacuously. It must find the keys the guard is built on AND the nine the old parser could not see.
        foreach ([
            'guard.forbidden', 'settings.saved',
            'conn.live', 'conn.offline', 'conn.connecting',
            'verdict.aria.verified', 'verdict.aria.disputed',
            'command.mode.set', 'command.mode.set.auto', 'command.goal.set', 'command.goal.unchanged',
        ] as $reached) {
            self::assertArrayHasKey($reached, $carried, '«' . $reached . '» is reached by a shipped module and the parser must see it');
        }
        self::assertGreaterThanOrEqual(40, \count($carried));

        foreach ($carried as $name => $files) {
            $where = implode(', ', array_unique($files));
            if (isset(self::NOT_COPY[$name]) || isset($signals[$name])) {
                self::assertFalse(
                    $catalog->has($name) && isset(self::NOT_COPY[$name]),
                    \sprintf('«%s» is declared a non-key but the catalog answers it — say which it is', $name),
                );
                continue;
            }
            self::assertTrue(
                $catalog->has($name),
                \sprintf('«%s» is carried by %s and is neither copy the catalog answers nor a declared non-key — a user would read it raw', $name, $where),
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

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

use Milpa\Container\DIContainer;
use Milpa\DesktopApp\Controllers\ShellController;
use Milpa\DesktopApp\Data\DesktopData;
use Milpa\DesktopApp\Admin\AgentViewComponent;
use Milpa\DesktopApp\Admin\AgentViewRenderer;
use Milpa\DesktopApp\Live\CapabilitiesScreen;
use Milpa\DesktopApp\Live\ComposerRender;
use Milpa\DesktopApp\Live\DecisionsInbox;
use Milpa\DesktopApp\Live\ScreenPreview;
use Milpa\DesktopApp\Live\ShellEvent;
use Milpa\DesktopApp\Live\ShellEventLog;
use Milpa\DesktopApp\ShellComposition;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\RenderRequest;
use Milpa\Eventing\EventDispatcher;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The DOM contract between a renderer's markup and its module's selectors (greenhouse decisions/0211).
 *
 * The gap this closes, named by the review of phases C and D: nothing gated the `data-*` half of the
 * declared views. `ClientCopyTest` closes it for COPY — a renamed catalog key fails there — and the node
 * suite runs the modules against a hand-typed stub of the renderers' output, which is a SECOND idea of
 * what the server prints and can drift from it silently (it already had: the thinking prototype's
 * `data-thinking-spark` was missing from the stub, and `data-cap-row` meant two different nodes). Green
 * over a stub is not evidence about the served page.
 *
 * So this reads the hooks out of the SHIPPED modules — every `getElementById('…')`, every `'#…'` selector
 * and every `[data-…]` / `…Attribute('data-…')` literal — and asserts the SERVER prints each one. A
 * renderer that renames a hook, or a module that reaches for one no renderer paints, is red here even when
 * both files are internally consistent and every other gate is green.
 *
 * The pages it reads are the shell's own, rendered FULL: the plain page, embed mode, a page over a real
 * session on disk (the work board, the decisions inbox), and a page whose surfaces were handed the props
 * a live app gives them (an available capability, a declared screen, a contributed panel). A hook that
 * only appears when a surface has something to show has something to show here.
 */
final class DomContractTest extends TestCase
{
    /**
     * Hooks a MODULE writes rather than reads — they are not the server's to print.
     *
     * The list is short and each entry names the writer, because a long one would be this test excusing
     * itself. Anything else must exist in the markup the server serves.
     *
     * @var array<string, string> hook => which module writes it, and onto what
     */
    private const array WRITTEN_BY_A_MODULE = [
        'data-cap-package' => 'desktop-capabilities.js stamps the package onto the confirm box it clones',
        'data-answered' => 'desktop-decisions.js stamps it on a graph card once its decision came back accepted',
        'data-decision-status' => 'desktop-decisions.js creates the line it writes the answer\'s outcome into',
    ];

    /**
     * The package's own resources — the shipped modules, not a fixture of them.
     *
     * @return list<string>
     */
    private static function modules(): array
    {
        $files = glob(\dirname(__DIR__) . '/resources/components/*/*.js') ?: [];
        self::assertNotEmpty($files, 'the instrument found no modules — it would pass on an empty package');

        return $files;
    }

    /**
     * Every element id a shipped module resolves, and every `data-` attribute it reaches for, with the
     * module that does it.
     *
     * @return array{ids: array<string, list<string>>, attributes: array<string, list<string>>}
     */
    private static function hooks(): array
    {
        $ids = [];
        $attributes = [];
        foreach (self::modules() as $file) {
            $source = (string) file_get_contents($file);
            $module = basename($file);
            foreach (["/getElementById\('([a-zA-Z0-9_-]+)'\)/", "/['\"]#([a-zA-Z0-9_-]+)['\"]/"] as $pattern) {
                preg_match_all($pattern, $source, $found);
                foreach ($found[1] as $hook) {
                    $ids[$hook][] = $module;
                }
            }
            foreach (["/\[(data-[a-z0-9-]+)/", "/(?:get|set|remove)Attribute\('(data-[a-z0-9-]+)'/"] as $pattern) {
                preg_match_all($pattern, $source, $found);
                foreach ($found[1] as $hook) {
                    $attributes[$hook][] = $module;
                }
            }
        }
        ksort($ids);
        ksort($attributes);

        return ['ids' => $ids, 'attributes' => $attributes];
    }

    /**
     * The shell's pages, rendered as full as a live app renders them, concatenated.
     *
     * The subscribers are how a surface is given something to show without inventing a data seam: each
     * renderer dispatches its own `before_render` with MUTABLE props, which is the plugin extension point
     * the package documents — so this exercises that seam too.
     */
    private static function pages(): string
    {
        $events = new EventDispatcher(new NullLogger());
        $events->subscribe(ShellController::COMPOSE_EVENT, static function (string $name, array $payload): void {
            $composition = $payload['composition'] ?? null;
            if ($composition instanceof ShellComposition) {
                $composition->addPanel('probe', 'Probe', '<p>a plugin\'s panel</p>');
            }
        });
        $events->subscribe(CapabilitiesScreen::BEFORE_RENDER, static function (string $name, array $payload): void {
            $subject = $payload['capabilities'] ?? null;
            if ($subject instanceof ComposerRender) {
                $subject->props['available'] = [['package' => 'milpa/devtools', 'command' => 'composer require milpa/devtools', 'title' => 'Dev tools']];
            }
        });
        $events->subscribe(ScreenPreview::BEFORE_RENDER, static function (string $name, array $payload): void {
            $subject = $payload['screens'] ?? null;
            if ($subject instanceof ComposerRender) {
                $subject->props['screens'] = [['name' => 'board', 'type' => 'screen', 'served_at' => '/live/page?component=board']];
            }
        });
        $events->subscribe(DecisionsInbox::BEFORE_RENDER, static function (string $name, array $payload): void {
            $subject = $payload['decisions'] ?? null;
            if ($subject instanceof ComposerRender) {
                $subject->props['pending'] = [['session' => 's1', 'goal' => 'g', 'question' => 'may I?', 'operation' => 'capabilities:enable', 'reason' => 'it installs']];
            }
        });

        // A real session on disk: the work board's cards and columns, and the counters the panels show.
        $dir = sys_get_temp_dir() . '/milpa-dom-contract-' . uniqid('', true);
        mkdir($dir);
        file_put_contents($dir . '/s1.json', json_encode([
            'goal' => 'Prove the contract', 'state' => 'working', 'turns' => 1, 'tool_calls' => 2,
            'work' => [['title' => 'a card', 'status' => 'pending', 'origin' => 'planned']],
        ], \JSON_THROW_ON_ERROR));
        $log = new ShellEventLog($dir . '/events.log');
        $log->append(new ShellEvent('gate.opened', ['operation' => 'x']));
        $data = new DesktopData(new DIContainer(), $log, $dir);

        $withData = new ShellController($events, null, $data);
        // …and one page with NO data at all: the empty states are markup too, and the Activity tab's
        // `data-activity-empty` only exists while nothing has been recorded.
        $bare = new ShellController(new EventDispatcher(new NullLogger()));

        // …and the surface this package contributes to SOMEBODY ELSE's page (greenhouse decisions/0211,
        // slice 3): the admin's Agent region, which composes the SAME surfaces into a host's document.
        $guest = (new AgentViewRenderer($withData->components(), $data))->render(
            new AgentViewComponent(),
            new RenderRequest(new ComponentContext('milpa-admin-section-agent', route: '/milpa/admin'), ['gate' => 'loopback']),
        )->output;

        $pages = (string) $withData->shell(new ServerRequest('GET', '/desktop'))->getBody()
            . (string) $withData->shell(new ServerRequest('GET', '/desktop?embed=1'))->getBody()
            . (string) $bare->shell(new ServerRequest('GET', '/desktop'))->getBody()
            . $guest;

        unlink($dir . '/s1.json');
        unlink($dir . '/events.log');
        rmdir($dir);

        return $pages;
    }

    public function testEveryElementIdAModuleResolvesIsOneTheServerPrints(): void
    {
        $pages = self::pages();
        $ids = self::hooks()['ids'];

        // The positive control for the PARSER: without these it would pass on a package that reaches for
        // nothing at all.
        foreach (['milpa-activity', 'milpa-charcount', 'milpa-search', 'milpa-desktop-i18n'] as $known) {
            self::assertArrayHasKey($known, $ids, 'the parser reads the ids the modules resolve');
        }
        self::assertGreaterThanOrEqual(10, \count($ids));

        foreach ($ids as $id => $modules) {
            self::assertStringContainsString(
                'id="' . $id . '"',
                $pages,
                \sprintf('«#%s» is resolved by %s and painted by nobody', $id, implode(', ', array_unique($modules))),
            );
        }
    }

    public function testEveryDataHookAModuleReachesForIsOneTheServerPrints(): void
    {
        $pages = self::pages();
        $attributes = self::hooks()['attributes'];

        // The positive control for the PARSER: it must see the hooks read through a `[data-…]` selector
        // AND the ones read through `getAttribute('data-…')`, which are two different forms.
        foreach (['data-thinking-body', 'data-cap-enable', 'data-command', 'data-status'] as $known) {
            self::assertArrayHasKey($known, $attributes, 'the parser reads the data hooks the modules use');
        }
        self::assertGreaterThanOrEqual(40, \count($attributes));

        foreach ($attributes as $hook => $modules) {
            if (isset(self::WRITTEN_BY_A_MODULE[$hook])) {
                self::assertStringNotContainsString(
                    $hook,
                    $pages,
                    \sprintf('«%s» is declared as written by a module, but the server prints it — say which it is', $hook),
                );
                continue;
            }
            self::assertStringContainsString(
                $hook,
                $pages,
                \sprintf('«%s» is reached for by %s and painted by nobody', $hook, implode(', ', array_unique($modules))),
            );
        }
    }

    /**
     * The other direction, for the hooks a RENDERER paints that only its module gives meaning to.
     *
     * Read alone, the two tests above would pass on a package whose modules reached for nothing; read
     * together with this one, a hook cannot be dropped from either side in silence. This is the pairing
     * `DeclaredViewsTest::testAMovedBehaviourIsGoneFromThePageAndPresentInItsModule` makes for behaviour,
     * made for markup.
     */
    public function testTheThinkingPrototypePaintsExactlyTheRegionsItsModuleFills(): void
    {
        $pages = self::pages();
        $module = (string) file_get_contents(\dirname(__DIR__) . '/resources/components/desktop-thinking/desktop-thinking.js');

        // The spark and the dots are the COMPONENT's own: the elapsed replaces the label alone, which is
        // why the label has a region of its own. A stub that omitted the spark hid exactly this.
        foreach (['data-thinking-spark', 'data-thinking-label', 'data-thinking-body', 'data-thinking-head', 'data-thinking-active'] as $region) {
            self::assertStringContainsString($region, $pages, $region . ' is painted by Live\Thinking');
        }
        foreach (['data-thinking-label', 'data-thinking-body', 'data-thinking-active'] as $region) {
            self::assertStringContainsString($region, $module, $region . ' is filled by desktop-thinking.js');
        }
        self::assertStringNotContainsString('data-thinking-spark', $module, 'the spark is the component\'s look; no module touches it');
    }
}

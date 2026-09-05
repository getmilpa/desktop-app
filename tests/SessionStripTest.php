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
use Milpa\DesktopApp\Data\DesktopData;
use Milpa\DesktopApp\I18n\Catalog;
use Milpa\DesktopApp\Live\SessionStrip;
use Milpa\DesktopApp\Live\SessionStripComponent;
use Milpa\Eventing\EventDispatcher;
use Milpa\Live\Contracts\Component\ComponentDefinitionInterface;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\InteractionRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The session strip of embed mode is a Milpa Component like every other shell surface (greenhouse decisions/0210
 * on the rule of decisions/0189): a declared contract, a signed envelope, lifecycle events — never a hand-written
 * view.
 */
final class SessionStripTest extends TestCase
{
    public function testItRendersAsAMilpaLiveComponentWithItsSignedEnvelope(): void
    {
        $html = (new SessionStrip('secret'))->render();

        self::assertStringContainsString('<div class="milpa-session-strip" id="milpa-session-strip" role="region" aria-label="Session" data-milpa-runtime="alpine" data-milpa-component="desktop-session-strip" data-milpa-component-id="session-strip" x-data>', $html);
        self::assertStringContainsString('data-milpa-state="session-strip"', $html);
        self::assertStringContainsString('security="signed"', $html);
        // No session: the goal line and the picker say so; the «New session» control is the shell's hook.
        self::assertStringContainsString('<span class="milpa-session-strip__goal" id="milpa-session-strip-goal">No session open</span>', $html);
        self::assertStringContainsString('<select class="mui-select mui-select--sm" id="milpa-embed-session" aria-label="Session"><option value="" selected disabled>No session open</option></select>', $html);
        self::assertStringContainsString('<button type="button" class="mui-btn mui-btn--subtle mui-btn--sm" data-new-session>New session</button>', $html);
    }

    public function testItListsTheSessionsWithTheCurrentOneSelectedFromTheSameDataTheSidebarReads(): void
    {
        $dir = sys_get_temp_dir() . '/milpa-strip-' . uniqid('', true);
        mkdir($dir);
        file_put_contents($dir . '/aaa11111.json', json_encode(['goal' => 'First goal', 'state' => 'ready'], JSON_THROW_ON_ERROR));
        file_put_contents($dir . '/bbb22222.json', json_encode(['goal' => 'Second goal', 'state' => 'working'], JSON_THROW_ON_ERROR));
        $data = new DesktopData(new DIContainer(), null, $dir);
        $data->select('aaa11111');

        $html = (new SessionStrip('secret', $data))->render();

        self::assertStringContainsString('<span class="milpa-session-strip__goal" id="milpa-session-strip-goal">First goal</span>', $html);
        self::assertStringContainsString('<option value="aaa11111" selected>First goal · ready</option>', $html);
        self::assertStringContainsString('<option value="bbb22222">Second goal · working</option>', $html);
        self::assertStringNotContainsString('No session open', $html);

        unlink($dir . '/aaa11111.json');
        unlink($dir . '/bbb22222.json');
        rmdir($dir);
    }

    public function testItEmitsRenderEventsSoPluginsCanExtendIt(): void
    {
        $events = new EventDispatcher(new NullLogger());
        $events->subscribe(SessionStrip::BEFORE_RENDER, static function (string $n, array $p): void {
            // Sessions but none selected: a placeholder option leads, the goal line says so, garbage rows are
            // skipped and a session without a goal is named by its id.
            $p['sessionStrip']->props['sessions'] = [['id' => 's1', 'goal' => '', 'state' => 'idle'], 'not-a-session'];
            $p['sessionStrip']->props['activeSession'] = '';
        });
        $events->subscribe(SessionStrip::AFTER_RENDER, static function (string $n, array $p): void {
            $p['sessionStrip']->html .= '<!-- strip extended -->';
        });

        $html = (new SessionStrip('secret', null, $events))->render();

        self::assertStringContainsString('strip extended', $html, 'after_render changed the html');
        self::assertStringContainsString('<option value="" selected disabled>Pick a session</option><option value="s1">s1 · idle</option>', $html, 'before_render changed the props');
        self::assertStringContainsString('>No session open</span>', $html);
    }

    public function testItSpeaksTheCatalogGivenAndEscapesWhatItPaints(): void
    {
        $events = new EventDispatcher(new NullLogger());
        $events->subscribe(SessionStrip::BEFORE_RENDER, static function (string $n, array $p): void {
            $p['sessionStrip']->props['sessions'] = [['id' => 's<1>', 'goal' => 'Fix "it"', 'state' => 'ready']];
            $p['sessionStrip']->props['activeSession'] = 's<1>';
        });

        $html = (new SessionStrip('secret', null, $events, new Catalog('es')))->render();

        self::assertStringContainsString('aria-label="Sesión"', $html);
        self::assertStringContainsString('<option value="s&lt;1&gt;" selected>Fix &quot;it&quot; · ready</option>', $html);
        self::assertStringContainsString('>Fix &quot;it&quot;</span>', $html);
        self::assertStringContainsString('data-new-session>Nueva sesión</button>', $html);
        self::assertStringNotContainsString('<1>', $html);
    }

    public function testTheComponentDeclaresItsContractAndNoAction(): void
    {
        $contract = SessionStripComponent::contract();
        self::assertInstanceOf(ComponentDefinitionInterface::class, new SessionStripComponent());
        self::assertSame('desktop-session-strip', $contract->name);
        self::assertSame(['sessions', 'activeSession'], array_keys($contract->propsSchema));
        self::assertSame(['activeSession' => ['type' => 'string']], $contract->stateSchema);
        self::assertSame([], $contract->actions, 'a pick navigates, «New session» opens the shell\'s overlay: no live action');

        $component = new SessionStripComponent();
        $state = $component->mount(['sessions' => [['id' => 'a', 'goal' => 'g', 'state' => 's']], 'activeSession' => 'a'], new ComponentContext('session-strip'));
        self::assertSame('session-strip', $state->componentId);
        self::assertSame('desktop-session-strip', $state->componentName);
        self::assertSame(['activeSession' => 'a'], $state->data);
        self::assertSame([['id' => 'a', 'goal' => 'g', 'state' => 's']], $state->meta['sessions']);

        $bare = $component->mount(['sessions' => 'nope'], new ComponentContext('session-strip'));
        self::assertSame(['activeSession' => ''], $bare->data);
        self::assertSame([], $bare->meta['sessions'], 'a malformed list mounts as none');

        $result = $component->handle(new InteractionRequest('session-strip', 'desktop-session-strip', 'select', $state, ['session' => 'b']));
        self::assertSame($state, $result->state, 'the state is returned unchanged, never omitted');
        self::assertStringContainsString('declares no actions', $result->errors['action']);
    }
}

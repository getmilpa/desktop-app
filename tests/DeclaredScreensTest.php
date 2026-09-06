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
use Milpa\DesktopApp\Controllers\LiveController;
use Milpa\DesktopApp\Data\DesktopData;
use Milpa\DesktopApp\Data\DesktopStore;
use Milpa\DesktopApp\I18n\Catalog;
use Milpa\DesktopApp\Live\AuthOverlay;
use Milpa\DesktopApp\Live\AuthOverlayComponent;
use Milpa\DesktopApp\Live\ComposerField;
use Milpa\DesktopApp\Live\DesktopComponents;
use Milpa\DesktopApp\Live\SettingsScreen;
use Milpa\DesktopApp\Live\SettingsScreenComponent;
use Milpa\Eventing\EventDispatcher;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\InteractionRequest;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The two screens phase B took out of the shell's template (greenhouse decisions/0211): the Settings
 * screen and the entry overlay.
 *
 * They were the last raw HTML the shell hand-wrote — a `<div class="view">` block each, with their
 * behaviour in the page's inline script. As declared views they are components like every other surface:
 * a contract, a signed envelope, lifecycle events a plugin can extend them through, a stylesheet and a
 * client module. What is asserted here is that shape, and the two facts the doctrine actually cares
 * about: the save NEVER says «Saved» on its own (the door does), and the overlay authenticates nobody.
 */
final class DeclaredScreensTest extends TestCase
{
    public function testTheSettingsScreenIsAComponentWithItsEnvelopeAndItsBindings(): void
    {
        $html = (new SettingsScreen('secret'))->render();

        self::assertStringContainsString('data-milpa-component="desktop-settings"', $html);
        self::assertStringContainsString('data-milpa-state="settings"', $html);
        self::assertStringContainsString('security="signed"', $html);
        self::assertStringContainsString('x-data="desktopSettings()"', $html);
        // The four cards the screen has always shown, by their headings.
        foreach (['Model and provider', 'Default autonomy', 'Context and storage', 'Appearance'] as $card) {
            self::assertStringContainsString($card, $html);
        }
        // The ids the shell script (and any plugin) has always looked up are unchanged.
        foreach (['set-prov', 'set-end', 'set-stream', 'set-comp', 'set-path', 'milpa-save-settings', 'milpa-discard', 'milpa-settings-saved'] as $id) {
            self::assertStringContainsString('id="' . $id . '"', $html, $id);
        }
        // Save and Discard are the component's verbs; the badge BINDS to the shared `settings.saved` signal.
        self::assertStringContainsString('@click="save()"', $html);
        self::assertStringContainsString('@click="discard()"', $html);
        self::assertStringContainsString('x-text="savedText" :hidden="!saved"', $html);
        self::assertStringContainsString(":class=\"{ 'mui-badge--success': savedOk, 'mui-badge--warning': !savedOk }\"", $html);
        // The three theme buttons set the SHARED theme and bind their pressed state to it.
        foreach (['system', 'dark', 'light'] as $choice) {
            self::assertStringContainsString('data-theme-set="' . $choice . '"', $html);
            self::assertStringContainsString('@click="setTheme(\'' . $choice . '\')"', $html);
            self::assertStringContainsString(':aria-pressed="isTheme(\'' . $choice . '\')"', $html);
        }
        // Its look is a declared file: not one inline style attribute is left.
        self::assertStringNotContainsString('style=', $html);
    }

    public function testTheSettingsScreenShowsThePersistedEndpointAndSpeaksTheCatalog(): void
    {
        $dir = sys_get_temp_dir() . '/milpa-settings-screen-' . uniqid('', true);
        mkdir($dir);
        $store = new DesktopStore($dir . '/sessions', $dir . '/settings.json');
        $store->saveSettings(['endpoint' => 'http://persisted.test/v1']);
        $data = new DesktopData(new DIContainer(), null, '', $store);

        $html = (new SettingsScreen('secret', $data, null, new Catalog('es')))->render();

        self::assertStringContainsString('value="http://persisted.test/v1"', $html, 'the persisted endpoint wins');
        self::assertStringContainsString('>Guardado</span>', $html, 'the badge seed speaks the declared locale');

        // With no persisted endpoint the configured one stands; with no data seam at all, the default.
        $configured = (new SettingsScreen('secret', new DesktopData(new DIContainer(), null, '', new DesktopStore($dir . '/s2', $dir . '/none.json'))))->render();
        self::assertMatchesRegularExpression('/id="set-end"[^>]*value="http/', $configured);
        self::assertStringContainsString('value="http://llama.local:11438"', (new SettingsScreen('secret'))->render());

        unlink($dir . '/settings.json');
        rmdir($dir);
    }

    public function testTheSettingsScreenEmitsRenderEventsSoPluginsCanExtendIt(): void
    {
        $events = new EventDispatcher(new NullLogger());
        $events->subscribe(SettingsScreen::BEFORE_RENDER, static function (string $n, array $p): void {
            $p['settings']->props['endpoint'] = 'http://changed.test';
        });
        $events->subscribe(SettingsScreen::AFTER_RENDER, static function (string $n, array $p): void {
            $p['settings']->html .= '<!-- settings extended -->';
        });

        $html = (new SettingsScreen('secret', null, $events))->render();

        self::assertStringContainsString('value="http://changed.test"', $html, 'before_render changed the props');
        self::assertStringContainsString('settings extended', $html, 'after_render changed the html');
    }

    public function testTheSettingsComponentOnlyEverProjectsWhatTheDoorAnswered(): void
    {
        $contract = SettingsScreenComponent::contract();
        self::assertSame('desktop-settings', $contract->name);
        self::assertArrayHasKey('save', $contract->actions);

        $component = new SettingsScreenComponent();
        $state = $component->mount(['endpoint' => 'http://x', 'savedLabel' => 'Guardado'], new ComponentContext('settings'));
        self::assertFalse($state->data['saved'], 'nothing is saved on mount');
        self::assertSame('http://x', $state->meta['endpoint']);

        $result = $component->handle(new InteractionRequest('settings', 'desktop-settings', 'save', $state, []));

        self::assertTrue($result->state->data['saved']);
        self::assertSame(
            [['type' => 'state', 'key' => 'settings.saved', 'value' => ['ok' => true, 'text' => 'Guardado']]],
            $result->effects,
            'the badge is a SIGNAL, and its copy comes from the catalog the server mounted with',
        );
    }

    public function testTheEntryOverlayIsAComponentHiddenBehindItsOwnSignal(): void
    {
        $html = (new AuthOverlay('secret'))->render();

        self::assertStringContainsString('data-milpa-component="desktop-auth"', $html);
        self::assertStringContainsString('data-milpa-state="auth"', $html);
        self::assertStringContainsString('security="signed"', $html);
        self::assertStringContainsString('x-data="desktopAuth()"', $html);
        // A `.view` like the screens it sits over, hidden by the server and bound to its own signal.
        self::assertStringContainsString('data-view="auth"', $html);
        self::assertStringContainsString('id="milpa-auth"', $html);
        self::assertStringContainsString('hidden :hidden="!open"', $html);
        self::assertStringContainsString('id="auth-app"', $html);
        self::assertStringContainsString('id="milpa-auth-enter"', $html);
        self::assertStringContainsString('@click="enter()"', $html);
        // The honest sentence the overlay exists to say — a system user is NOT a verified identity.
        self::assertStringContainsString('Your system user is not a verified identity', $html);
        self::assertStringContainsString('Authorizing in a session grants the operation; it is not signing the call.', $html);
        // The provider option names the app's REAL model, never a provider it does not have.
        self::assertStringContainsString('Local model · qwen3.8-27b (http://llama.local:11438)', $html);
        // Its look is a declared file.
        self::assertStringNotContainsString('style=', $html);
    }

    public function testTheEntryOverlayEmitsRenderEventsSoPluginsCanExtendIt(): void
    {
        $events = new EventDispatcher(new NullLogger());
        $events->subscribe(AuthOverlay::BEFORE_RENDER, static function (string $n, array $p): void {
            $p['auth']->props['app'] = 'acme/app';
        });
        $events->subscribe(AuthOverlay::AFTER_RENDER, static function (string $n, array $p): void {
            $p['auth']->html .= '<!-- auth extended -->';
        });

        $html = (new AuthOverlay('secret', null, $events))->render();

        self::assertStringContainsString('value="acme/app"', $html, 'before_render changed the props');
        self::assertStringContainsString('auth extended', $html, 'after_render changed the html');
    }

    public function testTheAuthComponentMountsClosedAndPutsNothingOnTheWire(): void
    {
        $contract = AuthOverlayComponent::contract();
        self::assertSame('desktop-auth', $contract->name);
        self::assertSame([], $contract->actions, 'visibility is the client signal; the overlay declares no wire action');

        $component = new AuthOverlayComponent();
        $state = $component->mount(['app' => 'acme/app', 'provider' => 'Local model'], new ComponentContext('auth'));
        self::assertFalse($state->data['open'], 'nothing runs on open — the overlay starts closed');
        self::assertSame('acme/app', $state->meta['app']);

        // Reached directly it echoes; it invents no state the renderer will contradict.
        $echo = $component->handle(new InteractionRequest('auth', 'desktop-auth', 'open', $state, []));
        self::assertSame($state, $echo->state);
        self::assertSame([], $echo->effects);
    }

    /**
     * The falsifier for the shared endpoint (greenhouse decisions/0211): the Desktop now serves ONE
     * registry to `POST /desktop/live`, so every declared component's actions became reachable over the
     * wire at once. The overlay declares none — measured here by POSTING a real signed envelope with
     * `open` and reading the refusal, with the composer's `change` as the positive control that the same
     * endpoint, the same key and the same session DO answer.
     */
    public function testTheEndpointRefusesAnOverlayActionAndStillAnswersARealOne(): void
    {
        $registry = new DesktopComponents('one-key', 'csrf-key');
        $registry->declare(new AuthOverlayComponent(), static fn (array $props): string => (new AuthOverlay('one-key'))->render());
        $controller = new LiveController($registry->endpoint());

        $overlay = (new AuthOverlay('one-key'))->render();
        self::assertSame(1, preg_match('#(<milpa-state\b.*?</milpa-state>)#s', $overlay, $m));
        $sid = 'sess-auth-1';
        $post = static function (string $envelope, string $action) use ($controller, $registry, $sid): array {
            $body = (string) json_encode(['action' => $action, 'state' => $envelope, 'payload' => [], 'sessionId' => $sid, 'csrfToken' => $registry->csrfToken($sid)]);
            $decoded = json_decode((string) $controller->live(new ServerRequest('POST', '/desktop/live', [], $body))->getBody(), true);

            return \is_array($decoded) ? $decoded : [];
        };

        $refused = $post($m[1], 'open');
        self::assertFalse($refused['ok'] ?? true, 'the overlay opens through its signal, never through the endpoint');
        self::assertSame('action_not_allowed', $refused['error'] ?? null, 'refused for the RIGHT reason — the contract, not a bad signature');

        // Positive control: the same endpoint, key and session answer a component that DOES declare an action.
        $field = new ComposerField('one-key', 'csrf-key', registry: $registry);
        self::assertSame(1, preg_match('#(<milpa-state\b.*?</milpa-state>)#s', $field->render(), $c));
        $body = (string) json_encode(['action' => 'change', 'state' => $c[1], 'payload' => ['value' => 'hola'], 'sessionId' => $sid, 'csrfToken' => $registry->csrfToken($sid)]);
        $ok = json_decode((string) $controller->live(new ServerRequest('POST', '/desktop/live', [], $body))->getBody(), true);
        self::assertIsArray($ok);
        self::assertTrue($ok['ok'] ?? false, 'the instrument discriminates: a declared action on the same wire is answered');
    }
}

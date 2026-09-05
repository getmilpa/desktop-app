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

use Milpa\DesktopApp\DesktopSettings;
use Milpa\DesktopApp\Http\LoopbackOnlyMiddleware;
use Milpa\DesktopApp\Tests\Fixtures\AllowAllMiddleware;
use Milpa\Runtime\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Server\MiddlewareInterface;

/**
 * The Desktop's door is judged by the same rule as the admin's (greenhouse decisions/0209, copied from
 * 0204): only a literally empty list opens it; anything misdeclared falls to loopback-only, whole.
 */
final class DesktopSettingsTest extends TestCase
{
    public function testDefaultsWithoutConfig(): void
    {
        $settings = DesktopSettings::fromConfig(null);

        self::assertSame('en', $settings->locale);
        self::assertSame([LoopbackOnlyMiddleware::class], $settings->middleware);
        self::assertFalse($settings->declared());
        self::assertFalse($settings->malformed());
        self::assertSame([], $settings->rejected());
        self::assertSame(['locale' => 'default', 'middleware' => 'default'], $settings->sources());
        self::assertSame('loopback', $settings->gateKind());
        self::assertSame('loopback', $settings->gateLabel());
        self::assertSame('en', $settings->catalog()->locale());
    }

    public function testDeclaredValuesWin(): void
    {
        $settings = DesktopSettings::fromConfig(new Config([
            'desktop' => ['locale' => 'es', 'middleware' => []],
        ]));

        self::assertSame('es', $settings->locale);
        self::assertSame([], $settings->middleware, 'an empty list opens the Desktop on purpose');
        self::assertTrue($settings->declared());
        self::assertSame([], $settings->rejected());
        self::assertSame(['locale' => 'config', 'middleware' => 'config'], $settings->sources());
        self::assertSame('es', $settings->catalog()->locale());
        self::assertSame('puerta: abierta', $settings->catalog()->tr('chip.gate', $settings->catalog()->tr('gate.kind.' . $settings->gateLabel())));
    }

    public function testRejectsWhatItCannotUseWithoutPaintingItDefault(): void
    {
        $settings = DesktopSettings::fromConfig(new Config([
            'desktop' => ['locale' => 'fr', 'middleware' => 'not-a-list', 'mercure' => ['hub_url' => 'http://hub']],
        ]));

        self::assertSame('en', $settings->locale, 'a locale the catalog lacks is not the Desktop\'s locale');
        self::assertSame([], $settings->middleware, 'nothing readable entry by entry was declared');
        self::assertTrue($settings->malformed());
        self::assertSame([LoopbackOnlyMiddleware::class], $settings->effectiveMiddleware());
        self::assertSame('fallback', $settings->gateKind());
        self::assertSame(['locale' => 'rejected', 'middleware' => 'rejected'], $settings->sources(), 'what the app wrote and the Desktop refused is a third state, never default');
        self::assertSame(['locale' => 'fr', 'middleware' => 'string'], $settings->rejected());

        $typed = DesktopSettings::fromConfig(new Config(['desktop' => ['locale' => 42, 'middleware' => true]]));
        self::assertSame(['locale' => 'int', 'middleware' => 'bool'], $typed->rejected(), 'the type for a non-string');
        self::assertSame('(empty)', DesktopSettings::fromConfig(new Config(['desktop' => ['locale' => '']]))->rejected()['locale'], 'an empty string is named as such');
    }

    public function testRecordsPerKeyWhetherTheAppDeclaredItTheDefaultIsRunningOrTheDesktopRefusedIt(): void
    {
        $declared = DesktopSettings::fromConfig(new Config(['desktop' => ['middleware' => [LoopbackOnlyMiddleware::class]]]));
        self::assertTrue($declared->declared());
        self::assertSame('config', $declared->sources()['middleware'], 'declaring the default value is still declaring');
        self::assertSame('default', $declared->sources()['locale']);
        self::assertSame('loopback', $declared->gateKind(), 'the strict gate, declared, is still the strict gate');

        $nulls = DesktopSettings::fromConfig(new Config(['desktop' => ['locale' => null, 'middleware' => null]]));
        self::assertSame(['locale' => 'default', 'middleware' => 'default'], $nulls->sources(), 'null is not a declaration');
        self::assertSame([], $nulls->rejected());

        self::assertTrue(DesktopSettings::fromConfig(new Config(['desktop' => []]))->declared(), 'an empty desktop key exists');
        self::assertTrue(DesktopSettings::fromConfig(new Config(['desktop' => ['mercure' => []]]))->declared(), 'the key other wiring declares counts too');
        self::assertFalse(DesktopSettings::fromConfig(new Config(['desktop' => 'yes']))->declared(), 'a key the Desktop cannot read is not a declaration');

        $direct = new DesktopSettings(sources: ['locale' => 'config', 'middleware' => 'weird']);
        self::assertSame('config', $direct->sources()['locale']);
        self::assertSame('default', $direct->sources()['middleware'], 'anything but config is default');

        $described = new DesktopSettings(sources: ['locale' => 'config'], rejected: ['locale' => 'fr']);
        self::assertSame('rejected', $described->sources()['locale'], 'a description wins over whatever the source says');
        self::assertSame(['locale' => 'fr'], $described->rejected());
    }

    /**
     * Every shape of `desktop.middleware` that is not a list of PSR-15 middleware class names — each one
     * a way a looser rule would let the Desktop open from the LAN, or die with a 500.
     *
     * @return iterable<string, array{0: mixed, 1: list<string>, 2: bool}> the declaration, what `unresolvedMiddleware()` names, whether it was not a list at all
     */
    public static function misdeclaredGates(): iterable
    {
        yield 'a non-string entry: an int' => [[42], ['int (not a class name)'], false];
        yield 'a non-string entry: null' => [[null], ['null (not a class name)'], false];
        yield 'a non-string entry: an instance' => [[new \stdClass()], ['stdClass (not a class name)'], false];
        yield 'a non-string entry: an instance of a real middleware' => [[new AllowAllMiddleware()], [AllowAllMiddleware::class . ' (not a class name)'], false];
        yield 'a non-string entry: a nested list' => [[[LoopbackOnlyMiddleware::class]], ['array (not a class name)'], false];
        yield 'an associative map' => [[LoopbackOnlyMiddleware::class => true], ['array (not a list)'], true];
        yield 'a string, not a list' => ['Acme\\Nope', ['string (not a list)'], true];
        yield 'a string naming a real middleware, still not a list' => [LoopbackOnlyMiddleware::class, ['string (not a list)'], true];
        yield 'true' => [true, ['bool (not a list)'], true];
        yield 'an int' => [42, ['int (not a list)'], true];
        yield 'an empty string entry' => [[''], ['(empty)'], false];
        yield 'a class that does not exist' => [['Acme\\Nope'], ['Acme\\Nope (class does not exist)'], false];
        yield 'two classes that do not exist' => [['Acme\\Nope', 'Acme\\Missing'], ['Acme\\Nope (class does not exist)', 'Acme\\Missing (class does not exist)'], false];
        yield 'the interface itself (is_a alone would accept it)' => [[MiddlewareInterface::class], [MiddlewareInterface::class . ' (class does not exist)'], false];
        yield 'a class that exists but is not a middleware: stdClass' => [[\stdClass::class], ['stdClass (not a PSR-15 middleware)'], false];
        yield 'a class that exists but is not a middleware: DateTimeImmutable' => [[\DateTimeImmutable::class], ['DateTimeImmutable (not a PSR-15 middleware)'], false];
        yield 'half a gate: a real middleware next to an int' => [[AllowAllMiddleware::class, 42], ['int (not a class name)'], false];
        yield 'half a gate: a real middleware next to a typo' => [[AllowAllMiddleware::class, 'Acme\\Nope'], ['Acme\\Nope (class does not exist)'], false];
        yield 'half a gate: the strict gate next to a typo' => [[LoopbackOnlyMiddleware::class, 'Acme\\Nope'], ['Acme\\Nope (class does not exist)'], false];
    }

    /**
     * @param list<string> $unresolved
     */
    #[DataProvider('misdeclaredGates')]
    public function testEveryMisdeclarationFallsBackToLoopbackOnlyAndIsNamed(mixed $declared, array $unresolved, bool $malformed): void
    {
        $settings = DesktopSettings::fromConfig(new Config(['desktop' => ['middleware' => $declared]]));

        self::assertSame([LoopbackOnlyMiddleware::class], $settings->effectiveMiddleware(), 'the strict gate, and only it — never open, never the half that loads');
        self::assertSame('fallback', $settings->gateKind());
        self::assertSame('fallback', $settings->gateLabel());
        self::assertSame($unresolved, $settings->unresolvedMiddleware());
        self::assertSame($malformed, $settings->malformed());
        self::assertSame($malformed ? 'rejected' : 'config', $settings->sources()['middleware'], 'a list with a bad entry was declared; a non-list was rejected whole');
        if (\is_array($declared) && array_is_list($declared)) {
            self::assertSame($declared, $settings->middleware, 'the declaration is kept exactly as written');
        }
        if ($malformed) {
            self::assertSame(get_debug_type($declared), $settings->rejected()['middleware']);
        }
    }

    public function testThePositiveControlsALiterallyEmptyListOpensAndARealMiddlewareIsCarried(): void
    {
        $open = DesktopSettings::fromConfig(new Config(['desktop' => ['middleware' => []]]));
        self::assertSame('open', $open->gateKind());
        self::assertSame([], $open->effectiveMiddleware(), 'the one declaration that opens: a literally empty list');
        self::assertSame([], $open->unresolvedMiddleware());
        self::assertFalse($open->malformed());
        self::assertSame('config', $open->sources()['middleware']);

        $custom = DesktopSettings::fromConfig(new Config(['desktop' => ['middleware' => [AllowAllMiddleware::class]]]));
        self::assertSame('custom', $custom->gateKind());
        self::assertSame([AllowAllMiddleware::class], $custom->effectiveMiddleware(), 'a real PSR-15 class is carried as declared');
        self::assertSame([], $custom->unresolvedMiddleware());

        $stacked = DesktopSettings::fromConfig(new Config(['desktop' => ['middleware' => [LoopbackOnlyMiddleware::class, AllowAllMiddleware::class]]]));
        self::assertSame('custom', $stacked->gateKind(), 'loopback plus something else is the app\'s own stack');
        self::assertSame([LoopbackOnlyMiddleware::class, AllowAllMiddleware::class], $stacked->effectiveMiddleware());

        self::assertNull(DesktopSettings::middlewareDefect(AllowAllMiddleware::class));
        self::assertNull(DesktopSettings::middlewareDefect(LoopbackOnlyMiddleware::class));
        self::assertSame('Acme\\Nope (class does not exist)', DesktopSettings::middlewareDefect('Acme\\Nope'));
    }

    public function testTheRuleHoldsHoweverTheSettingsWereBuilt(): void
    {
        $typo = new DesktopSettings(middleware: ['Acme\\Nope']);
        self::assertSame([LoopbackOnlyMiddleware::class], $typo->effectiveMiddleware());
        self::assertSame('fallback', $typo->gateKind());
        self::assertFalse($typo->malformed());

        $map = new DesktopSettings(middleware: ['a' => AllowAllMiddleware::class]);
        self::assertTrue($map->malformed(), 'a map is not a list, however it got here');
        self::assertSame(['array (not a list)'], $map->unresolvedMiddleware());
        self::assertSame([LoopbackOnlyMiddleware::class], $map->effectiveMiddleware());
        self::assertSame('fallback', $map->gateKind());

        $scalar = new DesktopSettings(middleware: [], rejected: ['middleware' => 'string']);
        self::assertTrue($scalar->malformed(), 'an empty list with the middleware key rejected is not an open Desktop');
        self::assertSame(['string (not a list)'], $scalar->unresolvedMiddleware());
        self::assertSame([LoopbackOnlyMiddleware::class], $scalar->effectiveMiddleware());
        self::assertSame('rejected', $scalar->sources()['middleware']);

        $instance = new DesktopSettings(middleware: [new AllowAllMiddleware()]);
        self::assertSame([AllowAllMiddleware::class . ' (not a class name)'], $instance->unresolvedMiddleware(), 'an instance is not a class name');
        self::assertSame([LoopbackOnlyMiddleware::class], $instance->effectiveMiddleware());
    }

    public function testTheLabelNamesThePasskeyGateWhenItIsTheWholeStackAndIsTheKindOtherwise(): void
    {
        self::assertSame('Milpa\\AppRuntime\\Web\\PasskeyGateMiddleware', DesktopSettings::PASSKEY_GATE, 'named as a string: the Desktop does not import app-runtime');

        // The class is not loadable in this package (app-runtime is not a dependency), so a declared passkey
        // gate is a fallback here — until a stand-in bearing its exact name is loaded, below, and the string
        // rule is exercised end to end, case-insensitively.
        if (!class_exists(DesktopSettings::PASSKEY_GATE, false)) {
            $unloadable = DesktopSettings::fromConfig(new Config(['desktop' => ['middleware' => [DesktopSettings::PASSKEY_GATE]]]));
            self::assertSame('fallback', $unloadable->gateKind(), 'a passkey gate that cannot be loaded is a fallback like any other');
            self::assertSame('fallback', $unloadable->gateLabel());

            require_once __DIR__ . '/Fixtures/app-runtime-passkey-gate.php';
        }

        $passkey = DesktopSettings::fromConfig(new Config(['desktop' => ['middleware' => [DesktopSettings::PASSKEY_GATE]]]));
        self::assertSame('custom', $passkey->gateKind(), 'the kind is unchanged: it is the app\'s own stack');
        self::assertSame('passkey', $passkey->gateLabel(), 'the label names it');
        self::assertSame([DesktopSettings::PASSKEY_GATE], $passkey->effectiveMiddleware(), 'the routes carry the class as declared');

        $leadingSlash = new DesktopSettings(middleware: ['\\' . DesktopSettings::PASSKEY_GATE]);
        self::assertSame('passkey', $leadingSlash->gateLabel(), 'however the name was spelled: a leading backslash');

        $lowercase = new DesktopSettings(middleware: [strtolower(DesktopSettings::PASSKEY_GATE)]);
        self::assertSame('passkey', $lowercase->gateLabel(), 'and letter case — PHP class names are case-insensitive');

        $stacked = new DesktopSettings(middleware: [DesktopSettings::PASSKEY_GATE, AllowAllMiddleware::class]);
        self::assertSame('custom', $stacked->gateLabel(), 'the passkey gate plus something else is a custom stack, not «passkey»');

        foreach ([DesktopSettings::fromConfig(null), new DesktopSettings(middleware: []), new DesktopSettings(middleware: [AllowAllMiddleware::class]), new DesktopSettings(middleware: ['Acme\\Nope'])] as $other) {
            self::assertSame($other->gateKind(), $other->gateLabel(), 'every other stack is labelled by its kind');
        }
    }
}

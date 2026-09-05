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

/** The Desktop's copy: English by default, Spanish on request, the key itself when nobody wrote it. */
final class CatalogTest extends TestCase
{
    public function testEnglishIsTheDefaultAndSpanishIsAnOption(): void
    {
        self::assertSame(['en', 'es'], Catalog::locales());

        $en = new Catalog();
        self::assertSame('en', $en->locale());
        self::assertSame('Saved', $en->tr('settings.saved'));
        self::assertSame('gate: passkey', $en->tr('chip.gate', $en->tr('gate.kind.passkey')));
        self::assertSame('signed in as passkey:rod', $en->tr('topbar.signed_in', 'passkey:rod'));

        $es = new Catalog('es');
        self::assertSame('es', $es->locale());
        self::assertSame('Guardado', $es->tr('settings.saved'));
        self::assertSame('puerta: respaldo', $es->tr('chip.gate', $es->tr('gate.kind.fallback')));
        self::assertSame('sesión iniciada como passkey:rod', $es->tr('topbar.signed_in', 'passkey:rod'));

        self::assertSame('en', (new Catalog('fr'))->locale(), 'a locale the catalog lacks falls back to English');
    }

    public function testAnUnknownKeyAnswersAsItselfAndEveryEnglishKeyHasItsSpanish(): void
    {
        $en = new Catalog();
        self::assertSame('nobody.wrote.this', $en->tr('nobody.wrote.this'));
        self::assertFalse($en->has('nobody.wrote.this'));
        self::assertTrue($en->has('guard.forbidden'));

        $es = new Catalog('es');
        self::assertSame(array_keys($en->all()), array_keys($es->all()), 'the same keys in both, the client gets one complete map');
        $sameInBoth = ['gate.kind.loopback', 'gate.kind.passkey'];
        foreach (array_keys($en->all()) as $key) {
            if (!\in_array($key, $sameInBoth, true)) {
                self::assertNotSame($en->tr($key), $es->tr($key), 'Spanish carries its own ' . $key);
            }
        }
        self::assertSame('Guardado', $es->all()['settings.saved']);
    }
}

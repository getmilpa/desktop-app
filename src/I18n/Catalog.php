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

namespace Milpa\DesktopApp\I18n;

/**
 * The Desktop's human-facing copy, by key, in English (default) and Spanish.
 *
 * The shell asks for a key and the catalog answers in the declared locale (`desktop.locale`), falling
 * back to English, and to the key itself when nobody wrote it. The keys the door added (greenhouse
 * decisions/0209) — the topbar chips, the gate's refusal, the guard's notices — live here; the shell's
 * older copy migrates key by key as it is touched. The same shape as milpa/admin's catalog, kept apart
 * on purpose: the Desktop names no dependency on the admin.
 */
final class Catalog
{
    public const DEFAULT_LOCALE = 'en';

    /** @var array<string, array<string, string>> */
    private const MESSAGES = [
        'en' => [
            'chip.gate' => 'gate: %s',
            'gate.kind.loopback' => 'loopback',
            'gate.kind.custom' => 'custom',
            'gate.kind.passkey' => 'passkey',
            'gate.kind.open' => 'open',
            'gate.kind.fallback' => 'fallback',
            'gate.loopback.title' => 'Loopback only',
            'gate.loopback' => 'Milpa Desktop answers only to loopback by default. Declare desktop.middleware in config/app.php to put it behind your own gate.',
            'topbar.signed_in' => 'signed in as %s',
            'settings.saved' => 'Saved',
            'settings.save_failed' => 'Not saved (HTTP %s)',
            'guard.forbidden' => 'Not allowed here',
            'guard.forbidden.reason' => 'Not allowed here (%s)',
            'guard.failed' => 'The request failed (HTTP %s)',
            'guard.unreachable' => 'The app could not be reached',
            'enroll.none' => 'No passkey door in this app',
        ],
        'es' => [
            'chip.gate' => 'puerta: %s',
            'gate.kind.loopback' => 'loopback',
            'gate.kind.custom' => 'propia',
            'gate.kind.passkey' => 'passkey',
            'gate.kind.open' => 'abierta',
            'gate.kind.fallback' => 'respaldo',
            'gate.loopback.title' => 'Sólo loopback',
            'gate.loopback' => 'Milpa Desktop sólo responde a loopback por default. Declara desktop.middleware en config/app.php para ponerlo detrás de tu propia puerta.',
            'topbar.signed_in' => 'sesión iniciada como %s',
            'settings.saved' => 'Guardado',
            'settings.save_failed' => 'No se guardó (HTTP %s)',
            'guard.forbidden' => 'No permitido aquí',
            'guard.forbidden.reason' => 'No permitido aquí (%s)',
            'guard.failed' => 'La petición falló (HTTP %s)',
            'guard.unreachable' => 'No se pudo alcanzar la app',
            'enroll.none' => 'Esta app no tiene puerta de passkey',
        ],
    ];

    private string $locale;

    public function __construct(string $locale = self::DEFAULT_LOCALE)
    {
        $this->locale = isset(self::MESSAGES[$locale]) ? $locale : self::DEFAULT_LOCALE;
    }

    /** The message for a key in the catalog's locale, with `sprintf` arguments applied; the key itself when unknown. */
    public function tr(string $key, string ...$args): string
    {
        $message = self::MESSAGES[$this->locale][$key] ?? self::MESSAGES[self::DEFAULT_LOCALE][$key] ?? $key;

        return $args === [] ? $message : vsprintf($message, $args);
    }

    /** True when the catalog knows the key in its locale or in English. */
    public function has(string $key): bool
    {
        return isset(self::MESSAGES[$this->locale][$key]) || isset(self::MESSAGES[self::DEFAULT_LOCALE][$key]);
    }

    /** The locale this catalog answers in. */
    public function locale(): string
    {
        return $this->locale;
    }

    /**
     * Every message of the catalog's locale, English filling the gaps — what the shell hands its client
     * script, so the browser says the same words the server does.
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        return self::MESSAGES[$this->locale] + self::MESSAGES[self::DEFAULT_LOCALE];
    }

    /**
     * The locales the catalog carries.
     *
     * @return list<string>
     */
    public static function locales(): array
    {
        return array_keys(self::MESSAGES);
    }
}

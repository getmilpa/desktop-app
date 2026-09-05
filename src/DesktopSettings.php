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

namespace Milpa\DesktopApp;

use Milpa\DesktopApp\Http\LoopbackOnlyMiddleware;
use Milpa\DesktopApp\I18n\Catalog;
use Milpa\Runtime\Config;
use Psr\Http\Server\MiddlewareInterface;

/**
 * What the app declared about its Desktop's door, read once from the `desktop` key of its config bag —
 * and, per key, whether it declared it, the Desktop is running on a default, or the Desktop refused it.
 *
 * The Desktop sits behind the same door as the admin (greenhouse decisions/0209): `desktop.middleware`
 * is the list of PSR-15 middleware every shell route carries — the assets excepted — and its default
 * answers only to loopback. The gate is the one knob the Desktop judges instead of copying. The rule,
 * copied from milpa/admin's `AdminSettings`: **only a literally empty list `[]` opens the Desktop**.
 * Anything else that is not a list of strings naming a PSR-15 middleware class — a non-string entry, an
 * associative map, a value that is not a list at all, an empty string, a class that does not exist, a
 * class that exists but is not a middleware — is a misdeclaration, and the effective stack is the STRICT
 * gate ({@see LoopbackOnlyMiddleware}) and nothing else: never «open», never the half that loads. The
 * topbar says so. Falling to strict keeps the Desktop safe; dying with a 500 would hide the cause.
 */
final readonly class DesktopSettings
{
    public const DEFAULT_LOCALE = 'en';

    public const SOURCE_DEFAULT = 'default';
    public const SOURCE_CONFIG = 'config';
    public const SOURCE_REJECTED = 'rejected';

    public const GATE_LOOPBACK = 'loopback';
    public const GATE_CUSTOM = 'custom';
    public const GATE_OPEN = 'open';
    public const GATE_FALLBACK = 'fallback';
    public const GATE_PASSKEY = 'passkey';

    /**
     * The gate `milpa/app-runtime`'s PasskeyPlugin registers in the container — named here as a string,
     * so the Desktop names it without importing it (greenhouse decisions/0209, as the admin does in 0206).
     */
    public const PASSKEY_GATE = 'Milpa\\AppRuntime\\Web\\PasskeyGateMiddleware';

    /** How an empty string is described wherever a declared value is shown — never as nothing. */
    public const EMPTY = '(empty)';

    /** The keys the app can declare under `desktop` that this judge reads. */
    public const KEYS = ['locale', 'middleware'];

    /** @var array<string, string> key → `default` | `config` | `rejected` */
    private array $sources;

    /** @var array<string, string> key → what the app declared and the Desktop refused, described */
    private array $rejected;

    /**
     * @param string                $locale     the language of the Desktop's own copy — one the {@see Catalog} carries
     * @param array<mixed>          $middleware what the app DECLARED under `desktop.middleware` when it declared an array: every entry as written, non-strings included, keys included when it was a map; `[]` when it declared no array at all — {@see self::malformed()} tells that apart from an empty list. See {@see self::effectiveMiddleware()} for what the routes get
     * @param array<string, string> $sources    per key, `config` when the app declared the value the Desktop is using; anything else, or a key left out, is `default`
     * @param bool                  $declared   whether the `desktop` key exists in the app's config at all
     * @param array<string, string> $rejected   per key, what the app declared and the Desktop refused, described (the value for a string, the type otherwise) — a key listed here is `rejected` whatever `$sources` says
     */
    public function __construct(
        public string $locale = self::DEFAULT_LOCALE,
        public array $middleware = [LoopbackOnlyMiddleware::class],
        array $sources = [],
        private bool $declared = false,
        array $rejected = [],
    ) {
        $normalizedSources = [];
        $normalizedRejected = [];
        foreach (self::KEYS as $key) {
            if (isset($rejected[$key])) {
                $normalizedSources[$key] = self::SOURCE_REJECTED;
                $normalizedRejected[$key] = $rejected[$key];
                continue;
            }
            $normalizedSources[$key] = ($sources[$key] ?? self::SOURCE_DEFAULT) === self::SOURCE_CONFIG ? self::SOURCE_CONFIG : self::SOURCE_DEFAULT;
        }
        $this->sources = $normalizedSources;
        $this->rejected = $normalizedRejected;
    }

    /**
     * Reads the `desktop.*` keys this judge owns, falling back to the defaults for anything the app did
     * not declare — and remembering, per key, which of three things happened: declared and used
     * (`config`), left out (`default`), or declared and refused (`rejected` — the default runs, and the
     * row says what was written).
     *
     * A missing config bag (the plugin booted without a kernel, as in unit tests) yields the defaults.
     * A key set to null is not a declaration. A locale the catalog lacks, a value of the wrong type —
     * those the app DID write, so they are `rejected`, never painted `default`. The middleware is kept
     * exactly as declared when it is a list; judging it is {@see self::effectiveMiddleware()}'s.
     */
    public static function fromConfig(?Config $config): self
    {
        $raw = $config?->get('desktop');
        $declared = \is_array($raw);
        $desktop = $declared ? $raw : [];

        $rawLocale = $desktop['locale'] ?? null;
        $rawMiddleware = $desktop['middleware'] ?? null;

        $sources = [];
        $rejected = [];

        if ($rawLocale === null) {
            $locale = self::DEFAULT_LOCALE;
            $sources['locale'] = self::SOURCE_DEFAULT;
        } elseif (\is_string($rawLocale) && \in_array($rawLocale, Catalog::locales(), true)) {
            $locale = $rawLocale;
            $sources['locale'] = self::SOURCE_CONFIG;
        } else {
            $locale = self::DEFAULT_LOCALE;
            $sources['locale'] = self::SOURCE_REJECTED;
            $rejected['locale'] = self::describe($rawLocale);
        }

        if ($rawMiddleware === null) {
            $middleware = [LoopbackOnlyMiddleware::class];
            $sources['middleware'] = self::SOURCE_DEFAULT;
        } elseif (\is_array($rawMiddleware) && array_is_list($rawMiddleware)) {
            $middleware = $rawMiddleware;
            $sources['middleware'] = self::SOURCE_CONFIG;
        } else {
            $middleware = \is_array($rawMiddleware) ? $rawMiddleware : [];
            $sources['middleware'] = self::SOURCE_REJECTED;
            $rejected['middleware'] = get_debug_type($rawMiddleware);
        }

        return new self(
            locale: $locale,
            middleware: $middleware,
            sources: $sources,
            declared: $declared,
            rejected: $rejected,
        );
    }

    /** True when the `desktop` key exists in the app's config — false means the Desktop read nothing under it. */
    public function declared(): bool
    {
        return $this->declared;
    }

    /**
     * Per key, `config` when the app declared the value the Desktop is using, `default` when it did not,
     * `rejected` when it declared one the Desktop refused — the default runs, and {@see self::rejected()} says what was written.
     *
     * @return array<string, string> `locale`, `middleware` → `default` | `config` | `rejected`
     */
    public function sources(): array
    {
        return $this->sources;
    }

    /**
     * What the app declared and the Desktop refused, per key, described: the value for a string
     * (`fr`, {@see self::EMPTY}), the type for anything else (`int`, `bool`, `array`).
     *
     * @return array<string, string> only the rejected keys
     */
    public function rejected(): array
    {
        return $this->rejected;
    }

    /** The catalog answering in the declared locale. */
    public function catalog(): Catalog
    {
        return new Catalog($this->locale);
    }

    /**
     * True when `desktop.middleware` was declared as something other than a list: a string, a bool, an
     * int, an associative map. The declaration cannot be read entry by entry, so it is refused whole.
     */
    public function malformed(): bool
    {
        return isset($this->rejected['middleware']) || !array_is_list($this->middleware);
    }

    /**
     * Every reason the declared gate cannot be carried, for a human — empty when it can.
     *
     * One entry per declared entry that fails, described: `Acme\Nope (class does not exist)`,
     * `stdClass (not a PSR-15 middleware)`, `int (not a class name)`, `(empty)`. A declaration that is
     * not a list at all yields one entry naming what was received: `string (not a list)`.
     *
     * @return list<string>
     */
    public function unresolvedMiddleware(): array
    {
        if ($this->malformed()) {
            return [\sprintf('%s (not a list)', $this->rejected['middleware'] ?? 'array')];
        }

        $unresolved = [];
        foreach ($this->middleware as $entry) {
            $defect = self::middlewareDefect($entry);
            if ($defect !== null) {
                $unresolved[] = $defect;
            }
        }

        return $unresolved;
    }

    /**
     * The middleware the Desktop's routes actually carry (the assets excepted — public package files).
     *
     * The declared stack only when every entry is a string naming a class that exists and implements
     * {@see MiddlewareInterface} — a literally empty list included: the app opened the Desktop on purpose.
     * Anything else replaces the WHOLE stack with the strict gate: a gate with a hole in it is not a
     * gate, and mixing the half that loads with a silent fallback would hide which half is running.
     *
     * @return list<class-string>
     */
    public function effectiveMiddleware(): array
    {
        if ($this->malformed()) {
            return [LoopbackOnlyMiddleware::class];
        }

        $stack = [];
        foreach ($this->middleware as $entry) {
            if (!self::isMiddlewareClass($entry)) {
                return [LoopbackOnlyMiddleware::class];
            }
            $stack[] = $entry;
        }

        return $stack;
    }

    /**
     * What kind of gate the Desktop is behind. The topbar chip says {@see self::gateLabel()}, which is this
     * except for the one custom stack it knows by name.
     *
     * @return string `loopback` (the strict default, declared or not) | `custom` (the app's own stack) | `open` (a literally empty list, on purpose) | `fallback` (a misdeclared stack fell to loopback-only)
     */
    public function gateKind(): string
    {
        if ($this->unresolvedMiddleware() !== []) {
            return self::GATE_FALLBACK;
        }

        return match ($this->middleware) {
            [] => self::GATE_OPEN,
            [LoopbackOnlyMiddleware::class] => self::GATE_LOOPBACK,
            default => self::GATE_CUSTOM,
        };
    }

    /**
     * The gate as the Desktop names it — {@see self::gateKind()}, except that a stack that is exactly
     * app-runtime's passkey gate (that one class, loadable, alone — spelled however, a leading backslash
     * and letter case aside) is named `passkey`, not `custom`. A presentation rule over the kind, nothing
     * more: the kind stays `custom` and the routes carry the class as declared; a passkey gate that cannot
     * be loaded is a `fallback` like any other.
     *
     * @return string `loopback` | `custom` | `passkey` | `open` | `fallback`
     */
    public function gateLabel(): string
    {
        $effective = $this->effectiveMiddleware();
        if (\count($effective) === 1 && strcasecmp(ltrim($effective[0], '\\'), self::PASSKEY_GATE) === 0) {
            return self::GATE_PASSKEY;
        }

        return $this->gateKind();
    }

    /**
     * Why one declared middleware entry cannot be a Desktop gate, described for a human — or null when
     * the runtime can load it: a non-empty string naming a class that exists and is a PSR-15 middleware.
     */
    public static function middlewareDefect(mixed $entry): ?string
    {
        if (!\is_string($entry)) {
            return get_debug_type($entry) . ' (not a class name)';
        }
        if ($entry === '') {
            return self::EMPTY;
        }
        if (!class_exists($entry)) {
            return $entry . ' (class does not exist)';
        }
        if (!is_a($entry, MiddlewareInterface::class, true)) {
            return $entry . ' (not a PSR-15 middleware)';
        }

        return null;
    }

    /**
     * The same test {@see self::effectiveMiddleware()} applies, as a predicate the type checker follows.
     *
     * @phpstan-assert-if-true class-string<MiddlewareInterface> $entry
     */
    private static function isMiddlewareClass(mixed $entry): bool
    {
        return \is_string($entry) && $entry !== '' && class_exists($entry) && is_a($entry, MiddlewareInterface::class, true);
    }

    /** A declared value for a human: the string itself (an empty one as {@see self::EMPTY}), the type of anything else. */
    private static function describe(mixed $raw): string
    {
        if (!\is_string($raw)) {
            return get_debug_type($raw);
        }

        return $raw === '' ? self::EMPTY : $raw;
    }
}

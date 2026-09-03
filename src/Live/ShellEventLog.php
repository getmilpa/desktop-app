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

namespace Milpa\DesktopApp\Live;

/**
 * The shared, append-only bus that carries shell events between requests (greenhouse decisions/0188).
 *
 * A single PHP request cannot push another request's events to a browser without a shared medium — which
 * is exactly why a live-UI transport needs a hub. This is the LIGHTWEIGHT hub: a newline-delimited file
 * that any request can append to and any SSE request can read from. Each event's id is its 1-based line
 * number, so a client resumes with `Last-Event-ID` and receives only what it has not seen. A productized
 * transport (milpa/mercure) replaces this file with a real hub; the contract here — append, and read
 * since a cursor — is what it must preserve.
 */
final class ShellEventLog
{
    public function __construct(private readonly string $path)
    {
    }

    /** Append one event; its id becomes the new last line number. */
    public function append(ShellEvent $event): void
    {
        $dir = \dirname($this->path);
        if (!is_dir($dir)) {
            mkdir($dir, 0o775, true);
        }

        file_put_contents($this->path, $event->toLogLine() . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * Every event whose id is greater than `$afterId`, each paired with its id (its line number).
     *
     * @return list<array{id: int, event: ShellEvent}>
     */
    public function since(int $afterId): array
    {
        if (!is_file($this->path)) {
            return [];
        }

        $lines = file($this->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        $out = [];
        foreach ($lines as $index => $line) {
            $id = $index + 1;
            if ($id > $afterId) {
                $out[] = ['id' => $id, 'event' => ShellEvent::fromLogLine($line)];
            }
        }

        return $out;
    }
}

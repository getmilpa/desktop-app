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
 * A change to the desktop shell that a plugin wants pushed to connected clients (greenhouse decisions/0188).
 *
 * A plugin dispatches {@see \Milpa\DesktopApp\DesktopAppPlugin::CHANGED_EVENT} carrying one of these; the
 * desktop-app appends it to the {@see ShellEventLog}, and the SSE feed streams it out. `type` is the
 * SSE event name a browser's `EventSource` listens for; `data` is the JSON payload it receives.
 */
final class ShellEvent
{
    /** @param array<array-key, mixed> $data */
    public function __construct(
        public readonly string $type,
        public readonly array $data = [],
    ) {
    }

    /** The payload as the JSON a browser reads from the SSE `data:` field. */
    public function toJson(): string
    {
        return json_encode($this->data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /** Serialize to one newline-free line for the append-only log. */
    public function toLogLine(): string
    {
        return json_encode(['type' => $this->type, 'data' => $this->data], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /** Reconstruct from a log line written by {@see toLogLine()}; a malformed line yields an empty event. */
    public static function fromLogLine(string $line): self
    {
        $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            return new self('');
        }

        $type = is_string($decoded['type'] ?? null) ? $decoded['type'] : '';
        $data = is_array($decoded['data'] ?? null) ? $decoded['data'] : [];

        return new self($type, $data);
    }
}

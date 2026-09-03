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
 * Records a shell change to both transports (greenhouse decisions/0188, 0475).
 *
 * When a plugin dispatches {@see \Milpa\DesktopApp\DesktopAppPlugin::CHANGED_EVENT}, this appends the event
 * to the shared log (the backlog the `/desktop/events` feed replays and polls) and, when a live publisher is
 * wired, ALSO publishes it to the hub for real-time fanout. Both, on purpose: the log gives resume-on-
 * reconnect and a no-hub fallback; the publisher removes the poll when a hub is present. With no publisher
 * the recorder is exactly the file-log behavior of 0473.
 */
final class ShellChangeRecorder
{
    public function __construct(
        private readonly ShellEventLog $log,
        private readonly ?ShellPublisher $publisher = null,
    ) {
    }

    /** Append the event to the log and, if a live publisher is wired, publish it to the hub. */
    public function record(ShellEvent $event): void
    {
        $this->log->append($event);
        $this->publisher?->publish($event);
    }
}

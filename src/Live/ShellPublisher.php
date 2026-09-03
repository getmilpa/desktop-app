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
 * Pushes a shell event to a real-time transport (greenhouse decisions/0188, 0475).
 *
 * The shared log ({@see ShellEventLog}) is the fallback transport a browser polls; a `ShellPublisher` is
 * the live one — a Mercure hub, today ({@see MercurePublisher}) — that fans a change out to connected
 * subscribers with no poll. The seam keeps the change-recording ({@see ShellChangeRecorder}) testable and
 * lets an app run with the log alone (no publisher) or with a hub wired in.
 */
interface ShellPublisher
{
    /** Publish one shell event to the live transport. */
    public function publish(ShellEvent $event): void;
}

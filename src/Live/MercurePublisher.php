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

use Milpa\Mercure\MercureService;

/**
 * Publishes shell events to a Mercure hub (greenhouse decisions/0188, 0475).
 *
 * The adapter that lets the shell's live transport be the reference hub: a change becomes a Mercure
 * publish on one topic, and the hub fans it out to every browser subscribed to that topic — no polling of
 * the app's own feed. milpa/mercure's {@see MercureService} was graduated against the real hub in
 * evidence/0474; this wires it to the shell's change stream.
 */
final class MercurePublisher implements ShellPublisher
{
    public function __construct(
        private readonly MercureService $mercure,
        private readonly string $topic,
    ) {
    }

    /** Publish the event's data to the hub on this publisher's topic. */
    public function publish(ShellEvent $event): void
    {
        $this->mercure->publish($this->topic, ['event' => $event->type, 'data' => $event->data]);
    }
}

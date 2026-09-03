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

namespace Milpa\DesktopApp\Tests\Fixtures;

use Milpa\Attributes\PluginMetadata;
use Milpa\DesktopApp\Controllers\ShellController;
use Milpa\DesktopApp\ShellComposition;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Interfaces\Event\MilpaEventDispatcherInterface;
use Milpa\Interfaces\Plugin\PluginInterface;

/**
 * A second plugin that contributes UI into the desktop shell — the witness for the 0188 seam.
 *
 * It knows nothing about `DesktopAppPlugin`; the two meet only at the event name
 * {@see ShellController::COMPOSE_EVENT}. In its own `boot()` it subscribes to that event and, when the
 * shell renders, appends a section carrying {@see MARKER}. If the marker shows up in the served page,
 * a foreign plugin modified the same UI through the seam — and if it does not show up when this plugin
 * is absent, the section is proven to come from here and nowhere else.
 */
#[PluginMetadata(
    version: '0.0.0',
    author: 'lab',
    site: 'https://teamx.agency',
    name: 'DemoSection',
    type: 'Service',
)]
final class DemoSectionPlugin implements PluginInterface
{
    /** The string that proves this plugin's contribution reached the served shell. */
    public const MARKER = 'DEMO-SECTION-OK';

    public function __construct(private readonly DIContainerInterface $container)
    {
    }

    /** Subscribe to the shell's compose event; contributing is the whole effect. */
    public function boot(): void
    {
        $events = $this->container->get(MilpaEventDispatcherInterface::class);
        if ($events instanceof MilpaEventDispatcherInterface) {
            $events->subscribe(ShellController::COMPOSE_EVENT, [$this, 'onCompose']);
        }
    }

    /**
     * Contribute one section to the shell.
     *
     * @param array<string, mixed> $payload
     */
    public function onCompose(string $eventName, array $payload): void
    {
        $composition = $payload['composition'] ?? null;
        if ($composition instanceof ShellComposition) {
            $composition->addSection('demo-section', '<h2>Demo</h2><p>' . self::MARKER . '</p>');
        }
    }

    public function install(): void
    {
    }

    public function uninstall(): void
    {
    }

    public function enable(): void
    {
    }

    public function disable(): void
    {
    }
}

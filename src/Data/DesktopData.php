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

namespace Milpa\DesktopApp\Data;

use Milpa\Attributes\PluginMetadata;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Runtime\Config;
use Milpa\Runtime\Kernel;

/**
 * The Desktop's data seam — real data the screens consume, not mocks (greenhouse decisions/0481).
 *
 * The dashboard's screens need what the running app actually knows. This reads it from the runtime: the
 * installed CAPABILITIES are the booted plugins and their `#[PluginMetadata]` (name, version, type, author),
 * read off the {@see Kernel} the app registers in its container; the MODEL is the app's configured provider
 * and endpoint, read from {@see Config} with env fallbacks. Absent a kernel (an app that did not register
 * one), capabilities is simply empty — the screen shows nothing rather than invents something.
 */
final class DesktopData
{
    public function __construct(private readonly DIContainerInterface $container)
    {
    }

    /**
     * The installed capabilities: every booted plugin that declares `#[PluginMetadata]`.
     *
     * @return list<array{name: string, version: string, type: string, author: string}>
     */
    public function capabilities(): array
    {
        $kernel = $this->container->has(Kernel::class) ? $this->container->get(Kernel::class) : null;
        if (!$kernel instanceof Kernel) {
            return [];
        }

        $out = [];
        foreach ($kernel->plugins() as $plugin) {
            // A plugin the kernel booted always declares #[PluginMetadata]; iterating (0 or 1) needs no guard.
            foreach ((new \ReflectionClass($plugin))->getAttributes(PluginMetadata::class) as $attribute) {
                $meta = $attribute->newInstance();
                $out[] = ['name' => $meta->name, 'version' => $meta->version, 'type' => $meta->type, 'author' => $meta->author];
            }
        }

        return $out;
    }

    /**
     * The configured model provider and endpoint (real config, with env fallbacks).
     *
     * @return array{model: string, endpoint: string}
     */
    public function model(): array
    {
        $config = $this->container->has(Config::class) ? $this->container->get(Config::class) : null;
        $model = $config instanceof Config ? $config->get('agent.model') : null;
        $endpoint = $config instanceof Config ? $config->get('agent.base_url') : null;

        return [
            'model' => is_string($model) && $model !== '' ? $model : (getenv('MILPA_AGENT_MODEL') ?: 'qwen3.8-27b'),
            'endpoint' => is_string($endpoint) && $endpoint !== '' ? $endpoint : (getenv('MILPA_AGENT_BASE_URL') ?: 'http://llama.local:11438'),
        ];
    }

    /**
     * The whole snapshot the Desktop reads.
     *
     * @return array{capabilities: list<array{name: string, version: string, type: string, author: string}>, model: array{model: string, endpoint: string}}
     */
    public function toArray(): array
    {
        return ['capabilities' => $this->capabilities(), 'model' => $this->model()];
    }
}

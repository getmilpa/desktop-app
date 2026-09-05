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

/*
 * The fresh app WITHOUT milpa/admin, measured (greenhouse decisions/0210): a separate process in which every
 * `Milpa\Admin\*` name is UNLOADABLE — composer's loader is replaced by one that refuses that prefix and delegates
 * the rest — boots the runtime with the Desktop plugin and serves `/desktop?embed=1`. Run by
 * `tests/Admin/AdminAbsentBootTest.php`; prints one JSON line.
 */

$loader = require \dirname(__DIR__, 2) . '/vendor/autoload.php';
\assert($loader instanceof \Composer\Autoload\ClassLoader);
$loader->unregister();
spl_autoload_register(static function (string $class) use ($loader): void {
    if (str_starts_with($class, 'Milpa\\Admin\\')) {
        return; // the admin is not installed in this process
    }
    $loader->loadClass($class);
});

$kernel = \Milpa\Runtime\Kernel::boot(['root' => sys_get_temp_dir(), 'plugins' => [\Milpa\DesktopApp\DesktopAppPlugin::class]]);
$response = (new \Milpa\Runtime\Http\RequestHandler($kernel, new \Nyholm\Psr7\Factory\Psr17Factory()))
    ->handle(new \Nyholm\Psr7\ServerRequest('GET', '/desktop?embed=1', [], null, '1.1', ['REMOTE_ADDR' => '127.0.0.1']));
$plugin = $kernel->plugins()[0] ?? null;

echo json_encode([
    'booted' => $kernel->bootedPluginNames(),
    'status' => $response->getStatusCode(),
    'embed' => str_contains((string) $response->getBody(), 'data-embed="1"'),
    'admin_interface' => interface_exists('Milpa\\Admin\\Section\\AdminSectionProvider'),
    'admin_section' => class_exists('Milpa\\Admin\\Section\\AdminSection'),
    'guest' => $plugin instanceof \Milpa\DesktopApp\Admin\AdminGuest,
    'provider' => $plugin instanceof \Milpa\Admin\Section\AdminSectionProvider,
    'interfaces' => \is_object($plugin) ? array_values(class_implements($plugin)) : [],
], JSON_THROW_ON_ERROR), PHP_EOL;

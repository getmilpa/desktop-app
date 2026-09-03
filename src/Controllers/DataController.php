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

namespace Milpa\DesktopApp\Controllers;

use Milpa\DesktopApp\Data\DesktopData;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Serves the Desktop's data snapshot as JSON (greenhouse decisions/0481).
 *
 * `GET /desktop/data.json` — the real data the screens consume (installed capabilities, the configured
 * model), read from the runtime by {@see DesktopData}. The shell also renders this data server-side; this
 * endpoint is the same data for a client that wants to refresh without a reload, and the DX seam a plugin's
 * panel can read.
 */
final class DataController
{
    public function __construct(private readonly DesktopData $data)
    {
    }

    /** The data snapshot as JSON. */
    public function data(ServerRequestInterface $request): ResponseInterface
    {
        return new Response(
            200,
            ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store'],
            json_encode($this->data->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
    }

    /** Download the current session as a JSON file — the autopsy/video material (greenhouse evidence/0488). */
    public function export(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        if (isset($params['session']) && is_string($params['session'])) {
            $this->data->select($params['session']);
        }
        $dump = $this->data->export();
        $id = $dump['id'] !== '' ? $dump['id'] : 'session';

        return new Response(
            200,
            [
                'Content-Type' => 'application/json; charset=utf-8',
                'Cache-Control' => 'no-store',
                'Content-Disposition' => 'attachment; filename="milpa-session-' . $id . '.json"',
            ],
            json_encode($dump, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        );
    }
}

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

use Milpa\DesktopApp\Data\DesktopStore;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The Desktop's write endpoints (greenhouse decisions/0483).
 *
 * `POST /desktop/settings` persists the settings form; `POST /desktop/sessions` creates a session in the
 * store and returns its id. Both write to the same real stores {@see \Milpa\DesktopApp\Data\DesktopData}
 * reads, so the change survives a reload. What the app exposes and how it governs these mutations is the
 * app's concern (its middleware / consent), exactly like any other route it mounts.
 */
final class MutationController
{
    public function __construct(private readonly DesktopStore $store)
    {
    }

    /** Persist the posted settings JSON. */
    public function saveSettings(ServerRequestInterface $request): ResponseInterface
    {
        $body = json_decode((string) $request->getBody(), true);
        $this->store->saveSettings(is_array($body) ? $body : []);

        return $this->json(['ok' => true]);
    }

    /** Create a session from the posted `{goal}` and return its id. */
    public function createSession(ServerRequestInterface $request): ResponseInterface
    {
        $body = json_decode((string) $request->getBody(), true);
        $goal = is_array($body) && is_string($body['goal'] ?? null) ? $body['goal'] : '';
        $id = $this->store->createSession($goal);

        return $this->json(['ok' => true, 'id' => $id]);
    }

    /** Move a work item to a new status (drag-drop on the board), persisting it to the session file. */
    public function moveWork(ServerRequestInterface $request): ResponseInterface
    {
        $body = json_decode((string) $request->getBody(), true);
        $session = is_array($body) && is_string($body['session'] ?? null) ? $body['session'] : '';
        $index = is_array($body) && is_numeric($body['index'] ?? null) ? (int) $body['index'] : -1;
        $status = is_array($body) && is_string($body['status'] ?? null) ? $body['status'] : '';

        return $this->json(['ok' => $this->store->updateWorkStatus($session, $index, $status)]);
    }

    /** @param array<string, mixed> $data */
    private function json(array $data): ResponseInterface
    {
        return new Response(
            200,
            ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store'],
            json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
    }
}

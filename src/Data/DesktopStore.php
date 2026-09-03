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

/**
 * The Desktop's write side — persistence on disk (greenhouse decisions/0483).
 *
 * The read seam ({@see DesktopData}) surfaces what the app knows; this writes it back. Settings persist to a
 * single JSON file; a new session is a JSON file written into the session store with a server-generated id
 * ({@see DesktopData} then reads it). Both are round-trips against the same real stores the screens read, so
 * a change survives a reload. Ids are generated here and session paths are confined to the store directory —
 * caller input never composes a filesystem path.
 */
final class DesktopStore
{
    public function __construct(
        private readonly string $sessionsPath,
        private readonly string $settingsPath,
    ) {
    }

    /**
     * The persisted settings, or an empty array if none saved yet.
     *
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        $raw = is_file($this->settingsPath) ? (string) file_get_contents($this->settingsPath) : '';
        $decoded = $raw === '' ? null : json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Persist the settings (replacing the stored set).
     *
     * @param array<string, mixed> $settings
     */
    public function saveSettings(array $settings): void
    {
        $dir = \dirname($this->settingsPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0o775, true);
        }
        // Merge over what is stored so a partial post (e.g. the composer's mode chip sending only `mode`)
        // updates its own field without dropping the rest of the settings.
        $merged = array_merge($this->settings(), $settings);
        file_put_contents($this->settingsPath, json_encode($merged, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX);
    }

    /**
     * Create a new session in the store and return its generated id. Nothing runs — it is a record.
     */
    public function createSession(string $goal): string
    {
        if (!is_dir($this->sessionsPath)) {
            mkdir($this->sessionsPath, 0o775, true);
        }
        $id = substr(bin2hex(random_bytes(4)), 0, 8);
        $session = [
            'id' => $id,
            'goal' => $goal !== '' ? $goal : '(no goal)',
            'state' => 'ready',
            'turns' => 0,
            'steps' => 0,
            'tokens' => 0,
            'tool_calls' => 0,
            'work' => [],
        ];
        file_put_contents(
            $this->sessionsPath . '/' . $id . '.json',
            json_encode($session, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            LOCK_EX,
        );

        return $id;
    }

    /**
     * Move a work item to a new status in its session file. Returns whether it was applied.
     *
     * The session id must name an existing file in the store (validated, no traversal); the index must be a
     * real work item. The write is the whole session file re-serialized — the record moves, nothing runs.
     */
    public function updateWorkStatus(string $sessionId, int $index, string $status): bool
    {
        if (preg_match('/^[0-9A-Za-z_-]{1,64}$/', $sessionId) !== 1) {
            return false;
        }
        $file = $this->sessionsPath . '/' . $sessionId . '.json';
        if (!is_file($file)) {
            return false;
        }
        $session = json_decode((string) file_get_contents($file), true);
        if (!is_array($session) || !isset($session['work'][$index]) || !is_array($session['work'][$index])) {
            return false;
        }
        $session['work'][$index]['status'] = $status;
        file_put_contents($file, json_encode($session, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX);

        return true;
    }
}

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
use Milpa\DesktopApp\Live\ShellEventLog;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Runtime\Config;
use Milpa\Runtime\Kernel;

/**
 * The Desktop's data seam — real data the screens consume, not mocks (greenhouse decisions/0481, 0482).
 *
 * It reads what the running app actually knows: CAPABILITIES from the booted plugins' `#[PluginMetadata]`
 * (off the {@see Kernel} the app registers), the MODEL from {@see Config}, the SESSIONS from the app's
 * on-disk session store (JSON files under `desktop.sessions.path`, default `.milpa/sessions/`) with the
 * current session's COUNTERS and WORK board read from that same file, and the AUDIT facts from the shared
 * {@see ShellEventLog}. Missing sources degrade to empty — the screens show nothing rather than invent it.
 */
final class DesktopData
{
    public function __construct(
        private readonly DIContainerInterface $container,
        private readonly ?ShellEventLog $log = null,
        private readonly string $sessionsPath = '',
    ) {
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
     * The app's sessions, read from the on-disk session store (each `*.json` file is one session).
     *
     * @return list<array{id: string, goal: string, state: string}>
     */
    public function sessions(): array
    {
        $out = [];
        foreach ($this->sessionFiles() as $file) {
            $s = $this->readJson($file);
            $out[] = [
                'id' => $this->str($s['id'] ?? null) ?: basename($file, '.json'),
                'goal' => $this->str($s['goal'] ?? $s['objective'] ?? $s['title'] ?? null) ?: '(no goal recorded)',
                'state' => $this->str($s['state'] ?? $s['status'] ?? null) ?: 'idle',
            ];
        }

        return $out;
    }

    /**
     * The current session's counters (turns, steps, tokens, tool calls) — from the session, else derived.
     *
     * @return array{turns: int, steps: int, tokens: int, tool_calls: int, state: string}
     */
    public function counters(): array
    {
        $s = $this->currentSession();

        return [
            'turns' => $this->int($s['turns'] ?? null),
            'steps' => $this->int($s['steps'] ?? null) ?: \count($this->audit()),
            'tokens' => $this->int($s['tokens'] ?? null),
            'tool_calls' => $this->int($s['tool_calls'] ?? $s['toolCalls'] ?? null),
            'state' => $this->str($s['state'] ?? $s['status'] ?? null) ?: 'idle',
        ];
    }

    /**
     * The current session's work board items.
     *
     * @return list<array{title: string, status: string, origin: string}>
     */
    public function work(): array
    {
        $items = $this->currentSession()['work'] ?? $this->currentSession()['todo'] ?? null;
        if (!is_array($items)) {
            return [];
        }

        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $out[] = [
                'title' => $this->str($item['title'] ?? $item['text'] ?? null) ?: '(untitled)',
                'status' => $this->str($item['status'] ?? null) ?: 'pending',
                'origin' => $this->str($item['origin'] ?? null) ?: 'planned',
            ];
        }

        return $out;
    }

    /**
     * The audit facts — the shared event log's events, in order, each with its seq.
     *
     * @return list<array{seq: int, type: string, data: string}>
     */
    public function audit(): array
    {
        if ($this->log === null) {
            return [];
        }

        $out = [];
        foreach ($this->log->since(0) as $entry) {
            $out[] = ['seq' => $entry['id'], 'type' => $entry['event']->type, 'data' => $entry['event']->toJson()];
        }

        return $out;
    }

    /**
     * The whole snapshot the Desktop reads.
     *
     * @return array{
     *     capabilities: list<array{name: string, version: string, type: string, author: string}>,
     *     model: array{model: string, endpoint: string},
     *     sessions: list<array{id: string, goal: string, state: string}>,
     *     counters: array{turns: int, steps: int, tokens: int, tool_calls: int, state: string},
     *     work: list<array{title: string, status: string, origin: string}>,
     *     audit: list<array{seq: int, type: string, data: string}>
     * }
     */
    public function toArray(): array
    {
        return [
            'capabilities' => $this->capabilities(),
            'model' => $this->model(),
            'sessions' => $this->sessions(),
            'counters' => $this->counters(),
            'work' => $this->work(),
            'audit' => $this->audit(),
        ];
    }

    /** @return list<string> */
    private function sessionFiles(): array
    {
        if ($this->sessionsPath === '' || !is_dir($this->sessionsPath)) {
            return [];
        }
        $files = glob(rtrim($this->sessionsPath, '/') . '/*.json') ?: [];
        sort($files);

        return $files;
    }

    /** @return array<string, mixed> */
    private function currentSession(): array
    {
        $files = $this->sessionFiles();

        return $files === [] ? [] : $this->readJson($files[0]);
    }

    /** @return array<string, mixed> */
    private function readJson(string $file): array
    {
        $raw = is_file($file) ? (string) file_get_contents($file) : '';
        $decoded = $raw === '' ? null : json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function str(mixed $value): string
    {
        return is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');
    }

    private function int(mixed $value): int
    {
        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : 0);
    }
}

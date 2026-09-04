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
        private readonly ?DesktopStore $store = null,
    ) {
    }

    /**
     * The persisted Desktop settings (write side, decisions/0483), or an empty array if none saved.
     *
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        return $this->store?->settings() ?? [];
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
     * The capability catalogue the runtime reports: what is installed, and what is available to install.
     *
     * Read server-side from the same {@see \Milpa\AppRuntime\Support\Capabilities} answer the `capabilities`
     * operation returns — so the human sees EXACTLY what the agent sees, and installing one (through the
     * gated `capabilities:enable` over HTTP, greenhouse decisions/0193) is instantly available to both.
     *
     * @return array{installed: list<array<string, mixed>>, available: list<array<string, mixed>>, source: string}
     *
     * @codeCoverageIgnore reads through to the app-runtime Capabilities registry; exercised by integration
     *                     on a booted app (greenhouse evidence/0507), not by the standalone unit suite
     */
    public function capabilityCatalogue(): array
    {
        if (!class_exists(\Milpa\AppRuntime\Support\Capabilities::class)) {
            return ['installed' => [], 'available' => [], 'source' => ''];
        }

        $answer = \Milpa\AppRuntime\Support\Capabilities::answer();

        return [
            'installed' => is_array($answer['installed'] ?? null) ? array_values($answer['installed']) : [],
            'available' => is_array($answer['available'] ?? null) ? array_values($answer['available']) : [],
            'source' => is_string($answer['source'] ?? null) ? $answer['source'] : '',
        ];
    }

    /**
     * The decisions inbox: every session that has a question an agent parked, across all sessions.
     *
     * The live gate lives in the conversation of the session it belongs to; this is the cross-session
     * backlog — the durable questions waiting for a human, wherever they were raised. Read from the agent's
     * {@see \Milpa\Agent\SessionStore} (each session's `->question`), guarded so an app without the agent
     * package degrades to none rather than failing.
     *
     * @return list<array{session: string, goal: string, question: string, operation: string, reason: string}>
     *
     * @codeCoverageIgnore reads through to the agent SessionStore; exercised by integration on a booted app
     *                     (greenhouse evidence/0509), not by the standalone unit suite
     */
    public function pendingDecisions(): array
    {
        // The agent op builds its own store at `<root>/var/agent-sessions.jsonl` rather than registering one
        // ({@see \Milpa\AppRuntime\Operations\AgentOperations}); read the SAME file so the inbox sees the
        // questions the agent parked. Guarded so an app without the agent/event-store packages degrades to none.
        if (!class_exists(\Milpa\Agent\SessionStore::class) || !class_exists(\Milpa\EventStore\FileEventStore::class)) {
            return [];
        }
        $kernel = $this->container->has(Kernel::class) ? $this->container->get(Kernel::class) : null;
        if (!$kernel instanceof Kernel) {
            return [];
        }
        $file = $kernel->root() . '/var/agent-sessions.jsonl';
        if (!is_file($file)) {
            return [];
        }
        $store = new \Milpa\Agent\SessionStore(new \Milpa\EventStore\FileEventStore($file));

        $out = [];
        foreach ($store->loadAll() as $session) {
            if ($session->question === null) {
                continue;
            }
            $q = $session->question;
            $operation = '';
            if (is_string($q->why) && $q->why !== '') {
                $decoded = json_decode($q->why, true);
                if (is_array($decoded) && is_string($decoded['operation'] ?? null)) {
                    $operation = $decoded['operation'];
                }
            }
            $out[] = [
                'session' => $session->id,
                'goal' => $session->goal,
                'question' => $q->question,
                'operation' => $operation,
                'reason' => is_string($q->reason) ? $q->reason : '',
            ];
        }

        return $out;
    }

    /**
     * The skills the agent carries — each one's name, description, and who may invoke it (greenhouse
     * decisions/0197). A skill guides judgment; it is not a tool that runs. Read from the same
     * {@see \Milpa\AppRuntime\Agent\Skill\SkillRegistry} (`<root>/skills/*​/SKILL.md`) the agent reads, so
     * the human sees exactly what the agent can reach for. Guarded so an app without the runtime degrades to none.
     *
     * @return list<array{name: string, description: string, model_invocable: bool, user_invocable: bool}>
     *
     * @codeCoverageIgnore reads through to the app-runtime SkillRegistry; exercised by integration on a booted
     *                     app (greenhouse evidence/0511), not by the standalone unit suite
     */
    public function skills(): array
    {
        if (!class_exists(\Milpa\AppRuntime\Agent\Skill\SkillRegistry::class)) {
            return [];
        }
        $kernel = $this->container->has(Kernel::class) ? $this->container->get(Kernel::class) : null;
        if (!$kernel instanceof Kernel) {
            return [];
        }

        $out = [];
        foreach ((new \Milpa\AppRuntime\Agent\Skill\SkillRegistry($kernel->root()))->all() as $skill) {
            $out[] = [
                'name' => $skill->name,
                'description' => $skill->description,
                'model_invocable' => $skill->modelInvocable,
                'user_invocable' => $skill->userInvocable,
            ];
        }

        return $out;
    }

    /**
     * The composer's commands (greenhouse decisions/0202): the house's own — `/goal`, `/mode`, `/help` — plus
     * every user-invocable skill as `/<skill-name>`. Each command is an OPERATION the agent could fire too
     * (`agent:goal`, `agent:mode`, `skill:load`); the Desktop invents no action. Read through the same
     * {@see self::skills()} seam, so an app without the runtime still offers the house commands.
     *
     * @return list<array{name: string, kind: string, description: string, usage: string}>
     */
    public function commands(): array
    {
        return self::commandsFor($this->skills());
    }

    /**
     * The house's own commands — what the composer offers even when no skill is user-invocable.
     *
     * @return list<array{name: string, kind: string, description: string, usage: string}>
     */
    public static function houseCommands(): array
    {
        return [
            ['name' => 'goal', 'kind' => 'house', 'description' => "Set, show or clear the session's standing goal", 'usage' => '/goal <text> | /goal clear | /goal'],
            ['name' => 'mode', 'kind' => 'house', 'description' => 'Choose how much the agent asks', 'usage' => '/mode ask|acknowledge|auto'],
            ['name' => 'help', 'kind' => 'house', 'description' => 'List the commands this composer understands', 'usage' => '/help'],
        ];
    }

    /**
     * The house commands followed by one `/<name>` per user-invocable skill (a model-only skill is not a
     * command: the human has no surface for it, greenhouse decisions/0202). Pure, so it is tested with fixtures.
     *
     * @param list<array{name: string, description: string, model_invocable: bool, user_invocable: bool}> $skills
     *
     * @return list<array{name: string, kind: string, description: string, usage: string}>
     */
    public static function commandsFor(array $skills): array
    {
        $out = self::houseCommands();
        foreach ($skills as $skill) {
            if (!$skill['user_invocable']) {
                continue;
            }
            $out[] = [
                'name' => $skill['name'],
                'kind' => 'skill',
                'description' => $skill['description'],
                'usage' => '/' . $skill['name'] . ' [args]',
            ];
        }

        return $out;
    }

    /**
     * The specialist agent roles this app declares (greenhouse decisions/0197): each a named authority with
     * the skills it preloads, the tools it is denied, and what it produces. A role names authority that already
     * governs; the skills only suggest. Read from the same {@see \Milpa\AppRuntime\Agent\Role\RoleRegistry}
     * (`<root>/.milpa/agents/*.md`) the `agent:role:list` operation reads. Guarded → none without the runtime.
     *
     * @return list<array{name: string, produces: string, deny: list<string>, skills: list<string>}>
     *
     * @codeCoverageIgnore reads through to the app-runtime RoleRegistry; exercised by integration on a booted
     *                     app (greenhouse evidence/0512), not by the standalone unit suite
     */
    public function roles(): array
    {
        if (!class_exists(\Milpa\AppRuntime\Agent\Role\RoleRegistry::class)) {
            return [];
        }
        $kernel = $this->container->has(Kernel::class) ? $this->container->get(Kernel::class) : null;
        if (!$kernel instanceof Kernel) {
            return [];
        }

        $registry = new \Milpa\AppRuntime\Agent\Role\RoleRegistry();
        $registry->loadFrom($kernel->root() . '/.milpa/agents');

        $out = [];
        foreach ($registry->all() as $role) {
            $row = $role->toArray();
            $out[] = [
                'name' => is_string($row['name'] ?? null) ? $row['name'] : '',
                'produces' => is_string($row['produces'] ?? null) ? $row['produces'] : '',
                'deny' => array_values(array_filter(is_array($row['deny'] ?? null) ? $row['deny'] : [], 'is_string')),
                'skills' => array_values(array_filter(is_array($row['skills'] ?? null) ? $row['skills'] : [], 'is_string')),
            ];
        }

        return $out;
    }

    /**
     * The live screens the agent declared (greenhouse decisions/0197): each served by the live wire at its own
     * path, previewable with no code deploy. Read from the same {@see \Milpa\AppRuntime\Web\ScreenStore} the
     * `screen:declare` operation writes. Guarded → none without the live wire.
     *
     * @return list<array{name: string, type: string, served_at: string}>
     *
     * @codeCoverageIgnore reads through to the app-runtime ScreenStore; exercised by integration on a booted
     *                     app (greenhouse evidence/0512), not by the standalone unit suite
     */
    public function declaredScreens(): array
    {
        if (!class_exists(\Milpa\AppRuntime\Web\ScreenStore::class)) {
            return [];
        }
        $kernel = $this->container->has(Kernel::class) ? $this->container->get(Kernel::class) : null;
        if (!$kernel instanceof Kernel) {
            return [];
        }
        $config = $this->container->has(Config::class) ? $this->container->get(Config::class) : null;
        $live = $config instanceof Config && is_array($config->get('live')) ? $config->get('live') : [];

        $out = [];
        foreach (\Milpa\AppRuntime\Web\ScreenStore::fromConfig($live, $kernel->root())->catalogue() as $row) {
            $out[] = [
                'name' => is_string($row['name'] ?? null) ? $row['name'] : '',
                'type' => is_string($row['type'] ?? null) ? $row['type'] : '',
                'served_at' => is_string($row['servedAt'] ?? null) ? $row['servedAt'] : '',
            ];
        }

        return $out;
    }

    /** The route the live wire is mounted on (config `live.route`, default `/live`) — the Preview iframe's base. */
    public function liveRoute(): string
    {
        $config = $this->container->has(Config::class) ? $this->container->get(Config::class) : null;
        $live = $config instanceof Config ? $config->get('live') : null;
        $route = is_array($live) && is_string($live['route'] ?? null) && $live['route'] !== '' ? $live['route'] : '/live';

        return $route;
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

    /** The session the UI selected (a sidebar click posts `?session=<id>`), when it names a real one. */
    private ?string $selectedId = null;

    /** Select the active session by id; ignored unless it is a well-formed id of a session that exists. */
    public function select(string $id): void
    {
        if (preg_match('/^[0-9A-Za-z_-]{1,64}$/', $id) !== 1) {
            return;
        }
        foreach ($this->sessionFiles() as $file) {
            if (basename($file, '.json') === $id) {
                $this->selectedId = $id;

                return;
            }
        }
    }

    /**
     * The current session's addressable id — the selected one when a sidebar click chose it, else the first
     * in the store, or '' when there is none. The file name is what the write side addresses
     * ({@see DesktopStore::updateWorkStatus()}), so it is the id the UI posts back, not the display id.
     */
    public function currentSessionId(): string
    {
        if ($this->selectedId !== null) {
            return $this->selectedId;
        }
        $files = $this->sessionFiles();

        return $files === [] ? '' : basename($files[0], '.json');
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
     * The context window usage (wireframe 3a): tokens used, the window size, and derived percent/free.
     *
     * @return array{tokens: int, window: int, used_pct: int, free: int}
     */
    public function context(): array
    {
        $tokens = $this->counters()['tokens'];
        $config = $this->container->has(Config::class) ? $this->container->get(Config::class) : null;
        $configured = $config instanceof Config ? $config->get('agent.context_window') : null;
        $window = is_int($configured) && $configured > 0 ? $configured : 32768;

        return [
            'tokens' => $tokens,
            'window' => $window,
            'used_pct' => min(100, (int) round($tokens / $window * 100)),
            'free' => max(0, $window - $tokens),
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
     * A downloadable dump of the CURRENT session — the material for a video or an autopsy of what the
     * agent did: the raw session record, its counters and context, the work board and the activity/audit.
     *
     * @return array{exported_at: string, id: string, model: array{model: string, endpoint: string}, session: array<string, mixed>, counters: array{turns: int, steps: int, tokens: int, tool_calls: int, state: string}, context: array{tokens: int, window: int, used_pct: int, free: int}, budget: list<array{class: string, messages: int, est_tokens: int}>, work: list<array{title: string, status: string, origin: string}>, audit: list<array{seq: int, type: string, data: string}>}
     */
    public function export(string $agentSessionId = ''): array
    {
        return [
            'exported_at' => date('c'),
            'id' => $this->currentSessionId(),
            'model' => $this->model(),
            'session' => $this->currentSession(),
            'counters' => $this->counters(),
            'context' => $this->context(),
            'budget' => $this->tokenBudget($agentSessionId),
            'work' => $this->work(),
            'audit' => $this->audit(),
        ];
    }

    /**
     * Where the token budget goes, by category — so a human can SEE and debug it (greenhouse decisions/0196).
     *
     * The provider reports a real TOTAL (decisions/0192), but not how it splits; this estimates the weight of
     * each part of the composed window (summary / current state / turns …) from its content length, the same
     * "≈ length/4" the TUI uses. The agent runs under a MINTED id (`run-…`), separate from the DesktopStore
     * session id, so the caller passes the agent session the browser drove (the `milpa_agent_sid` cookie).
     * Read from the agent's own store, guarded so an app without it degrades to none.
     *
     * @return list<array{class: string, messages: int, est_tokens: int}>
     *
     * @codeCoverageIgnore reads through to the agent SessionStore; exercised by integration on a booted app
     *                     (greenhouse evidence/0510), not by the standalone unit suite
     */
    public function tokenBudget(string $agentSessionId = ''): array
    {
        if ($agentSessionId === '' || !class_exists(\Milpa\Agent\SessionStore::class) || !class_exists(\Milpa\EventStore\FileEventStore::class)) {
            return [];
        }
        $kernel = $this->container->has(Kernel::class) ? $this->container->get(Kernel::class) : null;
        if (!$kernel instanceof Kernel) {
            return [];
        }
        $file = $kernel->root() . '/var/agent-sessions.jsonl';
        $id = $agentSessionId;
        if (!is_file($file)) {
            return [];
        }
        $session = (new \Milpa\Agent\SessionStore(new \Milpa\EventStore\FileEventStore($file)))->load($id);
        if ($session === null) {
            return [];
        }

        $byClass = [];
        foreach ($session->classifiedWindow() as $message) {
            $class = is_string($message['class'] ?? null) ? $message['class'] : 'other';
            $content = is_string($message['content'] ?? null) ? $message['content'] : '';
            if (!isset($byClass[$class])) {
                $byClass[$class] = ['class' => $class, 'messages' => 0, 'est_tokens' => 0];
            }
            ++$byClass[$class]['messages'];
            $byClass[$class]['est_tokens'] += (int) ceil(mb_strlen($content) / 4);
        }

        return array_values($byClass);
    }

    /**
     * The whole snapshot the Desktop reads.
     *
     * @return array{
     *     capabilities: list<array{name: string, version: string, type: string, author: string}>,
     *     model: array{model: string, endpoint: string},
     *     settings: array<string, mixed>,
     *     sessions: list<array{id: string, goal: string, state: string}>,
     *     counters: array{turns: int, steps: int, tokens: int, tool_calls: int, state: string},
     *     context: array{tokens: int, window: int, used_pct: int, free: int},
     *     work: list<array{title: string, status: string, origin: string}>,
     *     audit: list<array{seq: int, type: string, data: string}>
     * }
     */
    public function toArray(): array
    {
        return [
            'capabilities' => $this->capabilities(),
            'model' => $this->model(),
            'settings' => $this->settings(),
            'sessions' => $this->sessions(),
            'counters' => $this->counters(),
            'context' => $this->context(),
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
        if ($files === []) {
            return [];
        }
        $id = $this->currentSessionId();
        foreach ($files as $file) {
            if (basename($file, '.json') === $id) {
                return $this->readJson($file);
            }
        }

        return $this->readJson($files[0]);
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

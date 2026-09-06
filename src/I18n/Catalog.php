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

namespace Milpa\DesktopApp\I18n;

/**
 * The Desktop's human-facing copy, by key, in English (default) and Spanish.
 *
 * The shell asks for a key and the catalog answers in the declared locale (`desktop.locale`), falling
 * back to English, and to the key itself when nobody wrote it. The keys the door added (greenhouse
 * decisions/0209) — the topbar chips, the gate's refusal, the guard's notices — live here; the shell's
 * older copy migrates key by key as it is touched. The same shape as milpa/admin's catalog, kept apart
 * on purpose: the Desktop names no dependency on the admin.
 */
final class Catalog
{
    public const DEFAULT_LOCALE = 'en';

    /** @var array<string, array<string, string>> */
    private const MESSAGES = [
        'en' => [
            'chip.gate' => 'gate: %s',
            'gate.kind.loopback' => 'loopback',
            'gate.kind.custom' => 'custom',
            'gate.kind.passkey' => 'passkey',
            'gate.kind.open' => 'open',
            'gate.kind.fallback' => 'fallback',
            'gate.loopback.title' => 'Loopback only',
            'gate.loopback' => 'Milpa Desktop answers only to loopback by default. Declare desktop.middleware in config/app.php to put it behind your own gate.',
            'topbar.signed_in' => 'signed in as %s',
            'settings.saved' => 'Saved',
            'settings.save_failed' => 'Not saved (HTTP %s)',
            'guard.forbidden' => 'Not allowed here',
            'guard.forbidden.reason' => 'Not allowed here (%s)',
            'guard.failed' => 'The request failed (HTTP %s)',
            'guard.unreachable' => 'The app could not be reached',
            'enroll.none' => 'No passkey door in this app',
            'agent.title' => 'Agent',
            'agent.open' => 'Open the Desktop',
            'agent.signin' => 'Sign in to open the Agent',
            'agent.signin.action' => 'Sign in',
            // A surface of the Agent region that could not be painted (greenhouse decisions/0211, slice 3):
            // it says which one, inside its own node, and the rest of the region stands.
            'agent.surface.failed' => 'This part of the Agent could not be shown (%s).',
            'strip.session' => 'Session',
            'strip.none' => 'No session open',
            'strip.pick' => 'Pick a session',
            'strip.new' => 'New session',
            // The Settings screen (greenhouse decisions/0211, phase B7). The autonomy badges («ask»,
            // «acknowledge», «auto») are the mode's own VALUES, not copy, and stay as they are.
            'settings.model.title' => 'Model and provider',
            'settings.model.provider' => 'Provider',
            'settings.model.endpoint' => 'Endpoint',
            'settings.model.endpoint_hint' => 'The endpoint receives context. It does not execute operations.',
            'settings.model.stream' => 'Show streaming tokens',
            'settings.provider.local' => 'Local model',
            'settings.provider.lan' => 'Local-network model',
            'settings.provider.external' => 'External provider',
            'settings.autonomy.title' => 'Default autonomy',
            'settings.autonomy.ask' => 'Ask before changing',
            'settings.autonomy.ask_hint' => 'Pauses mutations without a standing permission.',
            'settings.autonomy.acknowledge' => 'Compatibility',
            'settings.autonomy.acknowledge_hint' => 'Today decides like auto: no observable prior notice.',
            'settings.autonomy.auto' => 'Continue automatically',
            'settings.autonomy.auto_hint' => 'Signatures and incomplete intent still stop.',
            'settings.autonomy.note' => 'The three values are not yet three behaviorally distinct levels.',
            'settings.storage.title' => 'Context and storage',
            'settings.storage.compact' => 'Compact context automatically',
            'settings.storage.compact_note' => 'Compaction reduces what the model sees, never the session record.',
            'settings.storage.folder' => 'Sessions folder',
            'settings.appearance.title' => 'Appearance',
            'settings.appearance.theme' => 'Theme',
            'settings.appearance.scale' => 'Interface scale',
            'settings.theme.system' => 'System',
            'settings.theme.dark' => 'Dark',
            'settings.theme.light' => 'Light',
            'settings.discard' => 'Discard changes',
            'settings.save' => 'Save settings',
            // The entry overlay (phase B5). «Milpa Desktop» is the product's name, not copy: it is not a key.
            'auth.kicker' => 'local workspace',
            'auth.lede' => "Open a Milpa app to start, understand and resume an agent's work. The session is the unit; nothing runs on open.",
            'auth.app' => 'Milpa app',
            'auth.app_hint' => 'Reads %s. One app at a time.',
            'auth.identity' => 'Decision identity',
            'auth.identity.system' => 'System user',
            'auth.identity.system_hint' => 'Not verified. Call signatures are asked for separately.',
            'auth.identity.verified' => 'Signature-verified principal',
            'auth.identity.verified_hint' => 'Requires an external mechanism.',
            'auth.provider' => 'Model provider',
            'auth.provider.local' => 'Local model · %s (%s)',
            'auth.warning.title' => 'Your system user is not a verified identity',
            'auth.warning.desc' => 'Authorizing in a session grants the operation; it is not signing the call.',
            'auth.enter' => 'Open workspace',
            // The conversation, the turn and the composer's commands (greenhouse decisions/0211, phase C).
            // Every sentence those modules say is a key here: they left the page as `tr('…')` calls, and a
            // sentence assembled from fragments in JavaScript cannot be translated as a sentence.
            'verdict.verified' => 'verified',
            'verdict.disputed' => 'disputed',
            'verdict.backed' => "The ledger backs this turn: every completed step carries evidence, nothing was left open, and no artifact's latest check is red.",
            'verdict.disputed.why' => 'The ledger disputes this turn — %s.',
            'verdict.disputed.default' => 'the completion is not backed by evidence',
            'verdict.aria.verified' => 'Verified. %s',
            'verdict.aria.disputed' => 'Disputed. %s',
            'thinking.elapsed' => 'thought for %ss',
            'turn.paused' => 'The agent is waiting on your decision.',
            'turn.stop_requested' => 'stop requested',
            // The session's state, as the topbar badge and the status bar READ it. It is a signal both the
            // server seeds and the turn's module writes, so both say it through this key: an English
            // «Working» written from JavaScript was the one sentence phase C left leaking into a Spanish
            // shell (`ShellController::liveSignals()` seeds the same key, from the same words).
            'session.state.working' => 'Working',
            'session.state.idle' => 'Idle',
            // The conversation's interrupted-run notice (greenhouse decisions/0196), rendered by the
            // thread's own renderer — the last user-facing sentence the shell hand-wrote in English.
            'conversation.interrupted' => 'A prior run was interrupted — it was left mid-turn and did not finish. Send again to continue; nothing was auto-resumed.',
            // The declared-screen preview: the wire is asked before the frame is pointed at it.
            'preview.failed' => 'The wire does not serve «%s» (HTTP %s)',
            'preview.unreachable' => 'The wire could not be reached for «%s»',
            'composer.tokens' => '~%s tokens',
            'composer.placeholder' => 'Write to the session…',
            'composer.attach' => 'attach',
            'composer.send' => 'continue session',
            'composer.stop' => 'stop the turn',
            'command.unknown' => 'unknown command %s — commands: %s',
            'command.mode.usage' => 'usage: /mode ask|acknowledge|auto',
            'command.mode.set' => 'mode %s — applies from the next turn',
            'command.mode.set.auto' => 'mode %s — applies from the next turn (a signature or third-party egress still asks)',
            'command.goal.none' => 'no standing goal — /goal <text> sets one',
            'command.goal.cleared' => 'goal cleared',
            'command.goal.set' => 'goal set: %s',
            'command.goal.unchanged' => 'goal unchanged: %s',
            'op.refused' => '%s refused — %s',
            'op.no_reason' => 'no reason given',
            'op.failed' => '%s → HTTP %s — %s',
            'op.detail' => '%s (%s)',
            'op.hint.not_exposed' => 'the app does not expose %s over HTTP — expose the operation in config/http.php',
            'op.hint.confirm' => "%s asks for confirmation — the house's gate stands; a command does not confirm it",
            'op.hint.unreachable' => 'the operation could not be reached',
            'op.hint.failed' => 'the operation failed',
            // The last inline behaviours to become declared views (greenhouse decisions/0211, phase D):
            // the transport's state, the capabilities two-step, the cross-session inbox, the preview and
            // the screens that used to be raw HTML in the shell's template.
            'conn.live' => '◉ live',
            'conn.offline' => '○ offline',
            'conn.connecting' => '○ connecting…',
            'hub.waiting' => 'Waiting on you: %s',
            'cap.intro' => 'What this app can do today, and what it could — the same catalogue the agent reads.',
            'cap.confirm' => 'Confirm',
            'cap.cancel' => 'Cancel',
            'cap.working' => 'Working…',
            'cap.done' => 'Installed %s — reloading…',
            'cap.failed' => 'Could not install %s — %s',
            'cap.refused' => 'refused',
            'cap.no_token' => 'the house issued no confirm token',
            'decisions.intro' => 'Decisions an agent has parked for you, across every session — durable questions, not modals. Open the session to approve or refuse, with your passkey, in this origin.',
            'decisions.empty' => 'No decisions to make. When an agent parks a gate, it appears here for you to approve or refuse.',
            'decisions.just_now' => 'just now · open the conversation to answer',
            'decisions.unnamed' => 'A question is waiting for you.',
            'skills.intro' => 'The skills the agent carries — each guides judgment, it is not a tool that runs. The same list the agent reaches for.',
            'skills.roles' => 'Specialist roles',
            'skills.roles_intro' => 'Specialist agents this app declares — each a named authority with the skills it preloads and the tools it is denied.',
            'screens.intro' => 'See what the agent is building — a screen it declared, rendered live and hosted here. "How does it look?", answered.',
            'screens.name' => 'screen name',
            'screens.preview' => 'Preview',
            'screens.frame' => 'Live screen preview',
            'statusbar.model' => '%s · local model',
        ],
        'es' => [
            'chip.gate' => 'puerta: %s',
            'gate.kind.loopback' => 'loopback',
            'gate.kind.custom' => 'propia',
            'gate.kind.passkey' => 'passkey',
            'gate.kind.open' => 'abierta',
            'gate.kind.fallback' => 'respaldo',
            'gate.loopback.title' => 'Sólo loopback',
            'gate.loopback' => 'Milpa Desktop sólo responde a loopback por default. Declara desktop.middleware en config/app.php para ponerlo detrás de tu propia puerta.',
            'topbar.signed_in' => 'sesión iniciada como %s',
            'settings.saved' => 'Guardado',
            'settings.save_failed' => 'No se guardó (HTTP %s)',
            'guard.forbidden' => 'No permitido aquí',
            'guard.forbidden.reason' => 'No permitido aquí (%s)',
            'guard.failed' => 'La petición falló (HTTP %s)',
            'guard.unreachable' => 'No se pudo alcanzar la app',
            'enroll.none' => 'Esta app no tiene puerta de passkey',
            'agent.title' => 'Agente',
            'agent.open' => 'Abrir el Desktop',
            'agent.signin' => 'Inicia sesión para abrir el Agente',
            'agent.signin.action' => 'Iniciar sesión',
            'agent.surface.failed' => 'Esta parte del Agente no se pudo mostrar (%s).',
            'strip.session' => 'Sesión',
            'strip.none' => 'Sin sesión abierta',
            'strip.pick' => 'Elige una sesión',
            'strip.new' => 'Nueva sesión',
            'settings.model.title' => 'Modelo y proveedor',
            'settings.model.provider' => 'Proveedor',
            'settings.model.endpoint' => 'Endpoint',
            'settings.model.endpoint_hint' => 'El endpoint recibe contexto. No ejecuta operaciones.',
            'settings.model.stream' => 'Mostrar tokens en vivo',
            'settings.provider.local' => 'Modelo local',
            'settings.provider.lan' => 'Modelo en la red local',
            'settings.provider.external' => 'Proveedor externo',
            'settings.autonomy.title' => 'Autonomía por default',
            'settings.autonomy.ask' => 'Preguntar antes de cambiar',
            'settings.autonomy.ask_hint' => 'Pausa las mutaciones que no tienen permiso vigente.',
            'settings.autonomy.acknowledge' => 'Compatibilidad',
            'settings.autonomy.acknowledge_hint' => 'Hoy decide como auto: sin aviso previo observable.',
            'settings.autonomy.auto' => 'Continuar automáticamente',
            'settings.autonomy.auto_hint' => 'Las firmas y la intención incompleta se siguen deteniendo.',
            'settings.autonomy.note' => 'Los tres valores todavía no son tres niveles distintos en su comportamiento.',
            'settings.storage.title' => 'Contexto y almacenamiento',
            'settings.storage.compact' => 'Compactar el contexto automáticamente',
            'settings.storage.compact_note' => 'La compactación reduce lo que ve el modelo, nunca el registro de la sesión.',
            'settings.storage.folder' => 'Carpeta de sesiones',
            'settings.appearance.title' => 'Apariencia',
            'settings.appearance.theme' => 'Tema',
            'settings.appearance.scale' => 'Escala de la interfaz',
            'settings.theme.system' => 'Sistema',
            'settings.theme.dark' => 'Oscuro',
            'settings.theme.light' => 'Claro',
            'settings.discard' => 'Descartar cambios',
            'settings.save' => 'Guardar ajustes',
            'auth.kicker' => 'espacio de trabajo local',
            'auth.lede' => 'Abre una app Milpa para empezar, entender y retomar el trabajo de un agente. La sesión es la unidad; nada corre al abrir.',
            'auth.app' => 'App Milpa',
            'auth.app_hint' => 'Lee %s. Una app a la vez.',
            'auth.identity' => 'Identidad de decisión',
            'auth.identity.system' => 'Usuario del sistema',
            'auth.identity.system_hint' => 'No verificado. Las firmas de llamada se piden aparte.',
            'auth.identity.verified' => 'Principal con firma verificada',
            'auth.identity.verified_hint' => 'Requiere un mecanismo externo.',
            'auth.provider' => 'Proveedor de modelo',
            'auth.provider.local' => 'Modelo local · %s (%s)',
            'auth.warning.title' => 'Tu usuario del sistema no es una identidad verificada',
            'auth.warning.desc' => 'Autorizar en una sesión concede la operación; no es firmar la llamada.',
            'auth.enter' => 'Abrir espacio de trabajo',
            'verdict.verified' => 'verificado',
            'verdict.disputed' => 'disputado',
            'verdict.backed' => 'El ledger respalda este turno: cada paso completado carga evidencia, nada quedó abierto y ningún artefacto tiene su última verificación en rojo.',
            'verdict.disputed.why' => 'El ledger disputa este turno — %s.',
            'verdict.disputed.default' => 'la conclusión no está respaldada por evidencia',
            'verdict.aria.verified' => 'Verificado. %s',
            'verdict.aria.disputed' => 'Disputado. %s',
            'thinking.elapsed' => 'pensó por %ss',
            'turn.paused' => 'El agente está esperando tu decisión.',
            'turn.stop_requested' => 'se pidió detener',
            'session.state.working' => 'Trabajando',
            'session.state.idle' => 'Inactivo',
            'conversation.interrupted' => 'Una corrida previa quedó interrumpida — se quedó a media vuelta y no terminó. Vuelve a enviar para continuar; nada se retomó solo.',
            'preview.failed' => 'El wire no sirve «%s» (HTTP %s)',
            'preview.unreachable' => 'No se pudo alcanzar el wire para «%s»',
            'composer.tokens' => '~%s tokens',
            'composer.placeholder' => 'Escribe a la sesión…',
            'composer.attach' => 'adjuntar',
            'composer.send' => 'continuar la sesión',
            'composer.stop' => 'detener el turno',
            'command.unknown' => 'comando desconocido %s — comandos: %s',
            'command.mode.usage' => 'uso: /mode ask|acknowledge|auto',
            'command.mode.set' => 'modo %s — aplica desde el siguiente turno',
            'command.mode.set.auto' => 'modo %s — aplica desde el siguiente turno (una firma o un egreso a terceros sigue preguntando)',
            'command.goal.none' => 'no hay meta vigente — /goal <texto> pone una',
            'command.goal.cleared' => 'meta borrada',
            'command.goal.set' => 'meta puesta: %s',
            'command.goal.unchanged' => 'meta sin cambio: %s',
            'op.refused' => '%s rechazó — %s',
            'op.no_reason' => 'sin razón declarada',
            'op.failed' => '%s → HTTP %s — %s',
            'op.detail' => '%s (%s)',
            'op.hint.not_exposed' => 'la app no expone %s por HTTP — expón la operación en config/http.php',
            'op.hint.confirm' => '%s pide confirmación — la puerta de la casa sigue en pie; un comando no la confirma',
            'op.hint.unreachable' => 'no se pudo alcanzar la operación',
            'op.hint.failed' => 'la operación falló',
            'conn.live' => '◉ en vivo',
            'conn.offline' => '○ sin conexión',
            'conn.connecting' => '○ conectando…',
            'hub.waiting' => 'Te esperan: %s',
            'cap.intro' => 'Lo que esta app puede hacer hoy, y lo que podría — el mismo catálogo que lee el agente.',
            'cap.confirm' => 'Confirmar',
            'cap.cancel' => 'Cancelar',
            'cap.working' => 'Instalando…',
            'cap.done' => 'Se instaló %s — recargando…',
            'cap.failed' => 'No se pudo instalar %s — %s',
            'cap.refused' => 'rechazado',
            'cap.no_token' => 'la casa no emitió token de confirmación',
            'decisions.intro' => 'Decisiones que un agente dejó pendientes para ti, en todas las sesiones — preguntas duraderas, no modales. Abre la sesión para aprobar o rechazar, con tu passkey, en este origen.',
            'decisions.empty' => 'No hay decisiones que tomar. Cuando un agente deja un gate, aparece aquí para que apruebes o rechaces.',
            'decisions.just_now' => 'ahora · abre la conversación para responder',
            'decisions.unnamed' => 'Hay una pregunta esperándote.',
            'skills.intro' => 'Las skills que carga el agente — cada una guía el criterio, no es una herramienta que corre. La misma lista a la que echa mano el agente.',
            'skills.roles' => 'Roles especialistas',
            'skills.roles_intro' => 'Agentes especialistas que esta app declara — cada uno una autoridad con nombre, con las skills que precarga y las herramientas que tiene negadas.',
            'screens.intro' => 'Mira lo que el agente está construyendo — una pantalla que declaró, renderizada en vivo y hospedada aquí. «¿Cómo se ve?», contestado.',
            'screens.name' => 'nombre de pantalla',
            'screens.preview' => 'Previsualizar',
            'screens.frame' => 'Vista previa de la pantalla en vivo',
            'statusbar.model' => '%s · modelo local',
        ],
    ];

    private string $locale;

    public function __construct(string $locale = self::DEFAULT_LOCALE)
    {
        $this->locale = isset(self::MESSAGES[$locale]) ? $locale : self::DEFAULT_LOCALE;
    }

    /** The message for a key in the catalog's locale, with `sprintf` arguments applied; the key itself when unknown. */
    public function tr(string $key, string ...$args): string
    {
        $message = self::MESSAGES[$this->locale][$key] ?? self::MESSAGES[self::DEFAULT_LOCALE][$key] ?? $key;

        return $args === [] ? $message : vsprintf($message, $args);
    }

    /** True when the catalog knows the key in its locale or in English. */
    public function has(string $key): bool
    {
        return isset(self::MESSAGES[$this->locale][$key]) || isset(self::MESSAGES[self::DEFAULT_LOCALE][$key]);
    }

    /** The locale this catalog answers in. */
    public function locale(): string
    {
        return $this->locale;
    }

    /**
     * Every message of the catalog's locale, English filling the gaps — what the shell hands its client
     * script, so the browser says the same words the server does.
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        return self::MESSAGES[$this->locale] + self::MESSAGES[self::DEFAULT_LOCALE];
    }

    /**
     * The locales the catalog carries.
     *
     * @return list<string>
     */
    public static function locales(): array
    {
        return array_keys(self::MESSAGES);
    }
}

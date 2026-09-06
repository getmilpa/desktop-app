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

use Milpa\DesktopApp\Data\DesktopData;
use Milpa\DesktopApp\I18n\Catalog;
use Milpa\Interfaces\Event\MilpaEventDispatcherInterface;
use Milpa\Live\Security\HmacStateSigner;
use Milpa\Live\Security\SignedXhtmlStateTransferCodec;
use Milpa\Live\Transport\XhtmlStateTransferCodec;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\StateSnapshot;

/**
 * Renders the {@see ComposerBarComponent} — the composer bar, wireframe 3a (greenhouse decisions/0211, C2).
 *
 * The bar the shell used to stitch by hand: the floating Context and Session panels (open on their
 * figures, close as you type — WHICH one is open is the `composer.panel` signal), the text field (a real
 * milpa/live `<milpa:textarea>` when one is wired, a plain textarea otherwise), the mode chip and its
 * menu, the live token count, the two figure chips and the send button. Every number is real: the context
 * window and its usage from {@see DesktopData::context()}, the counters from {@see DesktopData::counters()}.
 *
 * Nothing in this markup pokes anything: the send button BINDS its glyph, its label and its `disabled` to
 * `session.working` and `composer.draft`, the menu binds to the component's own `menuOpen`, and the
 * chips' figures bind to the shared counter signals — one truth projected here, to the status bar and to
 * the panels alike. The behaviour behind the bindings is `desktop-composer.js`, declared by this
 * component's renderer and emitted once by `LiveBoot::html()`.
 *
 * The LOOK is `desktop-composer.css` and nothing else: this file carries no static `style="…"` attribute,
 * the way every other phase-C/D renderer carries none. The one inline style left is the context meter's
 * two CUSTOM PROPERTIES (`--composer-meter` / `--composer-meter-color`), which are data — the percentage
 * this session actually spent — read by a rule in the stylesheet.
 *
 * And the words are the {@see Catalog}'s, in the declared locale: the field's placeholder, the attach and
 * send controls' `aria-label`s and the permission modes' names. The mode's names are the SAME keys the
 * Settings screen uses (`settings.autonomy.*`) — one authority for one word.
 */
final class ComposerBar
{
    public const string COMPONENT_ID = 'composer';

    /** Dispatched with a mutable {@see ComposerRender} BEFORE the render — a subscriber may change its props. */
    public const string BEFORE_RENDER = 'desktop.composer_bar.before_render';

    /** Dispatched with a mutable {@see ComposerRender} AFTER the render — a subscriber may change its html. */
    public const string AFTER_RENDER = 'desktop.composer_bar.after_render';

    /**
     * The permission modes the chip offers, each naming the CATALOG KEY its words come from — the same
     * key the Settings screen renders, so the two can no longer disagree in any locale.
     *
     * @var array<string, string>
     */
    public const array MODE_KEYS = [
        'ask' => 'settings.autonomy.ask',
        'acknowledge' => 'settings.autonomy.acknowledge',
        'auto' => 'settings.autonomy.auto',
    ];

    private readonly SignedXhtmlStateTransferCodec $codec;

    private readonly Catalog $catalog;

    public function __construct(
        string $signingSecret,
        private readonly ?DesktopData $data = null,
        private readonly ?ComposerField $field = null,
        private readonly ?MilpaEventDispatcherInterface $events = null,
        ?Catalog $catalog = null,
    ) {
        $this->codec = new SignedXhtmlStateTransferCodec(new XhtmlStateTransferCodec(), new HmacStateSigner($signingSecret), null);
        $this->catalog = $catalog ?? new Catalog();
    }

    /** The bar, with its signed envelope, after the render events a plugin may extend it through. */
    public function render(): string
    {
        $component = new ComposerBarComponent();
        $subject = new ComposerRender(['mode' => $this->mode()]);
        $this->events?->dispatch(self::BEFORE_RENDER, ['composerBar' => $subject]);

        $state = $component->mount($subject->props, new ComponentContext(componentId: self::COMPONENT_ID, route: ComposerField::ROUTE));
        $subject->html = $this->markup() . $this->envelope($state);

        $this->events?->dispatch(self::AFTER_RENDER, ['composerBar' => $subject]);

        return $subject->html;
    }

    /**
     * The commands the popup lists (greenhouse decisions/0202): the house's own plus every user-invocable
     * skill, or the house's alone when no data seam is wired.
     *
     * @return list<array{name: string, kind: string, description: string, usage: string, method: string}>
     */
    public function commands(): array
    {
        return $this->data?->commands() ?? DesktopData::houseCommands();
    }

    /**
     * One permission mode's name in the declared locale — the one authority the chip, its menu and the
     * Settings screen all read.
     */
    public static function modeLabel(Catalog $catalog, string $mode): string
    {
        return $catalog->tr(self::MODE_KEYS[$mode] ?? self::MODE_KEYS['ask']);
    }

    /** The permission mode the chip shows: the saved setting, or the mode that asks. */
    private function mode(): string
    {
        $settings = $this->data?->settings() ?? [];

        return \is_string($settings['mode'] ?? null) && isset(self::MODE_KEYS[$settings['mode']]) ? (string) $settings['mode'] : 'ask';
    }

    /** Format a token count as "9.25K". */
    private function kfmt(int $n): string
    {
        return number_format($n / 1000, 2) . 'K';
    }

    /** One catalog message, escaped for the markup. */
    private function tr(string $key, string ...$args): string
    {
        return htmlspecialchars($this->catalog->tr($key, ...$args), ENT_QUOTES);
    }

    private function markup(): string
    {
        $ctx = $this->data?->context() ?? ['tokens' => 0, 'window' => 32768, 'used_pct' => 0, 'free' => 32768];
        $c = $this->data?->counters() ?? ['turns' => 0, 'steps' => 0, 'tokens' => 0, 'tool_calls' => 0, 'state' => 'idle'];
        $model = htmlspecialchars($this->data?->model()['model'] ?? 'qwen3.8-27b', ENT_QUOTES);
        $tokens = $this->kfmt((int) $ctx['tokens']);
        $window = $this->kfmt((int) $ctx['window']);
        $free = $this->kfmt((int) $ctx['free']);
        $pct = $ctx['used_pct'];
        $barColor = $pct < 70 ? 'var(--success)' : ($pct < 90 ? 'var(--warning)' : 'var(--danger)');

        $modeKey = $this->mode();
        $modeLabel = htmlspecialchars(self::modeLabel($this->catalog, $modeKey), ENT_QUOTES);
        $modeMenu = '';
        foreach (array_keys(self::MODE_KEYS) as $key) {
            // The current mark is a BINDING on the component's own `isMode()`, so the chip, the menu and
            // `/mode` read one value instead of three that can drift.
            $label = htmlspecialchars(self::modeLabel($this->catalog, $key), ENT_QUOTES);
            $modeMenu .= sprintf(
                '<button type="button" role="menuitem" class="milpa-mode-opt mui-btn mui-btn--ghost mui-btn--sm mui-btn--full" data-mode="%s" data-label="%s"%s :aria-current="isMode(\'%s\') ? \'true\' : false" @click="pick(\'%s\')">%s<span class="mui-badge milpa-mode-opt__badge">%s</span></button>',
                $key,
                $label,
                $key === $modeKey ? ' aria-current="true"' : '',
                $key,
                $key,
                $label,
                $key,
            );
        }

        // The composer's text field IS a milpa/live component when the framework's UI system is wired
        // (greenhouse decisions/0189); otherwise a plain textarea (backwards-compatible fallback), whose
        // seamless look is the stylesheet's `.milpa-composer-box .mui-textarea` rule, not an attribute.
        $placeholder = $this->tr('composer.placeholder');
        $field = $this->field !== null
            ? $this->field->render()
            : '<textarea id="composer-input" class="mui-textarea" rows="2" placeholder="' . $placeholder . '"></textarea>';
        $commandList = (new CommandListView())->html($this->commands());
        $attach = $this->tr('composer.attach');
        $send = $this->tr('composer.send');
        // The same two words INSIDE an Alpine expression: a JS string literal in an HTML attribute, so an
        // apostrophe in some locale's word has to survive both readings, not just the HTML one.
        $sendJs = htmlspecialchars(addcslashes($this->catalog->tr('composer.send'), "\\'"), ENT_QUOTES);
        $stopJs = htmlspecialchars(addcslashes($this->catalog->tr('composer.stop'), "\\'"), ENT_QUOTES);

        return <<<HTML
<div class="composer-wrap" data-milpa-component="desktop-composer" data-milpa-component-id="composer" x-data="desktopComposer()">
  <div class="composer-panels">

    <div class="composer-panel composer-panel--session" data-panel-for="session" hidden :hidden="\$store.milpa['composer.panel'] !== 'session'">
      <p class="composer-panel__title">Session <span class="composer-panel__muted">· {$c['turns']} turns</span></p>
      <div class="composer-panel__rule"></div>
      <p class="composer-panel__row"><span class="composer-panel__key">Steps</span><span>{$c['steps']}</span></p>
      <p class="composer-panel__row"><span class="composer-panel__key">Tool calls</span><span>{$c['tool_calls']}</span></p>
      <p class="composer-panel__row"><span class="composer-panel__key">State</span><span class="composer-panel__state">{$c['state']}</span></p>
    </div>

    <div class="composer-panel composer-panel--context" data-panel-for="context" hidden :hidden="\$store.milpa['composer.panel'] !== 'context'">
      <p class="composer-panel__title">Context <span class="composer-panel__muted">· {$tokens} / {$window}</span></p>
      <div class="mui-progress composer-meter" role="progressbar" aria-valuenow="{$pct}" aria-valuemin="0" aria-valuemax="100"><span class="mui-progress__bar composer-meter__bar" style="--composer-meter:{$pct}%;--composer-meter-color:{$barColor}"></span></div>
      <p class="composer-panel__row composer-panel__foot"><span>{$pct}% used</span><span>{$free} free</span></p>
    </div>

  </div>

  {$commandList}
  <div class="milpa-composer-box">
    {$field}
    <div class="composer-row">
      <button type="button" class="mui-btn mui-btn--ghost mui-btn--sm mui-btn--icon composer-round" aria-label="{$attach}">＋</button>
      <span class="composer-mode">
        <button type="button" class="mui-badge composer-mode__chip" id="milpa-mode-chip" aria-haspopup="true" aria-expanded="false" :aria-expanded="menuOpen ? 'true' : 'false'" @click="toggleMenu(\$event)"><span id="milpa-mode-label" x-text="\$store.milpa['composer.mode.label']">{$modeLabel}</span><span class="composer-mode__caret" aria-hidden="true">▾</span></button>
        <div id="milpa-mode-menu" class="composer-mode__menu" hidden :hidden="!menuOpen" @click.stop role="menu">{$modeMenu}</div>
      </span>
      <span class="composer-meta">
        <span id="milpa-charcount" class="composer-count" aria-live="polite"></span>
        <button type="button" class="composer-chip" data-open-panel="session" @click="\$store.milpa['composer.panel'] = \$store.milpa['composer.panel'] === 'session' ? '' : 'session'">◈ <span x-text="\$store.milpa['session.counters']">{$c['turns']} turns · {$c['tool_calls']} tools</span></button>
        <button type="button" class="composer-chip" data-open-panel="context" @click="\$store.milpa['composer.panel'] = \$store.milpa['composer.panel'] === 'context' ? '' : 'context'">▤ <span x-text="\$store.milpa['context.usage']">{$tokens}/{$window}</span></button>
        <button type="button" class="mui-btn mui-btn--primary mui-btn--icon composer-round" id="milpa-send" aria-label="{$send}" disabled :disabled="!\$store.milpa['session.working'] && !\$store.milpa['composer.draft']" :aria-label="\$store.milpa['session.working'] ? '{$stopJs}' : '{$sendJs}'" @click="working ? stop() : send()"><span x-text="\$store.milpa['session.working'] ? '■' : '↑'">↑</span></button>
      </span>
    </div>
  </div>
  <p class="composer-model">Model: {$model} · panels open on their figures, close as you type.</p>
</div>
HTML;
    }

    private function envelope(StateSnapshot $state): string
    {
        return '<script type="application/milpa+xhtml" data-milpa-state="' . self::COMPONENT_ID . '">' . $this->codec->encodeState($state) . '</script>';
    }
}

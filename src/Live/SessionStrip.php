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
 * Renders the session strip of embed mode as a {@see SessionStripComponent} (greenhouse decisions/0210) — one
 * more surface of the "shell is pure Milpa Components" rule (decisions/0189), never a hand-written view. It
 * mounts the component over the same data the sidebar reads, produces the row (the current goal, a `<select>`
 * of every session with the current one selected, the «New session» control), carries the signed state
 * envelope, and emits `desktop.session_strip.before_render` / `after_render` so other plugins can extend it.
 *
 * Rendered only in embed mode, by {@see \Milpa\DesktopApp\Controllers\ShellController}. The controls keep the
 * ids and the `data-new-session` hook the shell script wires to the SAME handlers as the sidebar's.
 */
final class SessionStrip
{
    public const string COMPONENT_ID = 'session-strip';
    public const string BEFORE_RENDER = 'desktop.session_strip.before_render';
    public const string AFTER_RENDER = 'desktop.session_strip.after_render';

    /** The row's element id, and the picker's — the ids the shell script looks up. */
    public const string ID = 'milpa-session-strip';
    public const string SELECT_ID = 'milpa-embed-session';

    private readonly SignedXhtmlStateTransferCodec $codec;
    private readonly Catalog $catalog;

    /**
     * @param Catalog|null $catalog the copy's catalog; null answers in English
     */
    public function __construct(
        string $signingSecret,
        private readonly ?DesktopData $data = null,
        private readonly ?MilpaEventDispatcherInterface $events = null,
        ?Catalog $catalog = null,
    ) {
        $this->codec = new SignedXhtmlStateTransferCodec(new XhtmlStateTransferCodec(), new HmacStateSigner($signingSecret), null);
        $this->catalog = $catalog ?? new Catalog();
    }

    /** The strip's server-rendered HTML — a component with its signed envelope. */
    public function render(): string
    {
        $component = new SessionStripComponent();
        $props = [
            'sessions' => $this->data?->sessions() ?? [],
            'activeSession' => $this->data?->currentSessionId() ?? '',
        ];
        $subject = new ComposerRender($props);
        $this->events?->dispatch(self::BEFORE_RENDER, ['sessionStrip' => $subject]);

        $context = new ComponentContext(componentId: self::COMPONENT_ID);
        $state = $component->mount($subject->props, $context);
        $subject->html = $this->markup($state) . $this->envelope($state);

        $this->events?->dispatch(self::AFTER_RENDER, ['sessionStrip' => $subject]);

        return $subject->html;
    }

    /**
     * The row: the current goal (or «No session open»), the `<select>` with the current session selected (a
     * placeholder option when there is none; a session without a goal is named by its id; garbage rows are
     * skipped), and the «New session» control.
     */
    private function markup(StateSnapshot $state): string
    {
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $current = (string) ($state->data['activeSession'] ?? '');
        $sessions = \is_array($state->meta['sessions'] ?? null) ? $state->meta['sessions'] : [];
        $goal = '';
        $options = '';
        foreach ($sessions as $session) {
            if (!\is_array($session)) {
                continue;
            }
            $id = (string) ($session['id'] ?? '');
            $sessionGoal = (string) ($session['goal'] ?? '');
            $selected = $id !== '' && $id === $current;
            if ($selected) {
                $goal = $sessionGoal;
            }
            $options .= \sprintf(
                '<option value="%s"%s>%s</option>',
                $e($id),
                $selected ? ' selected' : '',
                $e(($sessionGoal !== '' ? $sessionGoal : $id) . ' · ' . (string) ($session['state'] ?? '')),
            );
        }
        if ($options === '') {
            $options = '<option value="" selected disabled>' . $e($this->catalog->tr('strip.none')) . '</option>';
        } elseif ($goal === '') {
            $options = '<option value="" selected disabled>' . $e($this->catalog->tr('strip.pick')) . '</option>' . $options;
        }

        return '<div class="milpa-session-strip" id="' . self::ID . '" role="region" aria-label="' . $e($this->catalog->tr('strip.session')) . '"'
            . ' data-milpa-runtime="alpine" data-milpa-component="' . SessionStripComponent::NAME . '" data-milpa-component-id="' . self::COMPONENT_ID . '" x-data>'
            . '<span class="milpa-session-strip__goal" id="' . self::ID . '-goal">' . $e($goal !== '' ? $goal : $this->catalog->tr('strip.none')) . '</span>'
            . '<span class="mui-select-wrap milpa-session-strip__pick"><select class="mui-select mui-select--sm" id="' . self::SELECT_ID . '" aria-label="' . $e($this->catalog->tr('strip.session')) . '">' . $options . '</select></span>'
            . '<button type="button" class="mui-btn mui-btn--subtle mui-btn--sm" data-new-session>' . $e($this->catalog->tr('strip.new')) . '</button>'
            . '</div>';
    }

    private function envelope(StateSnapshot $state): string
    {
        return '<script type="application/milpa+xhtml" data-milpa-state="' . self::COMPONENT_ID . '">' . $this->codec->encodeState($state) . '</script>';
    }
}

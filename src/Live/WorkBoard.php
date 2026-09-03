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
use Milpa\Interfaces\Event\MilpaEventDispatcherInterface;
use Milpa\Live\Security\HmacStateSigner;
use Milpa\Live\Security\SignedXhtmlStateTransferCodec;
use Milpa\Live\Transport\XhtmlStateTransferCodec;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\StateSnapshot;

/**
 * Renders the Desktop shell's Work board as a {@see WorkBoardComponent} — the fourth surface of the "shell is
 * pure Milpa Components" migration (greenhouse decisions/0189). It mounts the component, produces the
 * design-system board (columns by status, draggable cards), carries the signed state envelope, and emits
 * `desktop.work_board.before_render` / `after_render` so other plugins can extend it (decorate columns/cards).
 *
 * Moving a card persists through the dedicated `/desktop/work` mutation (greenhouse decisions/0484); that
 * drag-drop transport is unchanged — this makes the board a declared, signed, extensible component.
 */
final class WorkBoard
{
    public const string COMPONENT_ID = 'work-board';
    public const string BEFORE_RENDER = 'desktop.work_board.before_render';
    public const string AFTER_RENDER = 'desktop.work_board.after_render';

    /** @var array<string, string> */
    private const COLUMNS = ['pending' => 'Pending', 'in_progress' => 'In progress', 'done' => 'Done', 'blocked' => 'Blocked'];

    private readonly SignedXhtmlStateTransferCodec $codec;

    public function __construct(
        string $signingSecret,
        private readonly ?DesktopData $data = null,
        private readonly ?MilpaEventDispatcherInterface $events = null,
    ) {
        $this->codec = new SignedXhtmlStateTransferCodec(new XhtmlStateTransferCodec(), new HmacStateSigner($signingSecret), null);
    }

    /** The board's server-rendered HTML — a component with its signed envelope, or the empty state. */
    public function render(): string
    {
        $component = new WorkBoardComponent();
        $props = [
            'work' => $this->data?->work() ?? [],
            'sessionId' => $this->data?->currentSessionId() ?? '',
        ];
        $subject = new ComposerRender($props);
        $this->events?->dispatch(self::BEFORE_RENDER, ['workBoard' => $subject]);

        $context = new ComponentContext(componentId: self::COMPONENT_ID);
        $state = $component->mount($subject->props, $context);
        $subject->html = $this->markup($subject->props) . $this->envelope($state);

        $this->events?->dispatch(self::AFTER_RENDER, ['workBoard' => $subject]);

        return $subject->html;
    }

    /** @param array<string, mixed> $props */
    private function markup(array $props): string
    {
        /** @var list<array{title: string, status: string, origin: string}> $work */
        $work = \is_array($props['work'] ?? null) ? $props['work'] : [];
        $wrap = 'data-milpa-component="desktop-work-board" data-milpa-component-id="' . self::COMPONENT_ID . '"';

        if ($work === []) {
            return '<div class="mui-empty" ' . $wrap . '><p class="mui-empty__title">No work board yet</p>'
                . '<p class="mui-empty__desc">A session writes its plan as work items; they appear here by status.</p></div>';
        }

        $session = htmlspecialchars((string) ($props['sessionId'] ?? ''), ENT_QUOTES);
        $byStatus = ['pending' => '', 'in_progress' => '', 'done' => '', 'blocked' => ''];
        foreach ($work as $i => $item) {
            $status = \array_key_exists($item['status'], self::COLUMNS) ? $item['status'] : 'pending';
            $byStatus[$status] .= sprintf(
                '<article class="mui-card mui-card--compact" draggable="true" data-index="%d" style="cursor:grab"><div class="mui-card__body"><p style="margin:0 0 var(--space-3);font-size:var(--text-sm)">%s</p><span class="mui-badge">%s</span></div></article>',
                $i,
                htmlspecialchars($item['title'], ENT_QUOTES),
                htmlspecialchars($item['origin'], ENT_QUOTES),
            );
        }

        $out = '<div class="work-board" ' . $wrap . ' data-session="' . $session . '" style="display:grid;grid-template-columns:repeat(4,1fr);gap:var(--space-4);align-items:start">';
        foreach (self::COLUMNS as $key => $label) {
            $out .= sprintf(
                '<section class="work-col" data-status="%s" style="display:flex;flex-direction:column;gap:var(--space-3);min-height:8rem;padding:var(--space-2);border-radius:var(--radius-md)"><div class="mui-cluster mui-cluster--sm" style="justify-content:space-between"><span class="mui-section__kicker" style="margin:0">%s</span></div>%s</section>',
                htmlspecialchars($key, ENT_QUOTES),
                htmlspecialchars($label, ENT_QUOTES),
                $byStatus[$key],
            );
        }

        return $out . '</div>';
    }

    private function envelope(StateSnapshot $state): string
    {
        return '<script type="application/milpa+xhtml" data-milpa-state="' . self::COMPONENT_ID . '">' . $this->codec->encodeState($state) . '</script>';
    }
}

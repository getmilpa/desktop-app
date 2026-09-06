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
 * Moving a card persists through the dedicated `/desktop/work` mutation (greenhouse decisions/0484). Since
 * phase D of the declared views (greenhouse decisions/0211) the gesture is the board's OWN module:
 * `desktop-work-board.js` (declared by this renderer, delegated on the root) and `desktop-work-board.css`,
 * so the drag's look is CSS state and not a style assigned from JavaScript.
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
                '<article class="mui-card mui-card--compact work-card" draggable="true" data-index="%d"><div class="mui-card__body"><p class="work-card__title">%s</p><span class="mui-badge">%s</span></div></article>',
                $i,
                htmlspecialchars($item['title'], ENT_QUOTES),
                htmlspecialchars($item['origin'], ENT_QUOTES),
            );
        }

        // Every drag event is DELEGATED on the board's own root (greenhouse decisions/0211, D3), so a card
        // or a column painted later is draggable without anything re-wiring listeners onto it.
        $out = '<div class="work-board" ' . $wrap . ' data-session="' . $session . '"'
            . ' x-data="desktopWorkBoard()" @dragstart="onDragStart($event)" @dragend="onDragEnd($event)"'
            . ' @dragover="onDragOver($event)" @dragleave="onDragLeave($event)" @drop="onDrop($event)">';
        foreach (self::COLUMNS as $key => $label) {
            $out .= sprintf(
                '<section class="work-col" data-status="%s"><div class="mui-cluster mui-cluster--sm work-col__head"><span class="mui-section__kicker work-col__title">%s</span></div>%s</section>',
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

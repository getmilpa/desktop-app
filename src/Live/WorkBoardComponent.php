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

use Milpa\Live\Contracts\Component\ComponentDefinitionInterface;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\ComponentContract;
use Milpa\Live\ValueObjects\InteractionRequest;
use Milpa\Live\ValueObjects\InteractionResult;
use Milpa\Live\ValueObjects\StateSnapshot;

/**
 * The Desktop shell's Work board (columns by status: Pending / In progress / Done / Blocked) as a milpa/live
 * component — the fourth surface of the "shell is pure Milpa Components" migration (greenhouse decisions/0189).
 *
 * The board is a PROJECTION surface: it renders the session's work items grouped by status and owns no
 * interactive state. Moving a card between columns PERSISTS through the dedicated `/desktop/work` mutation
 * (greenhouse decisions/0484) — that transport is unchanged; this migration makes the board a declared,
 * signed, extensible component (plugins can decorate columns/cards via its render events), not a rewrite of
 * the drag-drop persistence.
 *
 * @phpstan-type WorkItem array{title: string, status: string, origin: string}
 */
final class WorkBoardComponent implements ComponentDefinitionInterface
{
    /** The component contract: the work items and session props, a read-only state, no live actions. */
    public static function contract(): ComponentContract
    {
        return new ComponentContract(
            name: 'desktop-work-board',
            contractVersion: '1',
            summary: "The Desktop shell's Work board: session work items in columns by status.",
            designContract: '@milpa/design:components/milpa-work-board.contract.json',
            propsSchema: [
                'work' => ['type' => 'array', 'default' => []],
                'sessionId' => ['type' => 'string', 'default' => ''],
            ],
            stateSchema: ['count' => ['type' => 'int']],
            actions: [],
        );
    }

    /** Mount from props: the item count in state; the work items and session id in meta. */
    public function mount(array $props, ComponentContext $context): StateSnapshot
    {
        $work = \is_array($props['work'] ?? null) ? $props['work'] : [];

        return new StateSnapshot(
            $context->componentId,
            'desktop-work-board',
            '1',
            ['count' => \count($work)],
            [
                'work' => $work,
                'sessionId' => (string) ($props['sessionId'] ?? ''),
            ],
        );
    }

    /** The board is a projection surface: moving a card persists through /desktop/work, so this is a no-op. */
    public function handle(InteractionRequest $request): InteractionResult
    {
        return new InteractionResult(state: $request->state);
    }
}

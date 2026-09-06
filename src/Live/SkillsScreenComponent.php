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
 * The Skills screen as a milpa/live component (greenhouse decisions/0211, phase D5).
 *
 * What the agent CARRIES (greenhouse decisions/0197): the skills that guide its judgment — none of them a
 * tool that runs — and the specialist roles the app declares, each with the skills it preloads and the
 * tools it is denied. One screen, two lists, both read-only.
 *
 * It is the one phase-D surface with NO module: it has no behaviour of its own, so it declares a
 * stylesheet and nothing else. That is why it is a component at all — its look had nowhere else to live
 * once the shell's `<style>` stopped being a home for other people's CSS.
 */
final class SkillsScreenComponent implements ComponentDefinitionInterface
{
    /** The screen: how many skills the agent carries and how many roles the app declares. */
    public static function contract(): ComponentContract
    {
        return new ComponentContract(
            name: 'desktop-skills',
            contractVersion: '1',
            summary: 'What the agent carries: its skills and the specialist roles the app declares.',
            designContract: '@milpa/design:components/milpa-skills.contract.json',
            propsSchema: [
                'skills' => ['type' => 'array', 'default' => []],
                'roles' => ['type' => 'array', 'default' => []],
            ],
            stateSchema: [
                'skills' => ['type' => 'integer'],
                'roles' => ['type' => 'integer'],
            ],
            actions: [],
        );
    }

    /** Mount from props: the two counts are the state. */
    public function mount(array $props, ComponentContext $context): StateSnapshot
    {
        return new StateSnapshot(
            $context->componentId,
            'desktop-skills',
            '1',
            [
                'skills' => \count(\is_array($props['skills'] ?? null) ? $props['skills'] : []),
                'roles' => \count(\is_array($props['roles'] ?? null) ? $props['roles'] : []),
            ],
            [],
        );
    }

    /** Inert: a skill is invoked from the composer (`/<skill>`), never from this list. */
    public function handle(InteractionRequest $request): InteractionResult
    {
        return new InteractionResult(state: $request->state);
    }
}

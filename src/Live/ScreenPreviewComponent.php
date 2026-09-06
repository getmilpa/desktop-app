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
 * The declared-screen preview as a milpa/live component (greenhouse decisions/0211, phase D4).
 *
 * "How does it look?", answered (greenhouse decisions/0197): a screen the agent declared is served live by
 * the wire with no code deploy, and this surface points an iframe at it. Its state is the live route the
 * chips and the manual box build a path from — so the route is the component's, not a global.
 */
final class ScreenPreviewComponent implements ComponentDefinitionInterface
{
    /** The preview: which live route serves the declared screens, and how many there are. */
    public static function contract(): ComponentContract
    {
        return new ComponentContract(
            name: 'desktop-screens',
            contractVersion: '1',
            summary: 'The declared-screen preview: pick a screen, see it rendered live.',
            designContract: '@milpa/design:components/milpa-screens.contract.json',
            propsSchema: [
                'route' => ['type' => 'string', 'default' => '/live'],
                'screens' => ['type' => 'array', 'default' => []],
            ],
            stateSchema: [
                'route' => ['type' => 'string'],
                'screens' => ['type' => 'integer'],
            ],
            actions: [],
        );
    }

    /** Mount from props: the live route and how many screens were declared. */
    public function mount(array $props, ComponentContext $context): StateSnapshot
    {
        $route = \is_string($props['route'] ?? null) && $props['route'] !== '' ? (string) $props['route'] : '/live';

        return new StateSnapshot(
            $context->componentId,
            'desktop-screens',
            '1',
            ['route' => $route, 'screens' => \count(\is_array($props['screens'] ?? null) ? $props['screens'] : [])],
            [],
        );
    }

    /** Inert: the preview is a same-origin iframe, and the screen it shows is the live wire's own render. */
    public function handle(InteractionRequest $request): InteractionResult
    {
        return new InteractionResult(state: $request->state);
    }
}

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
use Milpa\Live\Security\SignedXhtmlStateTransferCodec;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\StateSnapshot;

/**
 * Renders the {@see DecisionsInboxComponent} — the decisions screen (greenhouse decisions/0211, phase D4).
 *
 * The cards themselves stay the pure {@see DecisionsInboxView} (tested with fixtures); this renderer wraps
 * them with the screen's lede, its signed envelope, its lifecycle events — and the CARD PROTOTYPE the
 * module clones when a question is parked while the page is open.
 *
 * That prototype is what makes the live inbox a declared view: the page's inline script used to build a
 * card with `createElement`, so its markup existed only inside a function. `desktop-decisions.js` clones
 * this `<template>` and fills two regions, exactly the way a conversation message kind lands.
 */
final class DecisionsInbox
{
    public const string COMPONENT_ID = 'decisions';

    /** Dispatched with a mutable {@see ComposerRender} BEFORE the render — a subscriber may change its props. */
    public const string BEFORE_RENDER = 'desktop.decisions.before_render';

    /** Dispatched with a mutable {@see ComposerRender} AFTER the render — a subscriber may change its html. */
    public const string AFTER_RENDER = 'desktop.decisions.after_render';

    public function __construct(
        private readonly SignedXhtmlStateTransferCodec $codec,
        private readonly ?DesktopData $data = null,
        private readonly ?MilpaEventDispatcherInterface $events = null,
        private readonly ?Catalog $catalog = null,
    ) {
    }

    /** The screen, with its signed envelope, after the render events a plugin may extend it through. */
    public function render(): string
    {
        $subject = new ComposerRender(['pending' => $this->data?->pendingDecisions() ?? []]);
        $this->events?->dispatch(self::BEFORE_RENDER, ['decisions' => $subject]);

        $state = (new DecisionsInboxComponent())->mount($subject->props, new ComponentContext(componentId: self::COMPONENT_ID));
        $subject->html = $this->markup($subject->props) . $this->envelope($state);

        $this->events?->dispatch(self::AFTER_RENDER, ['decisions' => $subject]);

        return $subject->html;
    }

    /** @param array<string, mixed> $props */
    private function markup(array $props): string
    {
        /** @var list<array{session: string, goal: string, question: string, operation: string, reason: string}> $pending */
        $pending = \is_array($props['pending'] ?? null) ? $props['pending'] : [];

        return '<div class="view milpa-decisions" data-view="decisions"'
            . ' data-milpa-component="desktop-decisions" data-milpa-component-id="' . self::COMPONENT_ID . '" hidden>'
            . '<p class="milpa-decisions__intro">' . $this->tr('decisions.intro') . '</p>'
            . (new DecisionsInboxView())->html($pending, $this->plain('decisions.empty'))
            . '<template id="milpa-decision-proto">'
            . '<li class="decision-card"><p class="decision-card__q" data-decision-question></p>'
            . '<p class="decision-card__facts" data-decision-facts></p></li>'
            . '</template>'
            . '</div>';
    }

    private function envelope(StateSnapshot $state): string
    {
        return '<script type="application/milpa+xhtml" data-milpa-state="' . self::COMPONENT_ID . '">' . $this->codec->encodeState($state) . '</script>';
    }

    /** One catalog message, escaped for the markup. */
    private function tr(string $key): string
    {
        return htmlspecialchars($this->plain($key), ENT_QUOTES);
    }

    /** One catalog message, RAW — for a view that escapes what it is handed. */
    private function plain(string $key): string
    {
        return ($this->catalog ?? new Catalog())->tr($key);
    }
}

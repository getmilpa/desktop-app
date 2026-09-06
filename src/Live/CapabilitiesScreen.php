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
 * Renders the {@see CapabilitiesScreenComponent} — the Capabilities screen (greenhouse decisions/0211, D2).
 *
 * The screen was the last one the shell's template carried as raw HTML with its behaviour in the page's
 * inline script. It is a declared view now: the catalogue itself stays the pure {@see
 * CapabilityCatalogueView} (tested with fixtures), and this renderer wraps it with the screen's lede, its
 * signed envelope, its lifecycle events — and the CONFIRM BOX as a server-rendered prototype.
 *
 * The prototype is the point of the phase: the two-step used to build that box with `innerHTML` in the
 * page, so the only place its markup existed was a JavaScript string. It is a `<template>` the server
 * renders now, exactly like a message kind's, and `desktop-capabilities.js` clones and fills it.
 */
final class CapabilitiesScreen
{
    public const string COMPONENT_ID = 'capabilities';

    /** Dispatched with a mutable {@see ComposerRender} BEFORE the render — a subscriber may change its props. */
    public const string BEFORE_RENDER = 'desktop.capabilities.before_render';

    /** Dispatched with a mutable {@see ComposerRender} AFTER the render — a subscriber may change its html. */
    public const string AFTER_RENDER = 'desktop.capabilities.after_render';

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
        $catalogue = $this->data?->capabilityCatalogue() ?? ['installed' => [], 'available' => []];
        $subject = new ComposerRender(['installed' => $catalogue['installed'], 'available' => $catalogue['available']]);
        $this->events?->dispatch(self::BEFORE_RENDER, ['capabilities' => $subject]);

        $state = (new CapabilitiesScreenComponent())->mount($subject->props, new ComponentContext(componentId: self::COMPONENT_ID));
        $subject->html = $this->markup($subject->props) . $this->envelope($state);

        $this->events?->dispatch(self::AFTER_RENDER, ['capabilities' => $subject]);

        return $subject->html;
    }

    /** @param array<string, mixed> $props */
    private function markup(array $props): string
    {
        /** @var list<array<string, mixed>> $installed */
        $installed = \is_array($props['installed'] ?? null) ? $props['installed'] : [];
        /** @var list<array<string, mixed>> $available */
        $available = \is_array($props['available'] ?? null) ? $props['available'] : [];

        return '<div class="view milpa-capabilities" data-view="capabilities" data-milpa-runtime="alpine"'
            . ' data-milpa-component="desktop-capabilities" data-milpa-component-id="' . self::COMPONENT_ID . '"'
            . ' x-data="desktopCapabilities()" @click="onClick($event)" hidden>'
            . '<p class="milpa-capabilities__intro">' . $this->tr('cap.intro') . '</p>'
            . '<div id="milpa-capabilities">' . (new CapabilityCatalogueView())->html($installed, $available) . '</div>'
            . $this->confirmPrototype()
            . '</div>';
    }

    /**
     * The confirm box, server-rendered ONCE as a prototype the module clones per card.
     *
     * The command it shows and the outcome it reports are filled as `textContent`, so nothing about this
     * box is ever assembled out of a JavaScript string — the lesson the message kinds already carry
     * (greenhouse decisions/0191).
     *
     * Its action row is `data-cap-actions`, not `data-cap-row`: that name already means «an available
     * capability's card» in {@see CapabilityCatalogueView}, and one attribute with two meanings is a
     * selector waiting to pick the wrong node — the box is appended INTO the card, so both were in scope.
     */
    private function confirmPrototype(): string
    {
        return '<template id="milpa-cap-confirm-proto">'
            . '<div class="cap-confirm">'
            . '<p class="cap-confirm__cmd" data-cap-cmd-text></p>'
            . '<div class="cap-confirm__row" data-cap-actions>'
            . '<button type="button" class="mui-btn mui-btn--primary mui-btn--sm" data-cap-go>' . $this->tr('cap.confirm') . '</button>'
            . '<button type="button" class="mui-btn mui-btn--sm" data-cap-cancel>' . $this->tr('cap.cancel') . '</button>'
            . '</div></div></template>';
    }

    private function envelope(StateSnapshot $state): string
    {
        return '<script type="application/milpa+xhtml" data-milpa-state="' . self::COMPONENT_ID . '">' . $this->codec->encodeState($state) . '</script>';
    }

    /** One catalog message, escaped for the markup. */
    private function tr(string $key): string
    {
        return htmlspecialchars(($this->catalog ?? new Catalog())->tr($key), ENT_QUOTES);
    }
}

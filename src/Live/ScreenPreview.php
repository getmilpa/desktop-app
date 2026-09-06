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
 * Renders the {@see ScreenPreviewComponent} — the Preview screen (greenhouse decisions/0211, phase D4).
 *
 * The chips stay the pure {@see ScreenPreviewView} (tested with fixtures); this renderer wraps them with
 * the lede, the name box, the Preview button that carries the live route as DATA on its own element, the
 * iframe and the signed envelope. `desktop-screens.js` reads that route from the markup it was given —
 * it resolves no global and knows no configuration.
 */
final class ScreenPreview
{
    public const string COMPONENT_ID = 'screens';

    /** Dispatched with a mutable {@see ComposerRender} BEFORE the render — a subscriber may change its props. */
    public const string BEFORE_RENDER = 'desktop.screens.before_render';

    /** Dispatched with a mutable {@see ComposerRender} AFTER the render — a subscriber may change its html. */
    public const string AFTER_RENDER = 'desktop.screens.after_render';

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
        $subject = new ComposerRender([
            'route' => $this->data?->liveRoute() ?? '/live',
            'screens' => $this->data?->declaredScreens() ?? [],
        ]);
        $this->events?->dispatch(self::BEFORE_RENDER, ['screens' => $subject]);

        $state = (new ScreenPreviewComponent())->mount($subject->props, new ComponentContext(componentId: self::COMPONENT_ID));
        $subject->html = $this->markup($subject->props) . $this->envelope($state);

        $this->events?->dispatch(self::AFTER_RENDER, ['screens' => $subject]);

        return $subject->html;
    }

    /** @param array<string, mixed> $props */
    private function markup(array $props): string
    {
        /** @var list<array{name: string, type: string, served_at: string}> $screens */
        $screens = \is_array($props['screens'] ?? null) ? $props['screens'] : [];
        $route = htmlspecialchars(\is_string($props['route'] ?? null) ? (string) $props['route'] : '/live', ENT_QUOTES);

        return '<div class="view milpa-screens" data-view="preview" data-milpa-runtime="alpine"'
            . ' data-milpa-component="desktop-screens" data-milpa-component-id="' . self::COMPONENT_ID . '"'
            . ' x-data="desktopScreens()" @click="onClick($event)" @keydown="onKey($event)" hidden>'
            . '<p class="milpa-screens__intro">' . $this->tr('screens.intro') . '</p>'
            . '<div class="mui-cluster mui-cluster--sm milpa-screens__bar">'
            . '<input class="mui-input mui-input--sm milpa-screens__name" id="milpa-preview-name" placeholder="' . $this->tr('screens.name') . '">'
            . '<button type="button" class="mui-btn mui-btn--primary mui-btn--sm" id="milpa-preview-go" data-live-route="' . $route . '">' . $this->tr('screens.preview') . '</button>'
            . '<span id="milpa-screens">' . (new ScreenPreviewView())->html($screens) . '</span>'
            . '</div>'
            . '<iframe id="milpa-preview-frame" class="milpa-screens__frame" title="' . $this->tr('screens.frame') . '"></iframe>'
            . '</div>';
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

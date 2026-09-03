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

use Milpa\Interfaces\Event\MilpaEventDispatcherInterface;
use Milpa\Live\Security\HmacStateSigner;
use Milpa\Live\Security\SignedXhtmlStateTransferCodec;
use Milpa\Live\Transport\XhtmlStateTransferCodec;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\StateSnapshot;

/**
 * Renders the Desktop shell's Context tab as a {@see ContextComponent} — the sixth surface of the "shell is
 * pure Milpa Components" migration (greenhouse decisions/0189). It mounts the component, produces the
 * design-system panel grid from the panels other plugins contributed, carries the signed state envelope, and
 * emits `desktop.context.before_render` / `after_render` so other plugins can extend it.
 *
 * The panels come from the {@see \Milpa\DesktopApp\ShellComposition} (via `addPanel`), assembled per request;
 * this component is their container, unchanged in how a plugin contributes.
 */
final class Context
{
    public const string COMPONENT_ID = 'context';
    public const string BEFORE_RENDER = 'desktop.context.before_render';
    public const string AFTER_RENDER = 'desktop.context.after_render';

    private readonly SignedXhtmlStateTransferCodec $codec;

    public function __construct(
        string $signingSecret,
        private readonly ?MilpaEventDispatcherInterface $events = null,
    ) {
        $this->codec = new SignedXhtmlStateTransferCodec(new XhtmlStateTransferCodec(), new HmacStateSigner($signingSecret), null);
    }

    /**
     * The Context tab's server-rendered HTML — the panel grid as a component, or the empty state.
     *
     * @param list<array{id: string, title: string|null, html: string}> $panels
     */
    public function render(array $panels): string
    {
        $component = new ContextComponent();
        $subject = new ComposerRender(['panels' => $panels]);
        $this->events?->dispatch(self::BEFORE_RENDER, ['context' => $subject]);

        $context = new ComponentContext(componentId: self::COMPONENT_ID);
        $state = $component->mount($subject->props, $context);
        $subject->html = $this->markup($subject->props) . $this->envelope($state);

        $this->events?->dispatch(self::AFTER_RENDER, ['context' => $subject]);

        return $subject->html;
    }

    /** @param array<string, mixed> $props */
    private function markup(array $props): string
    {
        /** @var list<array{id: string, title: string|null, html: string}> $panels */
        $panels = \is_array($props['panels'] ?? null) ? $props['panels'] : [];
        $wrap = 'data-milpa-component="desktop-context" data-milpa-component-id="' . self::COMPONENT_ID . '"';

        $body = '';
        foreach ($panels as $panel) {
            $header = $panel['title'] !== null
                ? '<div class="mui-card__header"><h2 class="mui-card__title">' . htmlspecialchars($panel['title'], ENT_QUOTES) . '</h2></div>'
                : '';
            $body .= sprintf(
                '<section class="mui-card" data-panel="%1$s" data-plugin="%1$s">%2$s<div class="mui-card__body" data-panel-body>%3$s</div></section>',
                htmlspecialchars($panel['id'], ENT_QUOTES),
                $header,
                $panel['html'],
            );
        }
        if ($body === '') {
            $body = '<p class="mui-empty">No plugin has contributed a panel yet. A plugin adds one with '
                . '<code>ShellComposition::addPanel()</code>.</p>';
        }

        return '<div class="panel-grid" ' . $wrap . '>' . $body . '</div>';
    }

    private function envelope(StateSnapshot $state): string
    {
        return '<script type="application/milpa+xhtml" data-milpa-state="' . self::COMPONENT_ID . '">' . $this->codec->encodeState($state) . '</script>';
    }
}

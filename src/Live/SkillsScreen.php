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
 * Renders the {@see SkillsScreenComponent} — the Skills screen (greenhouse decisions/0211, phase D5).
 *
 * The two lists stay the pure {@see SkillsView} and {@see RolesView} (tested with fixtures); this renderer
 * wraps them with the screen's two ledes, the roles heading, the signed envelope and its lifecycle events
 * — and, most of the point of the phase, it OWNS `desktop-skills.css`, where the card styling the shell's
 * inline `<style>` used to carry now lives.
 */
final class SkillsScreen
{
    public const string COMPONENT_ID = 'skills';

    /** Dispatched with a mutable {@see ComposerRender} BEFORE the render — a subscriber may change its props. */
    public const string BEFORE_RENDER = 'desktop.skills.before_render';

    /** Dispatched with a mutable {@see ComposerRender} AFTER the render — a subscriber may change its html. */
    public const string AFTER_RENDER = 'desktop.skills.after_render';

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
            'skills' => $this->data?->skills() ?? [],
            'roles' => $this->data?->roles() ?? [],
        ]);
        $this->events?->dispatch(self::BEFORE_RENDER, ['skills' => $subject]);

        $state = (new SkillsScreenComponent())->mount($subject->props, new ComponentContext(componentId: self::COMPONENT_ID));
        $subject->html = $this->markup($subject->props) . $this->envelope($state);

        $this->events?->dispatch(self::AFTER_RENDER, ['skills' => $subject]);

        return $subject->html;
    }

    /** @param array<string, mixed> $props */
    private function markup(array $props): string
    {
        /** @var list<array{name: string, description: string, model_invocable: bool, user_invocable: bool}> $skills */
        $skills = \is_array($props['skills'] ?? null) ? $props['skills'] : [];
        /** @var list<array{name: string, produces: string, deny: list<string>, skills: list<string>}> $roles */
        $roles = \is_array($props['roles'] ?? null) ? $props['roles'] : [];

        return '<div class="view milpa-skills" data-view="skills"'
            . ' data-milpa-component="desktop-skills" data-milpa-component-id="' . self::COMPONENT_ID . '" hidden>'
            . '<p class="milpa-skills__intro">' . $this->tr('skills.intro') . '</p>'
            . '<div id="milpa-skills">' . (new SkillsView())->html($skills) . '</div>'
            . '<p class="milpa-skills__head">' . $this->tr('skills.roles') . '</p>'
            . '<p class="milpa-skills__intro">' . $this->tr('skills.roles_intro') . '</p>'
            . '<div id="milpa-roles">' . (new RolesView())->html($roles) . '</div>'
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

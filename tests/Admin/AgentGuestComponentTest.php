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

namespace Milpa\DesktopApp\Tests\Admin;

use Milpa\DesktopApp\Admin\AgentGuestComponent;
use Milpa\DesktopApp\DesktopSettings;
use Milpa\Live\Contracts\Component\ComponentDefinitionInterface;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\InteractionRequest;
use PHPUnit\Framework\TestCase;

/**
 * The Agent section's component (greenhouse decisions/0210): a real Milpa Component with a contract, whose one
 * decision is whether the region may show the Agent — `live`, or `signed-out` behind the passkey gate with nobody
 * authenticated in the context.
 */
final class AgentGuestComponentTest extends TestCase
{
    public function testTheContractDeclaresTheDesktopsPathsAndGateAsStringProps(): void
    {
        $contract = AgentGuestComponent::contract();

        self::assertInstanceOf(ComponentDefinitionInterface::class, new AgentGuestComponent());
        self::assertSame('desktop-agent', $contract->name);
        self::assertSame('1', $contract->contractVersion);
        self::assertSame(['embed', 'open', 'gate', 'signin', 'query'], array_keys($contract->propsSchema));
        foreach (['embed', 'open', 'gate', 'signin'] as $name) {
            self::assertSame('string', $contract->propsSchema[$name]['type'], $name . ' is a string prop');
        }
        // The host's reserved prop (milpa/admin hands every section the request's query), declared as DevTools does.
        self::assertSame(['type' => 'array', 'default' => []], $contract->propsSchema['query']);
        self::assertSame('/desktop?embed=1', $contract->propsSchema['embed']['default']);
        self::assertSame('/webauthn/signin', $contract->propsSchema['signin']['default']);
        self::assertSame(['live', 'signed-out'], $contract->stateSchema['state']['enum']);
        self::assertSame([], $contract->actions, 'no action: the Desktop inside the frame acts');
    }

    public function testItMountsLiveUnlessThePasskeyGateStandsAndNobodyIsSignedIn(): void
    {
        $component = new AgentGuestComponent();
        $props = ['embed' => '/desktop?embed=1', 'open' => '/desktop', 'signin' => '/webauthn/signin'];

        $loopback = $component->mount($props + ['gate' => 'loopback'], new ComponentContext('milpa-admin-section-agent', locale: 'en', route: '/milpa/admin'));
        self::assertSame('milpa-admin-section-agent', $loopback->componentId);
        self::assertSame('desktop-agent', $loopback->componentName);
        self::assertSame('1', $loopback->version);
        self::assertSame(['state' => 'live'], $loopback->data, 'a gate that authenticates nobody has no sign-in to offer: live');
        self::assertSame('/desktop?embed=1', $loopback->meta['embed']);
        self::assertSame('/desktop', $loopback->meta['open']);
        self::assertSame('loopback', $loopback->meta['gate']);
        self::assertSame('/webauthn/signin', $loopback->meta['signin']);
        self::assertSame('/milpa/admin/s/agent', $loopback->meta['next'], 'the way back after sign-in: this section, under the mount point the context carries');
        self::assertSame('en', $loopback->meta['locale']);

        $signedOut = $component->mount($props + ['gate' => DesktopSettings::GATE_PASSKEY], new ComponentContext('c', principal: null, route: '/panel/'));
        self::assertSame(['state' => 'signed-out'], $signedOut->data, 'passkey gate + nobody in the context: the frame would only bounce to sign-in');
        self::assertSame('/panel/s/agent', $signedOut->meta['next'], 'a panel mounted elsewhere: next follows it');

        $signedIn = $component->mount($props + ['gate' => DesktopSettings::GATE_PASSKEY], new ComponentContext('c', principal: 'passkey:rod'));
        self::assertSame(['state' => 'live'], $signedIn->data, 'passkey gate + a principal: live');
        self::assertSame('/milpa/admin/s/agent', $signedIn->meta['next'], 'no route in the context: milpa/admin\'s default mount point');

        $custom = $component->mount($props + ['gate' => 'custom'], new ComponentContext('c', principal: null));
        self::assertSame(['state' => 'live'], $custom->data, 'only the passkey gate has a sign-in door the region can offer');
    }

    public function testTheWayBackKeepsTheLanguageTheRequestCarried(): void
    {
        // The host hands the request's query as `props['query']`: a `?lang=es` page returns to a `?lang=es` page.
        $component = new AgentGuestComponent();
        $context = new ComponentContext('c', route: '/milpa/admin');

        self::assertSame('/milpa/admin/s/agent?lang=es', $component->mount(['query' => ['lang' => 'es']], $context)->meta['next']);
        self::assertSame('/milpa/admin/s/agent?lang=a%2Fb', $component->mount(['query' => ['lang' => 'a/b']], $context)->meta['next'], 'encoded');
        // Only `lang` travels: the rest of the query is the section's own business, and nothing is invented.
        self::assertSame('/milpa/admin/s/agent', $component->mount(['query' => ['session' => 'x']], $context)->meta['next']);
        self::assertSame('/milpa/admin/s/agent', $component->mount(['query' => ['lang' => '']], $context)->meta['next']);
        self::assertSame('/milpa/admin/s/agent', $component->mount(['query' => ['lang' => ['es']]], $context)->meta['next']);
        self::assertSame('/milpa/admin/s/agent', $component->mount(['query' => 'lang=es'], $context)->meta['next'], 'a malformed query is none');
    }

    public function testMissingOrMalformedPropsFallToTheDefaults(): void
    {
        $state = (new AgentGuestComponent())->mount(['embed' => '', 'open' => 42, 'gate' => null], new ComponentContext('c'));

        self::assertSame('/desktop?embed=1', $state->meta['embed']);
        self::assertSame('/desktop', $state->meta['open']);
        self::assertSame('loopback', $state->meta['gate']);
        self::assertSame('/webauthn/signin', $state->meta['signin']);
        self::assertSame(['state' => 'live'], $state->data);
        self::assertNull($state->meta['locale']);
    }

    public function testTheSectionPathFollowsTheMountPoint(): void
    {
        self::assertSame('/milpa/admin/s/agent', AgentGuestComponent::sectionPath(null));
        self::assertSame('/milpa/admin/s/agent', AgentGuestComponent::sectionPath('/'));
        self::assertSame('/milpa/admin/s/agent', AgentGuestComponent::sectionPath(''));
        self::assertSame('/panel/s/agent', AgentGuestComponent::sectionPath('panel/'));
    }

    public function testItDeclaresNoActionAndSaysSoInsteadOfThrowing(): void
    {
        $component = new AgentGuestComponent();
        $state = $component->mount([], new ComponentContext('c'));

        $result = $component->handle(new InteractionRequest('c', 'desktop-agent', 'open', $state, ['x' => 1]));

        self::assertSame($state, $result->state, 'the state is returned unchanged, never omitted');
        self::assertArrayHasKey('action', $result->errors);
        self::assertStringContainsString('declares no actions', $result->errors['action']);
    }
}

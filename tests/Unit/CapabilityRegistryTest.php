<?php

declare(strict_types=1);

use Capell\AgentBridge\Data\CapabilityData;
use Capell\AgentBridge\Enums\CapabilityRiskEnum;
use Capell\AgentBridge\Enums\CapabilityServerEnum;
use Capell\AgentBridge\Support\CapellAgentBridgeCapabilityRegistry;
use Capell\AgentBridge\Tests\Fixtures\FakeCapabilityAction;

it('filters capabilities by server and client scopes', function (): void {
    $registry = new CapellAgentBridgeCapabilityRegistry;

    $registry->register(new CapabilityData(
        key: 'capell.fake.read',
        name: 'Fake read',
        description: 'Fake read capability.',
        scope: 'capell.fake.read',
        server: CapabilityServerEnum::Site,
        risk: CapabilityRiskEnum::Read,
        actionClass: FakeCapabilityAction::class,
    ));

    $registry->register(new CapabilityData(
        key: 'capell.fake.write',
        name: 'Fake write',
        description: 'Fake write capability.',
        scope: 'capell.fake.write',
        server: CapabilityServerEnum::Site,
        risk: CapabilityRiskEnum::High,
        actionClass: FakeCapabilityAction::class,
    ));

    $visible = $registry->visibleFor(CapabilityServerEnum::Site, ['capell.fake.read']);
    $capability = $visible->first();

    throw_unless($capability instanceof CapabilityData, RuntimeException::class, 'Expected visible capability to resolve.');

    expect($visible)->toHaveCount(1)
        ->and($capability->key)->toBe('capell.fake.read');
});

it('prevents duplicate capability keys', function (): void {
    $registry = new CapellAgentBridgeCapabilityRegistry;
    $capability = new CapabilityData(
        key: 'capell.fake.read',
        name: 'Fake read',
        description: 'Fake read capability.',
        scope: 'capell.fake.read',
        server: CapabilityServerEnum::Site,
        risk: CapabilityRiskEnum::Read,
        actionClass: FakeCapabilityAction::class,
    );

    $registry->register($capability);

    expect(fn (): null => $registry->register($capability))
        ->toThrow(InvalidArgumentException::class);
});

it('hides required package capabilities when Capell core is unavailable', function (): void {
    $registry = new CapellAgentBridgeCapabilityRegistry;

    $registry->register(new CapabilityData(
        key: 'capell.fake.package',
        name: 'Fake package',
        description: 'Fake package capability.',
        scope: 'capell.fake.read',
        server: CapabilityServerEnum::Site,
        risk: CapabilityRiskEnum::Read,
        actionClass: FakeCapabilityAction::class,
        requiredPackage: 'capell-app/unknown-package',
    ));

    expect($registry->visibleFor(CapabilityServerEnum::Site, ['capell.fake.read']))->toHaveCount(0);
});

it('includes intent discovery metadata on the authenticated capability payload', function (): void {
    $capability = new CapabilityData(
        key: 'capell.pages.clone',
        name: 'Clone page',
        description: 'Create a safe disabled copy of an existing page.',
        scope: 'capell.pages.write',
        server: CapabilityServerEnum::Site,
        risk: CapabilityRiskEnum::High,
        actionClass: FakeCapabilityAction::class,
        category: 'pages',
        intents: ['clone a page', 'copy an existing page'],
        examples: ['Clone the About page as About our team.'],
    );

    expect($capability->toPayload())
        ->toMatchArray([
            'category' => 'pages',
            'intents' => ['clone a page', 'copy an existing page'],
            'examples' => ['Clone the About page as About our team.'],
        ])
        ->and($capability->publicPayload())
        ->not->toHaveKeys(['category', 'intents', 'examples']);
});

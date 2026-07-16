<?php

declare(strict_types=1);

use Capell\AgentBridge\Actions\SuggestAgentBridgeCapabilitiesAction;
use Capell\AgentBridge\Data\CapabilityData;
use Capell\AgentBridge\Enums\CapabilityRiskEnum;
use Capell\AgentBridge\Enums\CapabilityServerEnum;
use Capell\AgentBridge\Support\CapellAgentBridgeCapabilityRegistry;
use Capell\AgentBridge\Tests\Fixtures\FakeCapabilityAction;

it('ranks capabilities from natural language intent and keeps discovery deterministic', function (): void {
    $registry = new CapellAgentBridgeCapabilityRegistry;
    $registry->register(new CapabilityData(
        key: 'capell.pages.clone',
        name: 'Clone page',
        description: 'Create a disabled copy of an existing page for review.',
        scope: 'capell.pages.write',
        server: CapabilityServerEnum::Site,
        risk: CapabilityRiskEnum::High,
        actionClass: FakeCapabilityAction::class,
        category: 'pages',
        intents: ['clone a page', 'copy an existing page', 'duplicate content'],
        examples: ['Make a safe copy of the About page called About our team.'],
    ));
    $registry->register(new CapabilityData(
        key: 'capell.cache.clear',
        name: 'Clear caches',
        description: 'Clear Capell caches.',
        scope: 'capell.cache.run',
        server: CapabilityServerEnum::Site,
        risk: CapabilityRiskEnum::Medium,
        actionClass: FakeCapabilityAction::class,
        category: 'system',
        intents: ['clear cache'],
    ));

    app()->instance(CapellAgentBridgeCapabilityRegistry::class, $registry);

    $first = SuggestAgentBridgeCapabilitiesAction::run(
        query: 'Please make a safe copy of the About page',
        server: CapabilityServerEnum::Site,
        scopes: ['*'],
    );
    $second = SuggestAgentBridgeCapabilitiesAction::run(
        query: 'Please make a safe copy of the About page',
        server: CapabilityServerEnum::Site,
        scopes: ['*'],
    );

    expect($first)->toBe($second)
        ->and($first['suggestions'][0]['key'])->toBe('capell.pages.clone')
        ->and($first['suggestions'][0]['score'])->toBeGreaterThan(0)
        ->and($first['suggestions'][0]['matchedTerms'])->toContain('copy')
        ->and($first['suggestions'][0]['category'])->toBe('pages')
        ->and($first['suggestions'][0]['examples'])->toBe(['Make a safe copy of the About page called About our team.']);
});

it('never suggests capabilities outside the authenticated client scopes', function (): void {
    $registry = new CapellAgentBridgeCapabilityRegistry;
    $registry->register(new CapabilityData(
        key: 'capell.pages.clone',
        name: 'Clone page',
        description: 'Clone a page.',
        scope: 'capell.pages.write',
        server: CapabilityServerEnum::Site,
        risk: CapabilityRiskEnum::High,
        actionClass: FakeCapabilityAction::class,
        intents: ['clone page'],
    ));
    $registry->register(new CapabilityData(
        key: 'capell.pages.search',
        name: 'Search pages',
        description: 'Find a page by name or URL.',
        scope: 'capell.pages.read',
        server: CapabilityServerEnum::Site,
        risk: CapabilityRiskEnum::Read,
        actionClass: FakeCapabilityAction::class,
        intents: ['find page', 'open page'],
    ));

    app()->instance(CapellAgentBridgeCapabilityRegistry::class, $registry);

    $result = SuggestAgentBridgeCapabilitiesAction::run(
        query: 'find or clone a page',
        server: CapabilityServerEnum::Site,
        scopes: ['capell.pages.read'],
    );

    expect(array_column($result['suggestions'], 'key'))
        ->toBe(['capell.pages.search']);
});

it('returns useful popular actions for empty autocomplete queries', function (): void {
    $registry = new CapellAgentBridgeCapabilityRegistry;
    $registry->register(new CapabilityData(
        key: 'capell.pages.search',
        name: 'Search pages',
        description: 'Find pages.',
        scope: 'capell.pages.read',
        server: CapabilityServerEnum::Site,
        risk: CapabilityRiskEnum::Read,
        actionClass: FakeCapabilityAction::class,
        discoveryPriority: 90,
    ));
    $registry->register(new CapabilityData(
        key: 'capell.cache.clear',
        name: 'Clear caches',
        description: 'Clear caches.',
        scope: 'capell.cache.run',
        server: CapabilityServerEnum::Site,
        risk: CapabilityRiskEnum::Medium,
        actionClass: FakeCapabilityAction::class,
        discoveryPriority: 10,
    ));

    app()->instance(CapellAgentBridgeCapabilityRegistry::class, $registry);

    $result = SuggestAgentBridgeCapabilitiesAction::run(
        query: '',
        server: CapabilityServerEnum::Site,
        scopes: ['*'],
        limit: 1,
    );

    expect($result['suggestions'])
        ->toHaveCount(1)
        ->and($result['suggestions'][0]['key'])->toBe('capell.pages.search');
});

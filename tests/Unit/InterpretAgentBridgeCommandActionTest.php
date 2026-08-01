<?php

declare(strict_types=1);

use Capell\AgentBridge\Actions\InterpretAgentBridgeCommandAction;
use Capell\AgentBridge\Data\CapabilityCommandPatternData;
use Capell\AgentBridge\Data\CapabilityData;
use Capell\AgentBridge\Enums\CapabilityRiskEnum;
use Capell\AgentBridge\Enums\CapabilityServerEnum;
use Capell\AgentBridge\Enums\CommandInterpretationOutcomeEnum;
use Capell\AgentBridge\Support\CapellAgentBridgeCapabilityRegistry;
use Capell\AgentBridge\Tests\Fixtures\FakeCapabilityAction;

it('turns common voice and text requests into exact capability plans', function (
    string $command,
    string $capabilityKey,
    array $payload,
): void {
    $plan = InterpretAgentBridgeCommandAction::run($command);

    expect($plan->outcome)->toBe(CommandInterpretationOutcomeEnum::Exact)
        ->and($plan->matched)->toBeTrue()
        ->and($plan->capabilityKey)->toBe($capabilityKey)
        ->and($plan->payload)->toBe($payload)
        ->and($plan->confidence)->toBe(1.0);
})->with([
    'open a page by title' => [
        'Open the About page',
        'capell.pages.open_editor',
        ['page' => 'About'],
    ],
    'create a draft page' => [
        'Create a draft page called Summer Campaign',
        'capell.pages.create_draft',
        ['name' => 'Summer Campaign', 'title' => 'Summer Campaign'],
    ],
    'clone an internal page' => [
        'Clone the Services page as Consulting',
        'capell.pages.clone',
        ['page' => 'Services', 'name' => 'Consulting', 'title' => 'Consulting'],
    ],
    'rename a page' => [
        'Update the About page title to About Capell',
        'capell.pages.update_draft',
        ['page' => 'About', 'title' => 'About Capell'],
    ],
    'change a page slug' => [
        'Change the About page slug to about-capell',
        'capell.pages.update_draft',
        ['page' => 'About', 'slug' => 'about-capell'],
    ],
    'publish a page' => [
        'Publish the Launch page now',
        'capell.pages.publish_now',
        ['page' => 'Launch'],
    ],
    'schedule publication' => [
        'Schedule the Launch page to publish at 2026-08-01 09:00',
        'capell.pages.schedule_publish',
        ['page' => 'Launch', 'publish_at' => '2026-08-01 09:00'],
    ],
    'schedule unpublication' => [
        'Schedule the Launch page to unpublish at 2026-09-01 09:00',
        'capell.pages.schedule_unpublish',
        ['page' => 'Launch', 'unpublish_at' => '2026-09-01 09:00'],
    ],
    'revert a page to draft' => [
        'Revert the Launch page back to draft',
        'capell.pages.revert_to_draft',
        ['page' => 'Launch'],
    ],
    'check publishing readiness' => [
        'Check the Launch page publishing readiness',
        'capell.pages.inspect_readiness',
        ['page' => 'Launch'],
    ],
    'inspect a page' => [
        'Inspect the About page',
        'capell.pages.inspect',
        ['page' => 'About'],
    ],
    'search pages' => [
        'Find the pricing page',
        'capell.pages.search',
        ['query' => 'pricing'],
    ],
    'open a site record by name' => [
        'Edit the Main website site record',
        'capell.sites.open_editor',
        ['site' => 'Main website'],
    ],
    'inspect a site' => [
        'Inspect the site Main website',
        'capell.sites.inspect',
        ['site' => 'Main website'],
    ],
    'search sites' => [
        'List marketing sites',
        'capell.sites.search',
        ['query' => 'marketing'],
    ],
    'install a package' => [
        'Install the package SEO Suite',
        'capell.packages.install',
        ['package' => 'SEO Suite'],
    ],
    'inspect a package' => [
        'Inspect the package SEO Suite',
        'capell.packages.inspect',
        ['package' => 'SEO Suite'],
    ],
    'search packages' => [
        'Find analytics packages',
        'capell.packages.search',
        ['query' => 'analytics'],
    ],
    'clear caches' => [
        'Refresh the Capell cache',
        'capell.cache.clear',
        [],
    ],
]);

it('reports an unmatched outcome with no suggestions for a blank command', function (): void {
    $plan = InterpretAgentBridgeCommandAction::run('   ');

    expect($plan->outcome)->toBe(CommandInterpretationOutcomeEnum::Unmatched)
        ->and($plan->matched)->toBeFalse()
        ->and($plan->capabilityKey)->toBeNull()
        ->and($plan->suggestions)->toBe([])
        ->and($plan->confidence)->toBe(0.0);
});

it('offers a single clear leader as a suggestion without selecting it', function (): void {
    registerInterpretationCapabilities([
        fakeCapability('capell.demo.clone_page', 'Clone page', intents: ['clone a page', 'duplicate an existing page']),
        fakeCapability('capell.demo.clear_cache', 'Clear caches', intents: ['clear cache']),
    ]);

    $plan = InterpretAgentBridgeCommandAction::run('please duplicate an existing page for me');

    expect($plan->outcome)->toBe(CommandInterpretationOutcomeEnum::Suggested)
        ->and($plan->matched)->toBeFalse()
        ->and($plan->capabilityKey)->toBeNull()
        ->and($plan->payload)->toBe([])
        ->and($plan->suggestions[0]['key'])->toBe('capell.demo.clone_page')
        ->and($plan->confidence)->toBeGreaterThan(0.0)
        ->and($plan->confidence)->toBeLessThanOrEqual(0.95)
        ->and($plan->matchedTerms)->toContain('duplicate');
});

it('stays ambiguous when two capabilities rank too closely to separate', function (): void {
    registerInterpretationCapabilities([
        fakeCapability('capell.demo.alpha', 'Alpha', intents: ['publish page']),
        fakeCapability('capell.demo.beta', 'Beta', intents: ['publish page']),
    ]);

    $plan = InterpretAgentBridgeCommandAction::run('publish page');

    expect($plan->outcome)->toBe(CommandInterpretationOutcomeEnum::Ambiguous)
        ->and($plan->capabilityKey)->toBeNull()
        ->and($plan->confidence)->toBe(0.0)
        ->and(array_column($plan->suggestions, 'key'))
        ->toEqualCanonicalizing(['capell.demo.alpha', 'capell.demo.beta']);
});

it('stays unmatched when the ranking evidence is a weak single-word collision', function (): void {
    registerInterpretationCapabilities([
        fakeCapability('capell.demo.clone_page', 'Clone page', intents: ['clone a page']),
    ]);

    // Only "page" matches: one recognised term out of four meaningful ones.
    $plan = InterpretAgentBridgeCommandAction::run('Help me improve the About page');

    expect($plan->outcome)->toBe(CommandInterpretationOutcomeEnum::Unmatched)
        ->and($plan->suggestions)->toBe([])
        ->and($plan->confidence)->toBe(0.0);
});

it('interprets a contributor-defined pattern from an extension capability', function (): void {
    registerInterpretationCapabilities([
        new CapabilityData(
            key: 'vendor.newsletter.schedule',
            name: 'Schedule newsletter',
            description: 'Queue a newsletter for a future send.',
            scope: 'vendor.newsletter.write',
            server: CapabilityServerEnum::Site,
            risk: CapabilityRiskEnum::High,
            actionClass: FakeCapabilityAction::class,
            inputSchema: [
                'type' => 'object',
                'required' => ['newsletter', 'send_at'],
                'properties' => [
                    'newsletter' => ['type' => 'string'],
                    'send_at' => ['type' => 'string'],
                ],
                'additionalProperties' => false,
            ],
            commandPatterns: [
                new CapabilityCommandPatternData(
                    expression: '/^send\s+(?:the\s+)?(?<newsletter>.+?)\s+newsletter\s+(?:at|on)\s+(?<send_at>.+)$/i',
                    phrase: 'Send the {newsletter} newsletter at {send_at}',
                    fields: ['newsletter' => ['newsletter'], 'send_at' => ['send_at']],
                    required: ['newsletter', 'send_at'],
                ),
            ],
        ),
    ]);

    $plan = InterpretAgentBridgeCommandAction::run('Send the August Digest newsletter at 2026-08-05 08:00');

    expect($plan->outcome)->toBe(CommandInterpretationOutcomeEnum::Exact)
        ->and($plan->capabilityKey)->toBe('vendor.newsletter.schedule')
        ->and($plan->payload)->toBe([
            'newsletter' => 'August Digest',
            'send_at' => '2026-08-05 08:00',
        ]);
});

it('never interprets a command into a capability outside the client scopes', function (): void {
    registerInterpretationCapabilities([
        fakeCapability('capell.demo.clear_cache', 'Clear caches', scope: 'capell.cache.run', patterns: [
            new CapabilityCommandPatternData(
                expression: '/^clear\s+(?:the\s+)?cache$/i',
                phrase: 'Clear the cache',
            ),
        ]),
    ]);

    $plan = InterpretAgentBridgeCommandAction::run(
        command: 'clear the cache',
        server: CapabilityServerEnum::Site,
        scopes: ['capell.pages.read'],
    );

    expect($plan->outcome)->toBe(CommandInterpretationOutcomeEnum::Unmatched)
        ->and($plan->capabilityKey)->toBeNull();
});

/**
 * A pattern may match while still failing to yield a value it guarantees. That
 * is a refusal to guess, not a partial match.
 */
it('returns ambiguous rather than a partial exact match when a guaranteed value is empty', function (): void {
    registerInterpretationCapabilities([
        fakeCapability('capell.demo.open_page', 'Open page', patterns: [
            new CapabilityCommandPatternData(
                // The quoted target may legitimately capture nothing.
                expression: '/^open\s+"(?<page>[^"]*)"$/i',
                phrase: 'Open "{page}"',
                fields: ['page' => ['page']],
                required: ['page'],
            ),
        ]),
    ]);

    $plan = InterpretAgentBridgeCommandAction::run('open ""');

    expect($plan->outcome)->toBe(CommandInterpretationOutcomeEnum::Ambiguous)
        ->and($plan->capabilityKey)->toBeNull()
        ->and($plan->payload)->toBe([])
        ->and($plan->missingFields)->toBe(['page'])
        ->and($plan->suggestions[0]['key'])->toBe('capell.demo.open_page');
});

/** @param list<CapabilityData> $capabilities */
function registerInterpretationCapabilities(array $capabilities): void
{
    $registry = new CapellAgentBridgeCapabilityRegistry;

    foreach ($capabilities as $capability) {
        $registry->register($capability);
    }

    app()->instance(CapellAgentBridgeCapabilityRegistry::class, $registry);
}

/**
 * @param  list<string>  $intents
 * @param  list<CapabilityCommandPatternData>  $patterns
 */
function fakeCapability(
    string $key,
    string $name,
    array $intents = [],
    array $patterns = [],
    string $scope = 'capell.demo.read',
): CapabilityData {
    return new CapabilityData(
        key: $key,
        name: $name,
        description: $name . '.',
        scope: $scope,
        server: CapabilityServerEnum::Site,
        risk: CapabilityRiskEnum::Read,
        actionClass: FakeCapabilityAction::class,
        inputSchema: [
            'type' => 'object',
            'properties' => ['page' => ['type' => 'string']],
            'additionalProperties' => false,
        ],
        requiresConfirmation: false,
        category: 'general',
        intents: $intents,
        commandPatterns: $patterns,
    );
}

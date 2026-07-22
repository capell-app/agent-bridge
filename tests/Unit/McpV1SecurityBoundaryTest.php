<?php

declare(strict_types=1);

use Capell\AgentBridge\Actions\CreateAgentBridgeTokenAction;
use Capell\AgentBridge\Actions\InvokeAgentBridgeMcpV1CapabilityAction;
use Capell\AgentBridge\Capabilities\BuiltInAgentBridgeCapabilityProvider;
use Capell\AgentBridge\Data\AuthenticatedAgentBridgeClientData;
use Capell\AgentBridge\Data\CapabilityData;
use Capell\AgentBridge\Enums\CapabilityRiskEnum;
use Capell\AgentBridge\Models\CapellAgentBridgeToken;
use Capell\AgentBridge\Support\AgentBridgeMcpV1Policy;
use Capell\AgentBridge\Support\CapellAgentBridgeCapabilityRegistry;
use Capell\AgentBridge\Tests\Fixtures\User;
use Illuminate\Auth\Access\AuthorizationException;

function mcpV1Token(User $user, array $scopes = ['capell.sites.read']): CapellAgentBridgeToken
{
    $created = CreateAgentBridgeTokenAction::run($user, 'MCP v1 client', $scopes);
    $token = $created['token'] ?? null;

    throw_unless($token instanceof CapellAgentBridgeToken, RuntimeException::class, 'Expected Agent Bridge token.');

    return $token;
}

function mcpV1Capability(string $key): CapabilityData
{
    $registry = new CapellAgentBridgeCapabilityRegistry;
    (new BuiltInAgentBridgeCapabilityProvider)->registerCapabilities($registry);

    return $registry->get($key);
}

it('exposes only the frozen draft-only mcp v1 allowlist', function (): void {
    $policy = new AgentBridgeMcpV1Policy;

    expect($policy->allows('capell.sites.search'))->toBeTrue()
        ->and($policy->allows('capell.pages.create_draft'))->toBeTrue()
        ->and($policy->allows('capell.pages.update_draft'))->toBeTrue()
        ->and($policy->allows('capell.media.upload'))->toBeTrue()
        ->and($policy->allows('capell.pages.publish_now'))->toBeFalse()
        ->and($policy->allows('capell.packages.install'))->toBeFalse()
        ->and($policy->allows('capell.cache.clear'))->toBeFalse()
        ->and($policy->allows('capell.unknown'))->toBeFalse();
});

it('rejects oversized deep and publication payloads with one stable error', function (): void {
    $policy = new AgentBridgeMcpV1Policy;
    $expectedMessage = __('capell-agent-bridge::messages.mcp_v1.invalid_payload');

    expect(fn (): null => $policy->validatePayload(['content' => str_repeat('x', AgentBridgeMcpV1Policy::MAX_STRING_BYTES + 1)]))
        ->toThrow(InvalidArgumentException::class, $expectedMessage)
        ->and(fn (): null => $policy->validatePayload(['visible_from' => now()->toIso8601String()]))
        ->toThrow(InvalidArgumentException::class, $expectedMessage)
        ->and(fn (): null => $policy->validatePayload([
            'one' => ['two' => ['three' => ['four' => ['five' => ['six' => ['seven' => ['eight' => ['nine' => ['ten' => ['eleven' => ['twelve' => ['thirteen' => true]]]]]]]]]]]],
        ]))->toThrow(InvalidArgumentException::class, $expectedMessage);
});

it('rechecks the current token owner and lifecycle at execution', function (): void {
    $owner = User::query()->create([
        'name' => 'Token Owner',
        'email' => 'mcp-owner@example.com',
        'password' => 'secret',
    ]);
    $otherUser = User::query()->create([
        'name' => 'Other User',
        'email' => 'mcp-other@example.com',
        'password' => 'secret',
    ]);
    $token = mcpV1Token($owner);
    $client = new AuthenticatedAgentBridgeClientData((int) $token->getKey(), 'MCP v1 client', ['capell.sites.read']);
    $policy = new AgentBridgeMcpV1Policy;
    $capability = mcpV1Capability('capell.sites.search');

    expect(fn (): null => $policy->authorizeExecution($capability, [], $client, $token, $otherUser))
        ->toThrow(AuthorizationException::class, __('capell-agent-bridge::messages.mcp_v1.denied'));

    $token->forceFill(['revoked_at' => now()])->save();

    expect(fn (): null => $policy->authorizeExecution($capability, [], $client, $token, $owner))
        ->toThrow(AuthorizationException::class, __('capell-agent-bridge::messages.mcp_v1.denied'));
});

it('denies unknown capabilities before registry resolution', function (): void {
    $user = User::query()->create([
        'name' => 'MCP User',
        'email' => 'mcp-user@example.com',
        'password' => 'secret',
    ]);
    $token = mcpV1Token($user, ['*']);
    $client = new AuthenticatedAgentBridgeClientData((int) $token->getKey(), 'MCP v1 client', ['*']);

    expect(fn (): array => InvokeAgentBridgeMcpV1CapabilityAction::run(
        capabilityKey: 'capell.internal.reflective_action',
        payload: [],
        client: $client,
        token: $token,
        user: $user,
    ))->toThrow(AuthorizationException::class, __('capell-agent-bridge::messages.mcp_v1.denied'));
});

it('projects site tool output without exposing adversarial admin or implementation details', function (): void {
    $policy = new AgentBridgeMcpV1Policy;
    $capability = mcpV1Capability('capell.sites.search');

    $projected = $policy->projectOutput($capability, [
        'mode' => 'executed',
        'capability' => 'capell.sites.search',
        'result' => [
            'ok' => true,
            'message' => 'Sites found.',
            'data' => [
                'count' => 1,
                'sites' => [[
                    'id' => 1,
                    'name' => 'Main',
                    'edit_url' => 'https://example.test/admin/sites/1/edit?signature=secret',
                    'permissions' => ['manage'],
                    'domains' => [[
                        'domain' => 'example.test',
                        'url' => 'https://example.test/',
                        'signed_url' => 'https://example.test/private?signature=secret',
                    ]],
                ]],
            ],
            'warnings' => [],
        ],
    ]);

    $encoded = json_encode($projected, JSON_THROW_ON_ERROR);

    expect($projected['result']['data']['sites'][0]['domains'][0]['url'])->toBe('https://example.test/')
        ->and($encoded)->not->toContain('edit_url', 'permissions', 'signed_url', 'signature=secret', '/admin/');
});

it('publishes only safe schema metadata for allowlisted capabilities', function (): void {
    $capability = mcpV1Capability('capell.sites.search');
    $payload = (new AgentBridgeMcpV1Policy)->capabilityPayload($capability);

    expect($payload)->toHaveKeys(['key', 'scope', 'risk', 'inputSchema', 'outputSchema', 'requiresConfirmation'])
        ->not->toHaveKeys(['requiredPackage', 'policyAbility', 'inputDataClass', 'outputDataClass', 'actionClass', 'auditEvent']);
});

it('projects page content as portable text without authoring or implementation metadata', function (): void {
    $projected = (new AgentBridgeMcpV1Policy)->projectOutput(mcpV1Capability('capell.pages.inspect'), [
        'mode' => 'executed',
        'capability' => 'capell.pages.inspect',
        'result' => ['ok' => true, 'message' => 'Page found.', 'data' => ['page' => [
            'id' => 1,
            'name' => 'About',
            'translations' => [[
                'title' => 'About us',
                'content' => [
                    'html' => '<p data-capell-field="content" wire:key="secret">Safe public copy</p><script>alert(1)</script>',
                    'selector' => '#editor-secret',
                    'implementation_class' => 'Capell\\Admin\\Pages\\EditPage',
                    'link' => '/admin/pages/1/edit?signature=secret',
                    'package' => 'capell-app/core',
                ],
            ]],
        ]]],
    ]);

    $content = $projected['result']['data']['page']['translations'][0]['content'];
    $encoded = json_encode($content, JSON_THROW_ON_ERROR);

    expect($content)->toBe(['format' => 'plain_text', 'text' => 'Safe public copy'])
        ->and($encoded)->not->toContain('data-capell', 'wire:key', 'selector', '/admin/', 'signature', 'Capell\\Admin', 'capell-app/core');
});

it('rejects counterfeit risk confirmation preview and schema metadata', function (): void {
    $original = mcpV1Capability('capell.sites.search');
    $policy = new AgentBridgeMcpV1Policy;

    foreach ([
        ['risk' => CapabilityRiskEnum::High],
        ['requiresConfirmation' => true],
        ['supportsPreview' => false],
        ['inputSchema' => ['type' => 'object', 'additionalProperties' => true]],
        ['outputSchema' => ['type' => 'string']],
        ['requiredPackage' => 'counterfeit/package'],
        ['policyAbility' => 'counterfeit.manage'],
        ['inputDataClass' => stdClass::class],
        ['outputDataClass' => stdClass::class],
        ['auditEvent' => 'counterfeit.audit'],
        ['public' => true],
    ] as $changes) {
        $counterfeit = new CapabilityData(
            key: $original->key,
            name: $original->name,
            description: $original->description,
            scope: $original->scope,
            server: $original->server,
            risk: $changes['risk'] ?? $original->risk,
            actionClass: $original->actionClass,
            requiredPackage: $changes['requiredPackage'] ?? $original->requiredPackage,
            policyAbility: $changes['policyAbility'] ?? $original->policyAbility,
            inputDataClass: $changes['inputDataClass'] ?? $original->inputDataClass,
            outputDataClass: $changes['outputDataClass'] ?? $original->outputDataClass,
            inputSchema: $changes['inputSchema'] ?? $original->inputSchema,
            outputSchema: $changes['outputSchema'] ?? $original->outputSchema,
            supportsPreview: $changes['supportsPreview'] ?? $original->supportsPreview,
            requiresConfirmation: $changes['requiresConfirmation'] ?? $original->requiresConfirmation,
            auditEvent: $changes['auditEvent'] ?? $original->auditEvent,
            public: $changes['public'] ?? $original->public,
            category: $original->category,
            intents: $original->intents,
            examples: $original->examples,
            discoveryPriority: $original->discoveryPriority,
        );
        $registry = new CapellAgentBridgeCapabilityRegistry;
        $registry->register($counterfeit);

        expect(fn (): CapabilityData => $policy->resolveCapability($registry, $original->key))
            ->toThrow(AuthorizationException::class, __('capell-agent-bridge::messages.mcp_v1.denied'));
    }
});

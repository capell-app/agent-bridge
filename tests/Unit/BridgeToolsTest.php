<?php

declare(strict_types=1);

use Capell\AgentBridge\Actions\BuildAgentBridgeCapabilityCatalogAction;
use Capell\AgentBridge\Actions\InvokeAgentBridgeCapabilityPreviewAction;
use Capell\AgentBridge\Actions\Pages\CreateDraftPageCapabilityAction;
use Capell\AgentBridge\Capabilities\BuiltInAgentBridgeCapabilityProvider;
use Capell\AgentBridge\Data\AuthenticatedAgentBridgeClientData;
use Capell\AgentBridge\Data\Capabilities\CreateDraftPageCapabilityInputData;
use Capell\AgentBridge\Data\CapabilityData;
use Capell\AgentBridge\Data\CapabilityResultData;
use Capell\AgentBridge\Enums\CapabilityRiskEnum;
use Capell\AgentBridge\Enums\CapabilityServerEnum;
use Capell\AgentBridge\Facades\CapellAgentBridge;
use Capell\AgentBridge\Models\CapellAgentBridgeAuditEntry;
use Capell\AgentBridge\Models\CapellAgentBridgeToken;
use Capell\AgentBridge\Resources\CapellAgentBridgeCapabilityCatalogResource;
use Capell\AgentBridge\Resources\CapellAgentBridgeCapabilitySchemaResource;
use Capell\AgentBridge\Resources\CapellAgentBridgeOverviewResource;
use Capell\AgentBridge\Support\CapabilitySchemas;
use Capell\AgentBridge\Support\CapellAgentBridgeCapabilityRegistry;
use Capell\AgentBridge\Support\KnowledgeRepository;
use Capell\AgentBridge\Tests\Fixtures\FakeCapabilityAction;
use Capell\AgentBridge\Tests\Fixtures\User;
use Capell\AgentBridge\Tools\Boost\ListBoostCapabilitiesTool;
use Capell\AgentBridge\Tools\Boost\PreviewBoostCapabilityTool;
use Capell\AgentBridge\Tools\Knowledge\ListKnowledgePackagesTool;
use Capell\AgentBridge\Tools\Knowledge\ReadKnowledgeDocumentTool;
use Capell\AgentBridge\Tools\Site\InspectSiteStateTool;
use Capell\AgentBridge\Tools\Site\ListSiteCapabilitiesTool;
use Capell\AgentBridge\Tools\Site\QuerySiteAuditEntriesTool;
use Capell\AgentBridge\Tools\Site\RunSiteCapabilityTool;
use Capell\AgentBridge\Tools\Site\SuggestSiteCapabilitiesTool;
use Capell\Core\Facades\CapellCore;
use Composer\InstalledVersions;
use Laravel\Mcp\Request;
use Laravel\Mcp\ResponseFactory;

it('lists boost capabilities visible through the site server', function (): void {
    $registry = new CapellAgentBridgeCapabilityRegistry;
    $registry->register(new CapabilityData(
        key: 'capell.fake.preview',
        name: 'Fake preview',
        description: 'Preview fake capability.',
        scope: 'capell.fake.preview',
        server: CapabilityServerEnum::Site,
        risk: CapabilityRiskEnum::Read,
        actionClass: FakeCapabilityAction::class,
    ));

    $response = (new ListBoostCapabilitiesTool)->handle($registry);
    $structuredContent = agentBridgeStructuredContent($response);

    $capabilities = capabilityList($structuredContent);

    expect($structuredContent)
        ->toHaveKey('confirmation')
        ->and($capabilities[0]['key'])->toBe('capell.fake.preview');
});

it('previews a boost capability through the registry', function (): void {
    $registry = new CapellAgentBridgeCapabilityRegistry;
    $registry->register(new CapabilityData(
        key: 'capell.fake.preview',
        name: 'Fake preview',
        description: 'Preview fake capability.',
        scope: 'capell.fake.preview',
        server: CapabilityServerEnum::Site,
        risk: CapabilityRiskEnum::Read,
        actionClass: FakeCapabilityAction::class,
    ));

    $response = (new PreviewBoostCapabilityTool)->handle(
        new Request([
            'capability' => 'capell.fake.preview',
            'payload' => ['name' => 'Example'],
        ]),
        $registry,
    );
    $structuredContent = agentBridgeStructuredContent($response);
    $preview = $structuredContent['preview'] ?? null;

    throw_unless(is_array($preview), RuntimeException::class, 'Expected boost capability preview payload.');

    expect($structuredContent)
        ->toHaveKey('confirmation')
        ->and($structuredContent['mode'])->toBe('preview')
        ->and($structuredContent['capability'])->toBe('capell.fake.preview')
        ->and($preview['message'])->toBe('Previewed fake capability.');
});

it('lists knowledge packages as structured content', function (): void {
    app()->setBasePath(getcwd() ?: dirname(__DIR__, 4));

    $response = (new ListKnowledgePackagesTool)->handle(new KnowledgeRepository);

    $packages = agentBridgeStructuredContent($response)['packages'];
    $packageNames = is_array($packages) ? array_column($packages, 'name') : [];

    expect($packageNames)
        ->toContain('capell-app/agent-bridge');
});

it('reads allowed knowledge documents by repository path', function (): void {
    app()->setBasePath(getcwd() ?: dirname(__DIR__, 4));
    config()->set('capell-agent-bridge.agent_knowledge.published_paths', [
        base_path('packages/agent-bridge/docs'),
    ]);

    $response = (new ReadKnowledgeDocumentTool)->handle(
        new Request(['path' => 'packages/agent-bridge/docs/overview.md']),
        new KnowledgeRepository,
    );

    throw_unless($response instanceof ResponseFactory, RuntimeException::class, 'Expected a structured knowledge document.');
    $document = agentBridgeStructuredContent($response);

    expect($document['content'] ?? null)->toContain('Agent Bridge')
        ->and($document['provenance'] ?? null)->toBe(['source' => 'package-documentation', 'package' => 'agent-bridge'])
        ->and(data_get($document, 'contentTrust.untrustedFields'))->toBe(['content'])
        ->and(data_get($document, 'contentTrust.notice'))->toBeString()->not->toBeEmpty();
});

it('returns site state without leaking content bodies', function (): void {
    $response = (new InspectSiteStateTool)->handle();
    $structuredContent = agentBridgeStructuredContent($response);

    expect($structuredContent['app'])
        ->toHaveKey('name')
        ->not->toHaveKeys(['environment', 'debug'])
        ->and($structuredContent['counts'])
        ->toHaveKeys(['sites', 'languages', 'pages', 'pageUrls', 'blueprints', 'redirects', 'navigations']);
});

it('leaves a disabled extension out of the installed packages it reports', function (): void {
    $path = InstalledVersions::getInstallPath('capell-app/pest-plugin-blade-coverage');
    throw_unless(is_string($path), RuntimeException::class, 'Expected the blade coverage plugin to be installed through Composer.');

    CapellCore::registerPackage('capell-app/pest-plugin-blade-coverage', path: $path);
    CapellCore::forcePackageInstalled('capell-app/pest-plugin-blade-coverage');

    $enabled = agentBridgeStructuredContent((new InspectSiteStateTool)->handle())['packages'];

    CapellCore::forcePackageInstalled('capell-app/pest-plugin-blade-coverage', false);

    $disabled = agentBridgeStructuredContent((new InspectSiteStateTool)->handle())['packages'];

    expect($enabled)->toHaveKey('capell-app/pest-plugin-blade-coverage')
        ->and($disabled)->not->toHaveKey('capell-app/pest-plugin-blade-coverage')
        ->and($disabled)->toHaveKey('capell-app/core');
});

it('can opt into runtime state for trusted inspect calls', function (): void {
    config()->set('capell-agent-bridge.inspect_app_runtime', true);

    $response = (new InspectSiteStateTool)->handle();
    $structuredContent = agentBridgeStructuredContent($response);

    expect($structuredContent['app'])
        ->toHaveKeys(['name', 'environment', 'debug']);
});

it('lists site capabilities allowed by the authenticated client scopes', function (): void {
    CapellCore::shouldReceive('isPackageInstalled')->andReturnTrue();

    $registry = new CapellAgentBridgeCapabilityRegistry;
    (new BuiltInAgentBridgeCapabilityProvider)->registerCapabilities($registry);

    $response = (new ListSiteCapabilitiesTool)->handle(
        $registry,
        new AuthenticatedAgentBridgeClientData(tokenId: 1, name: 'Scoped client', scopes: ['capell.sites.read']),
    );

    $capabilities = agentBridgeStructuredContent($response)['capabilities'];
    $capabilityKeys = is_array($capabilities) ? array_column($capabilities, 'key') : [];

    expect($capabilityKeys)
        ->toBe(['capell.sites.search', 'capell.sites.inspect']);
});

it('suggests scoped site capabilities from a natural language request', function (): void {
    $registry = new CapellAgentBridgeCapabilityRegistry;
    $registry->register(new CapabilityData(
        key: 'capell.pages.clone',
        name: 'Clone page',
        description: 'Create a disabled copy of a page.',
        scope: 'capell.pages.write',
        server: CapabilityServerEnum::Site,
        risk: CapabilityRiskEnum::High,
        actionClass: CreateDraftPageCapabilityAction::class,
        category: 'pages',
        intents: ['clone page', 'copy page'],
        examples: ['Clone the About page.'],
    ));
    app()->instance(CapellAgentBridgeCapabilityRegistry::class, $registry);

    $response = (new SuggestSiteCapabilitiesTool)->handle(
        new Request(['query' => 'Copy the About page', 'limit' => 3]),
        new AuthenticatedAgentBridgeClientData(tokenId: 1, name: 'Scoped client', scopes: ['capell.pages.write']),
    );

    $structuredContent = agentBridgeStructuredContent($response);

    expect($structuredContent['query'])->toBe('Copy the About page')
        ->and($structuredContent['suggestions'])
        ->toHaveCount(1)
        ->and($structuredContent['suggestions'][0])
        ->toMatchArray([
            'key' => 'capell.pages.clone',
            'category' => 'pages',
            'requiresConfirmation' => true,
        ]);
});

it('lists built-in capability schemas for agent discovery', function (): void {
    CapellCore::shouldReceive('isPackageInstalled')->andReturnTrue();
    $registry = new CapellAgentBridgeCapabilityRegistry;
    (new BuiltInAgentBridgeCapabilityProvider)->registerCapabilities($registry);

    $response = (new ListSiteCapabilitiesTool)->handle(
        $registry,
        new AuthenticatedAgentBridgeClientData(tokenId: 1, name: 'Schema client', scopes: ['capell.pages.write']),
    );

    $capabilities = capabilityList(agentBridgeStructuredContent($response));

    $createDraft = firstCapabilityByKey($capabilities, 'capell.pages.create_draft');

    expect($createDraft)
        ->not->toHaveKeys(['inputDataClass', 'outputDataClass', 'actionClass', 'requiredPackage', 'policyAbility', 'auditEvent'])
        ->and(requiredSchemaFields($createDraft['inputSchema'] ?? null))->toBe(['name'])
        ->and(schemaProperties($createDraft['inputSchema'] ?? null))->toHaveKeys([
            'site',
            'page_type',
            'layout',
            'language',
            'title',
            'slug',
            'content',
        ]);
});

it('queries audit entries for the authenticated token', function (): void {
    $user = User::query()->create([
        'name' => 'Audit Tool User',
        'email' => 'audit-tool-user@example.com',
        'password' => 'secret',
    ]);

    $token = new CapellAgentBridgeToken;
    $token->forceFill([
        'name' => 'Audit client',
        'token_hash' => CapellAgentBridgeToken::hashPlainTextToken('audit-token'),
        'scopes' => ['*'],
        'user_type' => $user->getMorphClass(),
        'user_id' => $user->getKey(),
    ])->save();

    $entry = new CapellAgentBridgeAuditEntry([
        'agent_bridge_token_id' => $token->getKey(),
        'event' => 'capell_agent-bridge.test',
        'capability_key' => 'capell.fake.audit',
        'scope' => 'capell.fake.audit',
        'payload' => ['name' => 'Audit me'],
        'result' => ['ok' => true],
    ]);
    $entry->user()->associate($user);
    $entry->save();

    $response = (new QuerySiteAuditEntriesTool)->handle(
        new Request(['capability' => 'capell.fake.audit', 'limit' => 5]),
        $token,
    );

    $structured = agentBridgeStructuredContent($response);
    $entries = $structured['entries'];

    expect($entries)->toHaveCount(1)
        ->and($entries[0]['event'])->toBe('capell_agent-bridge.test')
        ->and($entries[0]['payload'])->toBe(['name' => 'Audit me'])
        ->and(data_get($structured, 'contentTrust.untrustedFields'))->toBe(['entries.*.payload', 'entries.*.result']);
});

it('denies non-v1 capabilities before previewing them for authenticated clients', function (): void {
    $registry = new CapellAgentBridgeCapabilityRegistry;
    app()->instance(CapellAgentBridgeCapabilityRegistry::class, $registry);
    $registry->register(new CapabilityData(
        key: 'capell.fake.confirmed',
        name: 'Fake confirmed',
        description: 'Confirmed fake capability.',
        scope: 'capell.fake.confirmed',
        server: CapabilityServerEnum::Site,
        risk: CapabilityRiskEnum::High,
        actionClass: FakeCapabilityAction::class,
    ));

    $user = User::query()->create([
        'name' => 'Tool User',
        'email' => 'tool-user@example.com',
        'password' => 'secret',
    ]);
    auth()->setUser($user);

    $token = new CapellAgentBridgeToken;
    $token->forceFill([
        'name' => 'Tool client',
        'token_hash' => CapellAgentBridgeToken::hashPlainTextToken('plain-token'),
        'scopes' => ['capell.fake.confirmed'],
        'user_type' => $user->getMorphClass(),
        'user_id' => $user->getKey(),
    ])->save();
    $client = new AuthenticatedAgentBridgeClientData(
        tokenId: (int) $token->getKey(),
        name: 'Tool client',
        scopes: ['capell.fake.confirmed'],
    );

    $response = agentBridgeStructuredContent((new RunSiteCapabilityTool)->handle(
        new Request([
            'capability' => 'capell.fake.confirmed',
            'payload' => ['name' => 'Preview me'],
        ]),
        $client,
        $token,
    ));

    expect($response['ok'])->toBeFalse()
        ->and(agentBridgeErrorCode($response))->toBe('forbidden');
});

it('rejects a counterfeit action registered under an allowlisted capability key', function (): void {
    $registry = new CapellAgentBridgeCapabilityRegistry;
    app()->instance(CapellAgentBridgeCapabilityRegistry::class, $registry);
    $registry->register(new CapabilityData(
        key: 'capell.sites.search',
        name: 'Fake readonly',
        description: 'Readonly fake capability.',
        scope: 'capell.sites.read',
        server: CapabilityServerEnum::Site,
        risk: CapabilityRiskEnum::Read,
        actionClass: FakeCapabilityAction::class,
        requiresConfirmation: false,
    ));

    $user = User::query()->create([
        'name' => 'Readonly Tool User',
        'email' => 'readonly-tool-user@example.com',
        'password' => 'secret',
    ]);
    auth()->setUser($user);

    $token = new CapellAgentBridgeToken;
    $token->forceFill([
        'name' => 'Readonly client',
        'token_hash' => CapellAgentBridgeToken::hashPlainTextToken('readonly-token'),
        'scopes' => ['capell.sites.read'],
        'user_type' => $user->getMorphClass(),
        'user_id' => $user->getKey(),
    ])->save();

    $response = agentBridgeStructuredContent((new RunSiteCapabilityTool)->handle(
        new Request([
            'capability' => 'capell.sites.search',
            'payload' => ['query' => 'Execute me'],
        ]),
        new AuthenticatedAgentBridgeClientData(
            tokenId: (int) $token->getKey(),
            name: 'Readonly client',
            scopes: ['capell.sites.read'],
        ),
        $token,
    ));

    expect($response['ok'])->toBeFalse()
        ->and(agentBridgeErrorCode($response))->toBe('forbidden');
});

it('hashes capability payloads deterministically regardless of key order', function (): void {
    expect(InvokeAgentBridgeCapabilityPreviewAction::payloadHash([
        'second' => ['nested' => true],
        'first' => 'value',
    ]))->toBe(InvokeAgentBridgeCapabilityPreviewAction::payloadHash([
        'first' => 'value',
        'second' => ['nested' => true],
    ]));
});

/**
 * @return array<string, mixed>
 */
function agentBridgeStructuredContent(ResponseFactory $response): array
{
    $structuredContent = $response->getStructuredContent();

    throw_unless(is_array($structuredContent), RuntimeException::class, 'Expected MCP response structured content.');

    return $structuredContent;
}

/** @param array<string, mixed> $response */
function agentBridgeErrorCode(array $response): string
{
    $error = $response['error'] ?? null;

    if (! is_array($error) || ! is_string($error['code'] ?? null)) {
        throw new RuntimeException('Expected MCP response error code.');
    }

    return $error['code'];
}

/**
 * @return array<string, mixed>
 */
function decodedJsonResourcePayload(string $json): array
{
    $payload = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

    throw_unless(is_array($payload), RuntimeException::class, 'Expected JSON resource payload array.');

    $normalized = [];

    foreach ($payload as $key => $value) {
        if (is_string($key)) {
            $normalized[$key] = $value;
        }
    }

    return $normalized;
}

/**
 * @param  array<string, mixed>  $structuredContent
 * @return list<array<string, mixed>>
 */
function capabilityList(array $structuredContent): array
{
    $capabilities = $structuredContent['capabilities'] ?? null;

    throw_unless(is_array($capabilities), RuntimeException::class, 'Expected capabilities list.');

    return array_values(array_map(
        static fn (array $capability): array => $capability,
        array_filter($capabilities, static fn (mixed $capability): bool => is_array($capability)),
    ));
}

/**
 * @param  list<array<string, mixed>>  $capabilities
 * @return array<string, mixed>
 */
function firstCapabilityByKey(array $capabilities, string $key): array
{
    foreach ($capabilities as $capability) {
        if (($capability['key'] ?? null) === $key) {
            return $capability;
        }
    }

    throw new RuntimeException(sprintf('Capability [%s] was not found.', $key));
}

/**
 * @return list<string>
 */
function requiredSchemaFields(mixed $schema): array
{
    if (! is_array($schema)) {
        throw new RuntimeException('Expected schema array.');
    }

    $required = $schema['required'] ?? null;

    throw_unless(is_array($required), RuntimeException::class, 'Expected required schema fields array.');

    return array_values(array_map(
        static fn (string $field): string => $field,
        array_filter($required, static fn (mixed $field): bool => is_string($field)),
    ));
}

/**
 * @return array<string, mixed>
 */
function schemaProperties(mixed $schema): array
{
    if (! is_array($schema)) {
        throw new RuntimeException('Expected schema array.');
    }

    $properties = $schema['properties'] ?? null;

    throw_unless(is_array($properties), RuntimeException::class, 'Expected schema properties array.');

    return array_map(
        static fn (mixed $value): mixed => $value,
        $properties,
    );
}

it('hashes nested capability payload objects deterministically while preserving list order', function (): void {
    expect(InvokeAgentBridgeCapabilityPreviewAction::payloadHash([
        'filters' => [
            'second' => true,
            'first' => [
                'beta' => 'two',
                'alpha' => 'one',
            ],
        ],
        'steps' => [
            ['name' => 'first'],
            ['name' => 'second'],
        ],
    ]))->toBe(InvokeAgentBridgeCapabilityPreviewAction::payloadHash([
        'steps' => [
            ['name' => 'first'],
            ['name' => 'second'],
        ],
        'filters' => [
            'first' => [
                'alpha' => 'one',
                'beta' => 'two',
            ],
            'second' => true,
        ],
    ]))->not->toBe(InvokeAgentBridgeCapabilityPreviewAction::payloadHash([
        'filters' => [
            'first' => [
                'alpha' => 'one',
                'beta' => 'two',
            ],
            'second' => true,
        ],
        'steps' => [
            ['name' => 'second'],
            ['name' => 'first'],
        ],
    ]));
});

it('exposes the agent bridge overview resource as markdown text', function (): void {
    expect((string) (new CapellAgentBridgeOverviewResource)->handle()->content())
        ->toContain('Capell Agent Bridge')
        ->toContain('CapellKnowledgeServer')
        ->toContain('CapellSiteServer');
});

it('builds and renders a capability governance catalog', function (): void {
    $registry = new CapellAgentBridgeCapabilityRegistry;
    $registry->register(new CapabilityData(
        key: 'capell.fake.write',
        name: 'Fake write',
        description: 'Write fake capability.',
        scope: 'capell.fake.write',
        server: CapabilityServerEnum::Site,
        risk: CapabilityRiskEnum::High,
        actionClass: FakeCapabilityAction::class,
        requiredPackage: 'capell-app/fake',
        policyAbility: 'updateFake',
    ));

    app()->instance(CapellAgentBridgeCapabilityRegistry::class, $registry);

    $catalog = BuildAgentBridgeCapabilityCatalogAction::run();
    $markdown = (string) (new CapellAgentBridgeCapabilityCatalogResource)->handle()->content();

    expect($catalog)->toHaveCount(1)
        ->and($catalog[0])->toMatchArray([
            'key' => 'capell.fake.write',
            'scope' => 'capell.fake.write',
            'server' => CapabilityServerEnum::Site->value,
            'risk' => CapabilityRiskEnum::High->value,
            'requires_confirmation' => true,
        ])
        ->and($catalog[0])->not->toHaveKeys(['required_package', 'policy_ability', 'audit_event', 'input_data_class', 'output_data_class'])
        ->and($markdown)->toContain('Capell Agent Bridge Capability Catalog')
        ->and($markdown)->toContain('`capell.fake.write`')
        ->and($markdown)->not->toContain('capell-app/fake')
        ->and($markdown)->not->toContain('updateFake');
});

it('exports a machine-readable MCP capability schema catalog', function (): void {
    $registry = new CapellAgentBridgeCapabilityRegistry;
    $registry->register(new CapabilityData(
        key: 'capell.pages.create_draft',
        name: 'Create draft page',
        description: 'Create a draft-like unpublished page record for an existing site, type, and layout.',
        scope: 'capell.pages.write',
        server: CapabilityServerEnum::Site,
        risk: CapabilityRiskEnum::High,
        actionClass: FakeCapabilityAction::class,
        inputDataClass: CreateDraftPageCapabilityInputData::class,
        outputDataClass: CapabilityResultData::class,
        inputSchema: CapabilitySchemas::createDraftPageInput(),
        outputSchema: CapabilitySchemas::capabilityResultOutput(),
        policyAbility: 'createPage',
    ));

    app()->instance(CapellAgentBridgeCapabilityRegistry::class, $registry);

    $payload = decodedJsonResourcePayload(
        (string) (new CapellAgentBridgeCapabilitySchemaResource)->handle()->content(),
    );
    $capabilities = capabilityList($payload);

    expect($payload)
        ->toBeArray()
        ->and($payload['schemaVersion'])->toBe('1.0')
        ->and($capabilities)->toHaveCount(1)
        ->and($capabilities[0])->toMatchArray([
            'key' => 'capell.pages.create_draft',
            'scope' => 'capell.pages.write',
            'server' => CapabilityServerEnum::Site->value,
            'risk' => CapabilityRiskEnum::High->value,
            'requires_confirmation' => true,
            'supports_preview' => true,
        ])
        ->and($capabilities[0])->not->toHaveKeys(['policy_ability', 'input_data_class', 'output_data_class', 'audit_event', 'required_package'])
        ->and(requiredSchemaFields($capabilities[0]['input_schema'] ?? null))
        ->toBe(['name'])
        ->and(schemaProperties($capabilities[0]['input_schema'] ?? null))
        ->toHaveKeys(['site', 'site_id', 'page_type', 'blueprint_id', 'layout', 'layout_id', 'language', 'title', 'slug', 'content'])
        ->and(schemaProperties($capabilities[0]['output_schema'] ?? null))
        ->toHaveKeys(['ok', 'message', 'data', 'warnings']);
});

it('resolves the capability registry through the facade', function (): void {
    CapellAgentBridge::register(new CapabilityData(
        key: 'capell.fake.facade',
        name: 'Fake facade',
        description: 'Facade fake capability.',
        scope: 'capell.fake.facade',
        server: CapabilityServerEnum::Site,
        risk: CapabilityRiskEnum::Read,
        actionClass: FakeCapabilityAction::class,
    ));

    expect(CapellAgentBridge::has('capell.fake.facade'))->toBeTrue();
});

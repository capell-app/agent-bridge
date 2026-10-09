<?php

declare(strict_types=1);

use Capell\Admin\Filament\Resources\Users\Pages\EditUser;
use Capell\AgentBridge\Actions\BuildAgentBridgePromptAction;
use Capell\AgentBridge\Actions\PrepareAgentBridgeScreenshotAction;
use Capell\AgentBridge\Actions\SaveAgentBridgePromptAction;
use Capell\AgentBridge\Data\AgentBridgePromptData;
use Capell\AgentBridge\Data\CapabilityCommandPatternData;
use Capell\AgentBridge\Data\CapabilityData;
use Capell\AgentBridge\Enums\CapabilityRiskEnum;
use Capell\AgentBridge\Enums\CapabilityServerEnum;
use Capell\AgentBridge\Filament\Pages\CapellAgentBridgePromptBuilderPage;
use Capell\AgentBridge\Filament\Resources\Users\RelationManagers\AgentBridgeAuditEntriesRelationManager;
use Capell\AgentBridge\Filament\Resources\Users\RelationManagers\AgentBridgeConfirmationsRelationManager;
use Capell\AgentBridge\Filament\Resources\Users\RelationManagers\AgentBridgeTokensRelationManager;
use Capell\AgentBridge\Livewire\PromptBuilderToolbarAction;
use Capell\AgentBridge\Models\CapellAgentBridgeAuditEntry;
use Capell\AgentBridge\Models\CapellAgentBridgeSavedPrompt;
use Capell\AgentBridge\Models\CapellAgentBridgeToken;
use Capell\AgentBridge\Support\CapellAgentBridgeCapabilityRegistry;
use Capell\AgentBridge\Tests\Fixtures\FakeCapabilityAction;
use Capell\AgentBridge\Tests\Fixtures\User;
use Capell\Tests\Support\ScreenshotManifest;
use Filament\Facades\Filament;
use Filament\Panel;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;

beforeEach(function (): void {
    $panel = Panel::make()
        ->id('admin')
        ->path('admin')
        ->default();
    Filament::registerPanel($panel);
    Filament::setCurrentPanel($panel);
    Filament::bootCurrentPanel();
    Filament::setServingStatus();
});

it('renders distinct seeded token confirmation and audit relation tables', function (): void {
    $user = User::query()->create(['name' => 'Screenshot operator', 'email' => 'screenshot@example.test', 'password' => 'password']);
    $this->actingAs($user);
    putenv('CAPELL_SCREENSHOT_FIXTURE=record-state');

    try {
        (new PrepareAgentBridgeScreenshotAction)->handle($user);
        $parameters = ['ownerRecord' => $user, 'pageClass' => EditUser::class];
        Livewire::test(AgentBridgeTokensRelationManager::class, $parameters)
            ->assertSee('Screenshot review token')->assertDontSee('screenshot.previewed');
        Livewire::test(AgentBridgeConfirmationsRelationManager::class, $parameters)
            ->assertSee('capell.pages.inspect')->assertSee(__('capell-agent-bridge::admin.expires_at'))->assertDontSee('screenshot.previewed');
        Livewire::test(AgentBridgeAuditEntriesRelationManager::class, $parameters)
            ->assertSee('screenshot.previewed')->assertDontSee('Screenshot review token');
        expect(CapellAgentBridgeToken::query()->where('user_id', $user->getKey())->count())->toBe(4)
            ->and(CapellAgentBridgeToken::query()->where('user_id', $user->getKey())->where('is_enabled', true)->whereNull('revoked_at')->count())->toBe(3)
            ->and(CapellAgentBridgeToken::query()->where('user_id', $user->getKey())->whereNotNull('revoked_at')->count())->toBe(1);
        $entry = ScreenshotManifest::entry(__DIR__ . '/../../docs/screenshots.json', 'token-management-or-setup-surface');
        expect($entry['beforeWait'] ?? [])->toContain(
            ['type' => 'click', 'selector' => '.fi-tabs-item:has-text("Tokens")'],
        )->and($entry['fullPage'] ?? false)->toBeTrue();
    } finally {
        putenv('CAPELL_SCREENSHOT_FIXTURE');
    }
});

it('builds an agent bridge prompt from the submitted intent', function (): void {
    $prompt = BuildAgentBridgePromptAction::run(AgentBridgePromptData::fromArray([
        'goal' => 'create a draft landing page',
        'area' => 'pages',
        'operation' => 'create',
        'safety' => 'preview_first',
        'target' => 'Spring campaign page',
        'constraints' => 'Use the default campaign layout',
        'success_criteria' => 'A draft exists but is not published',
    ]));

    expect($prompt)
        ->toContain('create a draft landing page')
        ->toContain('Area: pages')
        ->toContain('Operation: create')
        ->toContain('Safety mode: preview_first')
        ->toContain('Spring campaign page')
        ->toContain('Use the default campaign layout')
        ->toContain('A draft exists but is not published');
});

it('uses safe defaults when optional prompt context is omitted', function (): void {
    $prompt = BuildAgentBridgePromptAction::run(AgentBridgePromptData::fromArray([
        'goal' => 'inspect stale cache state',
        'area' => 'cache',
        'operation' => 'inspect',
        'safety' => 'read_only',
        'target' => '',
        'constraints' => '',
        'success_criteria' => '',
    ]));

    expect($prompt)
        ->toContain('Target/context:')
        ->toContain('Not provided.')
        ->toContain('Use Capell package boundaries, policies, and preview-first workflow.')
        ->toContain('Explain what changed or why no change is needed.');
});

it('limits prompt builder options to backed Agent Bridge operations', function (): void {
    expect(CapellAgentBridgePromptBuilderPage::areaOptions())->toHaveKeys([
        'pages',
        'cache',
        'packages',
    ])
        ->not->toHaveKeys([
            'seo',
            'redirects',
            'navigation',
            'other',
        ])
        ->and(CapellAgentBridgePromptBuilderPage::operationOptions())->toHaveKeys([
            'inspect',
            'create',
            'update',
            'disable',
            'clear',
            'recommend',
        ])
        ->not->toHaveKey('regenerate');
});

it('renders the AI command centre while retaining the reusable prompt builder', function (): void {
    $user = User::query()->create([
        'name' => 'Command Centre Admin',
        'email' => 'command-centre-admin@example.com',
        'password' => 'password',
    ]);

    $this->actingAs($user);

    Livewire::test(CapellAgentBridgePromptBuilderPage::class)
        ->assertSee(__('capell-agent-bridge::admin.command_heading'))
        ->assertSee(__('capell-agent-bridge::admin.command_suggestions_heading'))
        ->assertSee(__('capell-agent-bridge::admin.prompt_builder_title'))
        ->assertSeeHtml('id="capell-ai-command"')
        ->assertSeeHtml('window.SpeechRecognition');
});

it('keeps voice runtime translations outside the Alpine component expression', function (): void {
    $user = User::query()->create([
        'name' => 'Voice Runtime Admin',
        'email' => 'voice-runtime-admin@example.com',
        'password' => 'password',
    ]);

    $this->actingAs($user);

    Livewire::test(CapellAgentBridgePromptBuilderPage::class)
        ->assertSeeHtml('data-voice-unavailable="' . __('capell-agent-bridge::admin.command_voice_unavailable') . '"')
        ->assertSeeHtml('data-voice-error="' . __('capell-agent-bridge::admin.command_voice_error') . '"')
        ->assertSeeHtml('this.$root.dataset.voiceUnavailable')
        ->assertSeeHtml('this.$root.dataset.voiceError');
});

it('plans commands and executes read and confirmed write capabilities from the admin page', function (): void {
    $registry = new CapellAgentBridgeCapabilityRegistry;
    $registry->register(new CapabilityData(
        key: 'capell.fake.read',
        name: 'Read fake state',
        description: 'Read a fake record.',
        scope: 'capell.fake.read',
        server: CapabilityServerEnum::Site,
        risk: CapabilityRiskEnum::Read,
        actionClass: FakeCapabilityAction::class,
        requiresConfirmation: false,
        category: 'general',
        intents: ['read fake state'],
    ));
    $registry->register(new CapabilityData(
        key: 'capell.fake.write',
        name: 'Write fake state',
        description: 'Change a fake record.',
        scope: 'capell.fake.write',
        server: CapabilityServerEnum::Site,
        risk: CapabilityRiskEnum::High,
        actionClass: FakeCapabilityAction::class,
        category: 'general',
        intents: ['write fake state'],
    ));
    $registry->register(new CapabilityData(
        key: 'capell.fake.open_page',
        name: 'Open fake page',
        description: 'Open a fake page record.',
        scope: 'capell.fake.read',
        server: CapabilityServerEnum::Site,
        risk: CapabilityRiskEnum::Read,
        actionClass: FakeCapabilityAction::class,
        inputSchema: [
            'type' => 'object',
            'required' => ['page'],
            'properties' => ['page' => ['type' => 'string']],
            'additionalProperties' => false,
        ],
        requiresConfirmation: false,
        category: 'general',
        intents: ['open fake page'],
        commandPatterns: [
            new CapabilityCommandPatternData(
                expression: '/^(?:open|edit)\s+(?:the\s+)?(?<page>.+?)\s+page$/i',
                phrase: 'Open the {page} page',
                fields: ['page' => ['page']],
                required: ['page'],
            ),
        ],
    ));
    app()->instance(CapellAgentBridgeCapabilityRegistry::class, $registry);

    $user = User::query()->create([
        'name' => 'Command Runner',
        'email' => 'command-runner@example.com',
        'password' => 'password',
    ]);
    $this->actingAs($user);

    Livewire::test(CapellAgentBridgePromptBuilderPage::class)
        ->set('command', 'Open the About page')
        ->call('planCommand')
        ->assertSet('commandPlan.outcome', 'exact')
        ->assertSet('commandPlan.capabilityKey', 'capell.fake.open_page')
        ->assertSet('commandPlan.payload.page', 'About')
        ->call('chooseCapability', 'capell.fake.read')
        ->set('payloadJson', '{"query":"About"}')
        ->call('previewSelectedCapability')
        ->assertSet('commandResponse.mode', 'executed')
        ->assertSet('pendingConfirmationToken', null)
        ->call('chooseCapability', 'capell.fake.write')
        ->set('payloadJson', '{"name":"Example"}')
        ->call('previewSelectedCapability')
        ->assertSet('commandResponse.mode', 'preview')
        ->assertSet('pendingConfirmationToken', fn (?string $token): bool => is_string($token) && strlen($token) === 64)
        ->call('confirmPendingCapability')
        ->assertSet('commandResponse.mode', 'confirmed')
        ->assertSet('pendingConfirmationToken', null);

    expect(CapellAgentBridgeAuditEntry::query()->count())->toBe(3);
});

it('communicates each interpretation outcome in translated copy without preselecting anything', function (
    string $command,
    string $outcome,
    string $translationKey,
): void {
    $registry = new CapellAgentBridgeCapabilityRegistry;
    $registry->register(new CapabilityData(
        key: 'capell.fake.clone_page',
        name: 'Clone page',
        description: 'Create a disabled copy of an existing page.',
        scope: 'capell.fake.write',
        server: CapabilityServerEnum::Site,
        risk: CapabilityRiskEnum::High,
        actionClass: FakeCapabilityAction::class,
        category: 'general',
        intents: ['duplicate an existing page'],
    ));
    $registry->register(new CapabilityData(
        key: 'capell.fake.alpha',
        name: 'Alpha',
        description: 'Alpha.',
        scope: 'capell.fake.write',
        server: CapabilityServerEnum::Site,
        risk: CapabilityRiskEnum::High,
        actionClass: FakeCapabilityAction::class,
        category: 'general',
        intents: ['archive stale media'],
    ));
    $registry->register(new CapabilityData(
        key: 'capell.fake.beta',
        name: 'Beta',
        description: 'Beta.',
        scope: 'capell.fake.write',
        server: CapabilityServerEnum::Site,
        risk: CapabilityRiskEnum::High,
        actionClass: FakeCapabilityAction::class,
        category: 'general',
        intents: ['archive stale media'],
    ));
    app()->instance(CapellAgentBridgeCapabilityRegistry::class, $registry);

    $user = User::query()->create([
        'name' => 'Outcome Reader',
        'email' => 'outcome-reader@example.com',
        'password' => 'password',
    ]);
    $this->actingAs($user);

    Livewire::test(CapellAgentBridgePromptBuilderPage::class)
        ->set('command', $command)
        ->call('planCommand')
        ->assertSet('commandOutcome', $outcome)
        ->assertSet('selectedCapabilityKey', '')
        ->assertSet('commandPlan.capabilityKey', null)
        ->assertSet('commandError', fn (string $error): bool => $error !== '')
        ->assertSee(__($translationKey));
})->with([
    'suggested' => [
        'duplicate an existing page',
        'suggested',
        'capell-agent-bridge::admin.command_plan_suggested_heading',
    ],
    'ambiguous' => [
        'archive stale media',
        'ambiguous',
        'capell-agent-bridge::admin.command_plan_ambiguous_heading',
    ],
    'unmatched' => [
        'reconcile quarterly invoices',
        'unmatched',
        'capell-agent-bridge::admin.command_suggestions_heading',
    ],
]);

it('keeps invalid advanced payloads client safe and makes no change', function (): void {
    $registry = new CapellAgentBridgeCapabilityRegistry;
    $registry->register(new CapabilityData(
        key: 'capell.fake.write',
        name: 'Write fake state',
        description: 'Change a fake record.',
        scope: 'capell.fake.write',
        server: CapabilityServerEnum::Site,
        risk: CapabilityRiskEnum::High,
        actionClass: FakeCapabilityAction::class,
    ));
    app()->instance(CapellAgentBridgeCapabilityRegistry::class, $registry);

    $user = User::query()->create([
        'name' => 'Payload Reviewer',
        'email' => 'payload-reviewer@example.com',
        'password' => 'password',
    ]);
    $this->actingAs($user);

    Livewire::test(CapellAgentBridgePromptBuilderPage::class)
        ->call('chooseCapability', 'capell.fake.write')
        ->set('payloadJson', '{not valid json}')
        ->call('previewSelectedCapability')
        ->assertSet('commandError', __('capell-agent-bridge::admin.command_payload_invalid_json'))
        ->assertSet('commandResponse', [])
        ->assertSet('pendingConfirmationToken', null);

    expect(CapellAgentBridgeAuditEntry::query()->count())->toBe(0);
});

it('saves loads lists and deletes prompts scoped to the authenticated admin user', function (): void {
    $user = User::query()->create([
        'name' => 'Admin One',
        'email' => 'admin-one@example.com',
        'password' => 'password',
    ]);
    $otherUser = User::query()->create([
        'name' => 'Admin Two',
        'email' => 'admin-two@example.com',
        'password' => 'password',
    ]);

    $data = AgentBridgePromptData::fromArray([
        'goal' => 'inspect page readiness',
        'area' => 'pages',
        'operation' => 'inspect',
        'safety' => 'read_only',
        'target' => 'Homepage',
    ]);

    $savedPrompt = SaveAgentBridgePromptAction::run($user, 'Readiness check', 'Homepage review', $data);
    SaveAgentBridgePromptAction::run($otherUser, 'Other admin prompt', null, $data);

    expect(CapellAgentBridgeSavedPrompt::query()->forUser($user)->pluck('name')->all())
        ->toBe(['Readiness check'])
        ->and($savedPrompt->prompt)
        ->toContain('inspect page readiness')
        ->and($savedPrompt->form_state)
        ->toMatchArray(['target' => 'Homepage']);

    $this->actingAs($user);

    Livewire::test(PromptBuilderToolbarAction::class)
        ->set('selectedSavedPromptId', $savedPrompt->id)
        ->call('loadSavedPrompt')
        ->assertSet('templateName', 'Readiness check')
        ->assertSet('data.target', 'Homepage')
        ->call('deleteSavedPrompt');

    expect(CapellAgentBridgeSavedPrompt::query()->whereKey($savedPrompt->id)->exists())->toBeFalse()
        ->and(CapellAgentBridgeSavedPrompt::query()->forUser($otherUser)->count())->toBe(1);
});

it('rejects direct saved prompt updates for another admin user', function (): void {
    $user = User::query()->create([
        'name' => 'Admin One',
        'email' => 'direct-admin-one@example.com',
        'password' => 'password',
    ]);
    $otherUser = User::query()->create([
        'name' => 'Admin Two',
        'email' => 'direct-admin-two@example.com',
        'password' => 'password',
    ]);

    $data = AgentBridgePromptData::fromArray([
        'goal' => 'inspect page readiness',
        'area' => 'pages',
        'operation' => 'inspect',
        'safety' => 'read_only',
    ]);
    $otherPrompt = SaveAgentBridgePromptAction::run($otherUser, 'Other admin prompt', null, $data);

    expect(fn (): CapellAgentBridgeSavedPrompt => SaveAgentBridgePromptAction::run(
        user: $user,
        name: 'Hijacked prompt',
        description: null,
        data: $data,
        savedPrompt: $otherPrompt,
    ))->toThrow(AuthorizationException::class);

    expect($otherPrompt->refresh()->name)->toBe('Other admin prompt');
});

it('registers the toolbar Livewire component used by the global search render hook', function (): void {
    expect(FilamentView::hasRenderHook(PanelsRenderHook::GLOBAL_SEARCH_BEFORE))->toBeTrue();

    $html = Blade::render('@livewire($component)', [
        'component' => 'capell-agent-bridge.prompt-builder-toolbar-action',
    ]);

    expect($html)
        ->toContain(__('capell-agent-bridge::admin.prompt_builder_tooltip'))
        ->toContain(__('capell-agent-bridge::admin.prompt_builder_tool'))
        ->toContain('wire:click="openBuilder"')
        ->toContain('M7.5 8.25h9')
        ->not->toContain('agent-bridge-prompt-builder-title');
});

it('renders the toolbar slide-over only after click and refreshes prompt text on edits', function (): void {
    Livewire::test(PromptBuilderToolbarAction::class)
        ->assertSet('isOpen', false)
        ->assertDontSeeHtml('id="agent-bridge-prompt-builder-title"')
        ->call('openBuilder')
        ->assertSet('isOpen', true)
        ->assertSeeHtml('id="agent-bridge-prompt-builder-title"')
        ->set('data.goal', 'inspect updated draft state')
        ->assertSet('preparedPrompt', fn (string $prompt): bool => str_contains($prompt, 'inspect updated draft state'));
});

it('applies prompt starters through the toolbar component and rebuilds the prepared prompt', function (string $starter, string $area, string $operation, string $safety): void {
    Livewire::test(PromptBuilderToolbarAction::class)
        ->call('applyStarter', $starter)
        ->assertSet('data.area', $area)
        ->assertSet('data.operation', $operation)
        ->assertSet('data.safety', $safety)
        ->assertSet('preparedPrompt', fn (string $prompt): bool => str_contains($prompt, 'Area: ' . $area)
            && str_contains($prompt, 'Operation: ' . $operation)
            && str_contains($prompt, 'Safety mode: ' . $safety));
})->with([
    'disabled draft page' => ['create_disabled_draft_page', 'pages', 'create', 'preview_first'],
    'update draft content' => ['update_draft_content', 'pages', 'update', 'preview_first'],
    'clear cache' => ['clear_cache', 'cache', 'clear', 'prepare_confirmation'],
    'recommend packages' => ['recommend_packages', 'packages', 'recommend', 'read_only'],
    'fallback readiness' => ['unknown_starter', 'pages', 'inspect', 'read_only'],
]);

it('saves a prompt from the toolbar component for the authenticated admin', function (): void {
    $user = User::query()->create([
        'name' => 'Prompt Admin',
        'email' => 'prompt-admin@example.com',
        'password' => 'password',
    ]);

    $this->actingAs($user);

    Livewire::test(PromptBuilderToolbarAction::class)
        ->set('templateName', 'Launch checklist')
        ->set('templateDescription', 'Reusable launch review')
        ->set('data.goal', 'inspect launch readiness')
        ->set('data.area', 'pages')
        ->set('data.operation', 'inspect')
        ->set('data.safety', 'read_only')
        ->set('data.target', 'Homepage')
        ->call('savePrompt')
        ->assertSet('selectedSavedPromptId', fn (?int $id): bool => $id !== null)
        ->assertSet('preparedPrompt', fn (string $prompt): bool => str_contains($prompt, 'inspect launch readiness'));

    $savedPrompt = CapellAgentBridgeSavedPrompt::query()->where('name', 'Launch checklist')->firstOrFail();

    expect($savedPrompt->user_id)->toBe($user->getKey())
        ->and($savedPrompt->description)->toBe('Reusable launch review')
        ->and($savedPrompt->form_state)->toMatchArray([
            'goal' => 'inspect launch readiness',
            'target' => 'Homepage',
        ]);
});

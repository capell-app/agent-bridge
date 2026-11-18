<?php

declare(strict_types=1);

use Capell\Admin\Filament\Resources\Users\Pages\EditUser;
use Capell\AgentBridge\Actions\ApproveAgentBridgeConfirmationAction;
use Capell\AgentBridge\Actions\ConfirmAgentBridgeCapabilityAction;
use Capell\AgentBridge\Actions\ConfirmAgentBridgeMcpV1CapabilityAction;
use Capell\AgentBridge\Actions\CreateAgentBridgeTokenAction;
use Capell\AgentBridge\Actions\DeclineAgentBridgeConfirmationAction;
use Capell\AgentBridge\Actions\InvokeAgentBridgeCapabilityPreviewAction;
use Capell\AgentBridge\Actions\RevokeAgentBridgeTokenAction;
use Capell\AgentBridge\Data\AuthenticatedAgentBridgeClientData;
use Capell\AgentBridge\Data\CapabilityData;
use Capell\AgentBridge\Enums\CapabilityRiskEnum;
use Capell\AgentBridge\Enums\CapabilityServerEnum;
use Capell\AgentBridge\Exceptions\AgentBridgeConfirmationAwaitingApprovalException;
use Capell\AgentBridge\Filament\Resources\Users\RelationManagers\AgentBridgeConfirmationsRelationManager;
use Capell\AgentBridge\Models\CapellAgentBridgeAuditEntry;
use Capell\AgentBridge\Models\CapellAgentBridgeConfirmation;
use Capell\AgentBridge\Models\CapellAgentBridgeToken;
use Capell\AgentBridge\Support\AgentBridgeMcpV1ErrorMapper;
use Capell\AgentBridge\Support\AgentBridgeMcpV1Policy;
use Capell\AgentBridge\Support\AgentBridgeTokenManagementAccess;
use Capell\AgentBridge\Support\CapellAgentBridgeCapabilityRegistry;
use Capell\AgentBridge\Tests\Fixtures\FakeCapabilityAction;
use Capell\AgentBridge\Tests\Fixtures\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

beforeEach(function (): void {
    $panel = Panel::make()->id('admin')->path('admin')->default();
    Filament::registerPanel($panel);
    Filament::setCurrentPanel($panel);
    Filament::bootCurrentPanel();
    Filament::setServingStatus();
});

function humanApprovalCapability(CapabilityRiskEnum $risk = CapabilityRiskEnum::High, ?string $policyAbility = null): void
{
    resolve(CapellAgentBridgeCapabilityRegistry::class)->register(new CapabilityData(
        key: 'capell.fake.approval',
        name: 'Fake approval write',
        description: 'Fake capability that changes site content.',
        scope: 'capell.fake.approval',
        server: CapabilityServerEnum::Site,
        risk: $risk,
        actionClass: FakeCapabilityAction::class,
        policyAbility: $policyAbility,
        auditEvent: 'capell_agent-bridge.fake.approval',
    ));
}

function humanApprovalUser(string $email): User
{
    return User::query()->create(['name' => $email, 'email' => $email, 'password' => 'secret']);
}

/**
 * @return array{token: CapellAgentBridgeToken, client: AuthenticatedAgentBridgeClientData}
 */
function humanApprovalClient(User $owner): array
{
    $token = CreateAgentBridgeTokenAction::run($owner, 'Assistant', ['capell.fake.approval'])['token'];

    return [
        'token' => $token,
        'client' => new AuthenticatedAgentBridgeClientData(tokenId: $token->id, name: 'Assistant', scopes: ['capell.fake.approval']),
    ];
}

/**
 * @param  array{token: CapellAgentBridgeToken, client: AuthenticatedAgentBridgeClientData}  $bridge
 * @param  array<string, mixed>  $payload
 * @return array<string, mixed>
 */
function humanApprovalPreview(array $bridge, User $owner, array $payload = ['name' => 'Spring sale']): array
{
    return InvokeAgentBridgeCapabilityPreviewAction::run(
        capabilityKey: 'capell.fake.approval',
        payload: $payload,
        client: $bridge['client'],
        token: $bridge['token'],
        user: $owner,
    );
}

/**
 * @param  array{token: CapellAgentBridgeToken, client: AuthenticatedAgentBridgeClientData}  $bridge
 * @param  array<string, mixed>  $preview
 * @param  array<string, mixed>  $payload
 * @return array<string, mixed>
 */
function humanApprovalConfirm(array $bridge, User $owner, array $preview, array $payload = ['name' => 'Spring sale']): array
{
    return ConfirmAgentBridgeCapabilityAction::run(
        confirmationToken: humanApprovalToken($preview),
        payload: $payload,
        client: $bridge['client'],
        token: $bridge['token'],
        user: $owner,
    );
}

/** @param array<string, mixed> $preview */
function humanApprovalToken(array $preview): string
{
    $token = $preview['confirmationToken'] ?? null;

    throw_unless(is_string($token), RuntimeException::class, 'Expected a confirmation token.');

    return $token;
}

/** @param array<string, mixed> $preview */
function humanApprovalConfirmation(array $preview): CapellAgentBridgeConfirmation
{
    return CapellAgentBridgeConfirmation::query()
        ->where('token', CapellAgentBridgeConfirmation::hashPlainTextToken(humanApprovalToken($preview)))
        ->firstOrFail();
}

function humanApprovalExecutions(): int
{
    return CapellAgentBridgeAuditEntry::query()->where('event', 'capell_agent-bridge.fake.approval')->count();
}

it('does not let the agent token confirm a high-risk change on its own', function (): void {
    humanApprovalCapability();
    $owner = humanApprovalUser('owner@example.test');
    $bridge = humanApprovalClient($owner);

    $preview = humanApprovalPreview($bridge, $owner);

    expect($preview['approvalRequired'])->toBeTrue()
        ->and($preview['nextStep'])->toContain('approve')
        ->and(humanApprovalConfirmation($preview)->requires_approval)->toBeTrue();

    expect(fn (): array => humanApprovalConfirm($bridge, $owner, $preview))
        ->toThrow(AgentBridgeConfirmationAwaitingApprovalException::class);

    expect(humanApprovalConfirmation($preview)->used_at)->toBeNull()
        ->and(humanApprovalExecutions())->toBe(0);

    ApproveAgentBridgeConfirmationAction::run(humanApprovalConfirmation($preview), $owner);

    $result = humanApprovalConfirm($bridge, $owner, $preview);

    expect($result['mode'])->toBe('confirmed')
        ->and(humanApprovalExecutions())->toBe(1)
        ->and(CapellAgentBridgeAuditEntry::query()->where('event', 'capell_agent-bridge.capability.approved')->count())->toBe(1);
});

it('keeps the approval bound to the previewed payload', function (): void {
    humanApprovalCapability();
    $owner = humanApprovalUser('payload-owner@example.test');
    $bridge = humanApprovalClient($owner);
    $preview = humanApprovalPreview($bridge, $owner);
    ApproveAgentBridgeConfirmationAction::run(humanApprovalConfirmation($preview), $owner);

    expect(fn (): array => humanApprovalConfirm($bridge, $owner, $preview, ['name' => 'Something else']))
        ->toThrow(AuthorizationException::class, 'The Agent Bridge confirmation payload has changed.');

    expect(humanApprovalExecutions())->toBe(0);
});

it('lets the token confirm lower-risk changes without approval unless the host asks for it', function (): void {
    humanApprovalCapability(CapabilityRiskEnum::Medium);
    $owner = humanApprovalUser('medium-owner@example.test');
    $bridge = humanApprovalClient($owner);

    $preview = humanApprovalPreview($bridge, $owner);

    expect($preview['approvalRequired'])->toBeFalse()
        ->and(humanApprovalConfirm($bridge, $owner, $preview)['mode'])->toBe('confirmed');

    config()->set('capell-agent-bridge.human_approval_risks', ['medium', 'high', 'destructive']);
    $stricter = humanApprovalPreview($bridge, $owner, ['name' => 'Second']);

    expect($stricter['approvalRequired'])->toBeTrue()
        ->and(fn (): array => humanApprovalConfirm($bridge, $owner, $stricter, ['name' => 'Second']))
        ->toThrow(AgentBridgeConfirmationAwaitingApprovalException::class);
});

it('still requires approval when the host stops requiring it after the preview', function (): void {
    humanApprovalCapability();
    $owner = humanApprovalUser('lowered-owner@example.test');
    $bridge = humanApprovalClient($owner);
    $preview = humanApprovalPreview($bridge, $owner);

    config()->set('capell-agent-bridge.human_approval_risks', []);

    expect(fn (): array => humanApprovalConfirm($bridge, $owner, $preview))
        ->toThrow(AgentBridgeConfirmationAwaitingApprovalException::class);
});

it('only lets the person the token acts as approve, even an administrator who manages their tokens', function (): void {
    humanApprovalCapability();
    $owner = humanApprovalUser('approval-owner@example.test');
    $administrator = humanApprovalUser('approval-admin@example.test');
    Gate::before(static fn (mixed $user): ?bool => $user instanceof User && $user->is($administrator) ? true : null);
    $bridge = humanApprovalClient($owner);
    $preview = humanApprovalPreview($bridge, $owner);

    expect(AgentBridgeTokenManagementAccess::allows($administrator, $owner))->toBeTrue()
        ->and(fn (): CapellAgentBridgeConfirmation => ApproveAgentBridgeConfirmationAction::run(humanApprovalConfirmation($preview), $administrator))
        ->toThrow(AuthorizationException::class, 'Only the person this assistant acts for can approve its changes.');

    expect(humanApprovalConfirmation($preview)->approved_at)->toBeNull();
});

it('refuses approval from inside an Agent Bridge request', function (): void {
    humanApprovalCapability();
    $owner = humanApprovalUser('bridge-request@example.test');
    $bridge = humanApprovalClient($owner);
    $preview = humanApprovalPreview($bridge, $owner);

    app()->instance(AuthenticatedAgentBridgeClientData::class, $bridge['client']);

    expect(fn (): CapellAgentBridgeConfirmation => ApproveAgentBridgeConfirmationAction::run(humanApprovalConfirmation($preview), $owner))
        ->toThrow(AuthorizationException::class, 'Changes can only be approved by a person in Capell admin, not through Agent Bridge.')
        ->and(DeclineAgentBridgeConfirmationAction::allows(humanApprovalConfirmation($preview), $owner))->toBeFalse();
});

it('refuses approval once the token is revoked', function (): void {
    humanApprovalCapability();
    $owner = humanApprovalUser('revoked-approval@example.test');
    $bridge = humanApprovalClient($owner);
    $preview = humanApprovalPreview($bridge, $owner);

    RevokeAgentBridgeTokenAction::run($bridge['token']);

    expect(fn (): CapellAgentBridgeConfirmation => ApproveAgentBridgeConfirmationAction::run(humanApprovalConfirmation($preview), $owner))
        ->toThrow(AuthorizationException::class, 'This change cannot be approved because the assistant\'s token is revoked, expired or no longer allowed to make it.');
});

it('needs the capability permission to approve it', function (): void {
    humanApprovalCapability(policyAbility: 'approve fake capability');
    Gate::define('approve fake capability', static fn (User $user): bool => $user->name === 'allowed@example.test');
    $owner = humanApprovalUser('allowed@example.test');
    $bridge = humanApprovalClient($owner);
    $preview = humanApprovalPreview($bridge, $owner);

    User::query()->whereKey($owner->getKey())->update(['name' => 'demoted']);

    expect(fn (): CapellAgentBridgeConfirmation => ApproveAgentBridgeConfirmationAction::run(humanApprovalConfirmation($preview), $owner->refresh()))
        ->toThrow(AuthorizationException::class, 'This action is unauthorized.');
});

it('cannot approve the same change twice or approve an expired one', function (): void {
    humanApprovalCapability();
    $owner = humanApprovalUser('twice@example.test');
    $bridge = humanApprovalClient($owner);
    $preview = humanApprovalPreview($bridge, $owner);
    ApproveAgentBridgeConfirmationAction::run(humanApprovalConfirmation($preview), $owner);

    expect(fn (): CapellAgentBridgeConfirmation => ApproveAgentBridgeConfirmationAction::run(humanApprovalConfirmation($preview), $owner))
        ->toThrow(AuthorizationException::class, 'This change is not waiting for approval.');

    $expired = humanApprovalPreview($bridge, $owner, ['name' => 'Late']);
    humanApprovalConfirmation($expired)->forceFill(['expires_at' => now()->subMinute()])->save();

    expect(fn (): CapellAgentBridgeConfirmation => ApproveAgentBridgeConfirmationAction::run(humanApprovalConfirmation($expired), $owner))
        ->toThrow(AuthorizationException::class, 'This change is not waiting for approval.');
});

it('gives a preview that needs approval longer to live than one the agent confirms itself', function (): void {
    config()->set('capell-agent-bridge.approval_ttl_minutes', 45);
    humanApprovalCapability();
    $owner = humanApprovalUser('ttl@example.test');
    $bridge = humanApprovalClient($owner);

    $this->freezeTime();
    $preview = humanApprovalPreview($bridge, $owner);

    expect(humanApprovalConfirmation($preview)->expires_at->toDateTimeString())->toBe(now()->addMinutes(45)->toDateTimeString())
        ->and($preview['expiresAt'])->toBe(now()->addMinutes(45)->toIso8601String());
});

it('never runs a declined change, and lets someone who manages the tokens decline it', function (): void {
    humanApprovalCapability();
    $owner = humanApprovalUser('declined-owner@example.test');
    $manager = humanApprovalUser('declined-manager@example.test');
    $stranger = humanApprovalUser('declined-stranger@example.test');
    Gate::before(static fn (mixed $user): ?bool => $user instanceof User && $user->is($manager) ? true : null);
    $bridge = humanApprovalClient($owner);
    $preview = humanApprovalPreview($bridge, $owner);
    ApproveAgentBridgeConfirmationAction::run(humanApprovalConfirmation($preview), $owner);

    expect(DeclineAgentBridgeConfirmationAction::allows(humanApprovalConfirmation($preview), $stranger))->toBeFalse()
        ->and(fn (): CapellAgentBridgeConfirmation => DeclineAgentBridgeConfirmationAction::run(humanApprovalConfirmation($preview), $stranger))
        ->toThrow(AuthorizationException::class);

    DeclineAgentBridgeConfirmationAction::run(humanApprovalConfirmation($preview), $manager);

    expect(fn (): array => humanApprovalConfirm($bridge, $owner, $preview))
        ->toThrow(AuthorizationException::class, 'The Agent Bridge confirmation token is invalid or expired.');

    expect(humanApprovalExecutions())->toBe(0)
        ->and(CapellAgentBridgeAuditEntry::query()->where('event', 'capell_agent-bridge.capability.declined')->count())->toBe(1);
});

it('tells an MCP client the change is waiting for approval rather than invalid', function (): void {
    $owner = humanApprovalUser('mcp-owner@example.test');
    $token = CreateAgentBridgeTokenAction::run($owner, 'MCP client', ['capell.pages.write'])['token'];
    $client = new AuthenticatedAgentBridgeClientData(tokenId: $token->id, name: 'MCP client', scopes: ['capell.pages.write']);
    $payload = ['site' => 'main', 'name' => 'Spring sale'];
    $plainTextConfirmationToken = str_repeat('a', 64);
    $confirmation = new CapellAgentBridgeConfirmation([
        'token' => CapellAgentBridgeConfirmation::hashPlainTextToken($plainTextConfirmationToken),
        'agent_bridge_token_id' => $token->getKey(),
        'capability_key' => 'capell.pages.create_draft',
        'scope' => 'capell.pages.write',
        'payload_hash' => InvokeAgentBridgeCapabilityPreviewAction::payloadHash($payload),
        'payload' => $payload,
        'preview' => [],
        'requires_approval' => true,
        'expires_at' => now()->addHour(),
    ]);
    $confirmation->user()->associate($owner);
    $confirmation->save();

    try {
        ConfirmAgentBridgeMcpV1CapabilityAction::run($plainTextConfirmationToken, $payload, $client, $token, $owner);
        $error = null;
    } catch (Throwable $exception) {
        $error = resolve(AgentBridgeMcpV1ErrorMapper::class)->map($exception);
    }

    expect($error['error']['code'] ?? null)->toBe('approval_required')
        ->and($confirmation->fresh()?->used_at)->toBeNull();

    $policy = resolve(AgentBridgeMcpV1Policy::class);
    $projected = $policy->projectOutput(
        resolve(CapellAgentBridgeCapabilityRegistry::class)->get('capell.pages.create_draft'),
        ['mode' => 'preview', 'preview' => ['ok' => true], 'confirmationToken' => $plainTextConfirmationToken, 'expiresAt' => 'soon', 'approvalRequired' => true, 'nextStep' => 'Ask the person to approve.'],
    );

    expect($projected['approvalRequired'])->toBeTrue()
        ->and($projected['nextStep'])->toBe('Ask the person to approve.');
});

it('shows the approve action only to the person the token acts as, and approves from the admin', function (): void {
    humanApprovalCapability();
    $owner = humanApprovalUser('filament-owner@example.test');
    $administrator = humanApprovalUser('filament-admin@example.test');
    Gate::before(static fn (mixed $user): ?bool => $user instanceof User && $user->is($administrator) ? true : null);
    $bridge = humanApprovalClient($owner);
    $preview = humanApprovalPreview($bridge, $owner);
    $confirmation = humanApprovalConfirmation($preview);

    $this->actingAs($administrator);
    Livewire::test(AgentBridgeConfirmationsRelationManager::class, ['ownerRecord' => $owner, 'pageClass' => EditUser::class])
        ->assertActionHidden(TestAction::make('approve')->table($confirmation))
        ->assertActionVisible(TestAction::make('decline')->table($confirmation));

    $this->actingAs($owner);
    Livewire::test(AgentBridgeConfirmationsRelationManager::class, ['ownerRecord' => $owner, 'pageClass' => EditUser::class])
        ->assertActionVisible(TestAction::make('approve')->table($confirmation))
        ->callAction(TestAction::make('approve')->table($confirmation))
        ->assertHasNoActionErrors();

    expect($confirmation->fresh()?->isApprovedBy($owner))->toBeTrue()
        ->and(humanApprovalConfirm($bridge, $owner, $preview)['mode'])->toBe('confirmed');
});

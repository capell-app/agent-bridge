<?php

declare(strict_types=1);

use Capell\AgentBridge\Actions\ApproveAgentBridgeConfirmationAction;
use Capell\AgentBridge\Actions\ConfirmAgentBridgeCapabilityAction;
use Capell\AgentBridge\Actions\CreateAgentBridgeTokenAction;
use Capell\AgentBridge\Actions\InvokeAgentBridgeCapabilityPreviewAction;
use Capell\AgentBridge\Data\AuthenticatedAgentBridgeClientData;
use Capell\AgentBridge\Data\CapabilityData;
use Capell\AgentBridge\Enums\CapabilityRiskEnum;
use Capell\AgentBridge\Enums\CapabilityServerEnum;
use Capell\AgentBridge\Models\CapellAgentBridgeAuditEntry;
use Capell\AgentBridge\Models\CapellAgentBridgeConfirmation;
use Capell\AgentBridge\Models\CapellAgentBridgeToken;
use Capell\AgentBridge\Support\CapellAgentBridgeCapabilityRegistry;
use Capell\AgentBridge\Tests\Fixtures\FakeCapabilityAction;
use Capell\AgentBridge\Tests\Fixtures\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

function registerFakeCapability(string $scope = 'capell.fake.write', ?string $policyAbility = null, CapabilityRiskEnum $risk = CapabilityRiskEnum::High): void
{
    resolve(CapellAgentBridgeCapabilityRegistry::class)->register(new CapabilityData(
        key: 'capell.fake.write',
        name: 'Fake write',
        description: 'Fake mutating capability.',
        scope: $scope,
        server: CapabilityServerEnum::Site,
        risk: $risk,
        actionClass: FakeCapabilityAction::class,
        policyAbility: $policyAbility,
        auditEvent: 'capell_agent-bridge.fake.write',
    ));
}

/**
 * @param  array<string, mixed>  $created
 */
function agentBridgeCreatedToken(array $created): CapellAgentBridgeToken
{
    $token = $created['token'] ?? null;

    throw_unless($token instanceof CapellAgentBridgeToken, RuntimeException::class, 'Expected created agent bridge token.');

    return $token;
}

/**
 * @param  array<string, mixed>  $preview
 */
function agentBridgeConfirmationToken(array $preview): string
{
    $confirmationToken = $preview['confirmationToken'] ?? null;

    throw_unless(is_string($confirmationToken), RuntimeException::class, 'Expected agent bridge confirmation token.');

    return $confirmationToken;
}

/**
 * The person the token acts as approves the preview in the admin.
 *
 * @param  array<string, mixed>  $preview
 */
function approveAgentBridgePreview(array $preview, User $approver): CapellAgentBridgeConfirmation
{
    $confirmation = CapellAgentBridgeConfirmation::query()
        ->where('token', CapellAgentBridgeConfirmation::hashPlainTextToken(agentBridgeConfirmationToken($preview)))
        ->firstOrFail();

    return ApproveAgentBridgeConfirmationAction::run($confirmation, $approver);
}

function agentBridgeTokenId(CapellAgentBridgeToken $token): int
{
    $key = $token->getKey();

    throw_unless(is_numeric($key), RuntimeException::class, 'Expected numeric agent bridge token key.');

    return (int) $key;
}

it('previews and confirms a mutating capability with the same payload', function (): void {
    registerFakeCapability();

    $user = User::query()->create([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'secret',
    ]);

    $created = CreateAgentBridgeTokenAction::run($user, 'Test client', ['capell.fake.write']);
    $token = agentBridgeCreatedToken($created);
    $client = new AuthenticatedAgentBridgeClientData(
        tokenId: agentBridgeTokenId($token),
        name: 'Test client',
        scopes: ['capell.fake.write'],
    );

    $payload = ['name' => 'Example'];

    $preview = InvokeAgentBridgeCapabilityPreviewAction::run(
        capabilityKey: 'capell.fake.write',
        payload: $payload,
        client: $client,
        token: $token,
        user: $user,
    );
    $confirmationToken = agentBridgeConfirmationToken($preview);
    $storedConfirmationToken = CapellAgentBridgeConfirmation::query()->value('token');

    expect($preview['mode'])->toBe('preview')
        ->and($preview['approvalRequired'])->toBeTrue()
        ->and($confirmationToken)->toBeString()
        ->and($storedConfirmationToken)->toBeString()
        ->and($storedConfirmationToken)->not->toBe($confirmationToken);

    approveAgentBridgePreview($preview, $user);

    $result = ConfirmAgentBridgeCapabilityAction::run(
        confirmationToken: $confirmationToken,
        payload: $payload,
        client: $client,
        token: $token,
        user: $user,
    );

    expect($result['mode'])->toBe('confirmed')
        ->and($result['result']['message'])->toBe('Executed fake capability.')
        ->and(CapellAgentBridgeConfirmation::query()->whereNotNull('used_at')->count())->toBe(1);

    expect(fn (): array => ConfirmAgentBridgeCapabilityAction::run(
        confirmationToken: agentBridgeConfirmationToken($preview),
        payload: $payload,
        client: $client,
        token: $token,
        user: $user,
    ))->toThrow(AuthorizationException::class, 'The Agent Bridge confirmation token is invalid or expired.');
});

it('rejects confirmation when the payload changes after preview', function (): void {
    registerFakeCapability();

    $user = User::query()->create([
        'name' => 'Test User',
        'email' => 'changed@example.com',
        'password' => 'secret',
    ]);

    $created = CreateAgentBridgeTokenAction::run($user, 'Test client', ['capell.fake.write']);
    $token = agentBridgeCreatedToken($created);
    $client = new AuthenticatedAgentBridgeClientData(
        tokenId: agentBridgeTokenId($token),
        name: 'Test client',
        scopes: ['capell.fake.write'],
    );

    $preview = InvokeAgentBridgeCapabilityPreviewAction::run(
        capabilityKey: 'capell.fake.write',
        payload: ['name' => 'Original'],
        client: $client,
        token: $token,
        user: $user,
    );

    ConfirmAgentBridgeCapabilityAction::run(
        confirmationToken: agentBridgeConfirmationToken($preview),
        payload: ['name' => 'Changed'],
        client: $client,
        token: $token,
        user: $user,
    );
})->throws(AuthorizationException::class, 'The Agent Bridge confirmation payload has changed.');

it('redacts sensitive payload and result fragments before writing audit entries', function (): void {
    // Medium risk is confirmed by the token alone, so the trail is exactly preview and run.
    registerFakeCapability(risk: CapabilityRiskEnum::Medium);

    $user = User::query()->create([
        'name' => 'Audit User',
        'email' => 'audit-redaction@example.com',
        'password' => 'secret',
    ]);

    $created = CreateAgentBridgeTokenAction::run($user, 'Audit client', ['capell.fake.write']);
    $token = agentBridgeCreatedToken($created);
    $client = new AuthenticatedAgentBridgeClientData(
        tokenId: agentBridgeTokenId($token),
        name: 'Audit client',
        scopes: ['capell.fake.write'],
    );

    $payload = [
        'name' => 'Example',
        'tokenId' => agentBridgeTokenId($token),
        'accessToken' => 'secret-access-token',
        'password' => 'secret-password',
        'authorization' => 'Bearer secret-header-token',
        'adminUrl' => 'https://example.test/admin/pages/1/edit?expires=123&signature=abc',
        'prompt' => [
            'is_private' => true,
            'prompt' => 'Private launch instructions',
        ],
    ];

    $preview = InvokeAgentBridgeCapabilityPreviewAction::run(
        capabilityKey: 'capell.fake.write',
        payload: $payload,
        client: $client,
        token: $token,
        user: $user,
    );

    $result = ConfirmAgentBridgeCapabilityAction::run(
        confirmationToken: agentBridgeConfirmationToken($preview),
        payload: $payload,
        client: $client,
        token: $token,
        user: $user,
    );

    $resultPayload = data_get($result, 'result.data.payload');

    throw_unless(is_array($resultPayload), RuntimeException::class, 'Expected agent bridge result payload array.');

    expect($resultPayload['accessToken'] ?? null)->toBe('secret-access-token');

    $auditEntries = CapellAgentBridgeAuditEntry::query()
        ->orderBy('id')
        ->get();

    expect($auditEntries)->toHaveCount(2);

    foreach ($auditEntries as $auditEntry) {
        $auditResultPayload = data_get($auditEntry->result, 'data.payload');

        throw_unless(is_array($auditResultPayload), RuntimeException::class, 'Expected redacted audit result payload array.');

        expect($auditEntry->payload)
            ->toMatchArray([
                'name' => 'Example',
                'tokenId' => agentBridgeTokenId($token),
                'accessToken' => '[redacted]',
                'password' => '[redacted]',
                'authorization' => '[redacted]',
                'adminUrl' => '[redacted]',
                'prompt' => ['[redacted]'],
            ])
            ->and($auditResultPayload['accessToken'] ?? null)->toBe('[redacted]')
            ->and($auditResultPayload['password'] ?? null)->toBe('[redacted]')
            ->and($auditResultPayload['authorization'] ?? null)->toBe('[redacted]')
            ->and($auditResultPayload['adminUrl'] ?? null)->toBe('[redacted]')
            ->and($auditResultPayload['prompt'] ?? null)->toBe(['[redacted]']);
    }
});

it('rejects confirmation replay across users', function (): void {
    registerFakeCapability();

    $user = User::query()->create([
        'name' => 'Original User',
        'email' => 'original-confirm@example.com',
        'password' => 'secret',
    ]);
    $otherUser = User::query()->create([
        'name' => 'Other User',
        'email' => 'other-confirm@example.com',
        'password' => 'secret',
    ]);

    $created = CreateAgentBridgeTokenAction::run($user, 'Original client', ['capell.fake.write']);
    $token = agentBridgeCreatedToken($created);
    $client = new AuthenticatedAgentBridgeClientData(
        tokenId: agentBridgeTokenId($token),
        name: 'Original client',
        scopes: ['capell.fake.write'],
    );

    $preview = InvokeAgentBridgeCapabilityPreviewAction::run(
        capabilityKey: 'capell.fake.write',
        payload: ['name' => 'Original'],
        client: $client,
        token: $token,
        user: $user,
    );

    ConfirmAgentBridgeCapabilityAction::run(
        confirmationToken: agentBridgeConfirmationToken($preview),
        payload: ['name' => 'Original'],
        client: $client,
        token: $token,
        user: $otherUser,
    );
})->throws(AuthorizationException::class, 'The Agent Bridge confirmation token does not belong to this user.');

it('rejects confirmation replay across non numeric user identifiers', function (): void {
    registerFakeCapability();

    $tokenOwner = User::query()->create([
        'name' => 'Token Owner',
        'email' => 'uuid-owner@example.com',
        'password' => 'secret',
    ]);

    $created = CreateAgentBridgeTokenAction::run($tokenOwner, 'UUID client', ['capell.fake.write']);
    $token = agentBridgeCreatedToken($created);
    $client = new AuthenticatedAgentBridgeClientData(
        tokenId: agentBridgeTokenId($token),
        name: 'UUID client',
        scopes: ['capell.fake.write'],
    );

    $payload = ['name' => 'Original'];
    $confirmation = new CapellAgentBridgeConfirmation;
    $confirmation->forceFill([
        'token' => CapellAgentBridgeConfirmation::hashPlainTextToken('uuid-confirmation-token'),
        'agent_bridge_token_id' => $token->getKey(),
        'user_type' => (new User)->getMorphClass(),
        'user_id' => 'user-alpha',
        'capability_key' => 'capell.fake.write',
        'scope' => 'capell.fake.write',
        'payload_hash' => InvokeAgentBridgeCapabilityPreviewAction::payloadHash($payload),
        'payload' => $payload,
        'preview' => ['message' => 'Preview'],
        'expires_at' => now()->addMinutes(5),
    ]);
    $confirmation->save();

    $otherUser = new User;
    $otherUser->forceFill(['id' => 'user-beta']);

    ConfirmAgentBridgeCapabilityAction::run(
        confirmationToken: 'uuid-confirmation-token',
        payload: $payload,
        client: $client,
        token: $token,
        user: $otherUser,
    );
})->throws(AuthorizationException::class, 'The Agent Bridge confirmation token does not belong to this user.');

it('rejects policy protected capability previews when no authenticated user is available', function (): void {
    registerFakeCapability(policyAbility: 'preview fake capability');

    Gate::define('preview fake capability', static fn (User $user): bool => true);

    $created = CreateAgentBridgeTokenAction::run(User::query()->create([
        'name' => 'Token Owner',
        'email' => 'owner@example.com',
        'password' => 'secret',
    ]), 'Test client', ['capell.fake.write']);
    $token = agentBridgeCreatedToken($created);
    $client = new AuthenticatedAgentBridgeClientData(
        tokenId: agentBridgeTokenId($token),
        name: 'Test client',
        scopes: ['capell.fake.write'],
    );

    InvokeAgentBridgeCapabilityPreviewAction::run(
        capabilityKey: 'capell.fake.write',
        payload: ['name' => 'Original'],
        client: $client,
        token: $token,
    );
})->throws(AuthorizationException::class, 'Agent Bridge policy ability [preview fake capability] requires an authenticated user.');

dataset('agent bridge token changes between preview and confirm', [
    'revoked' => [fn (CapellAgentBridgeToken $token): mixed => CapellAgentBridgeToken::query()->whereKey($token->getKey())->update(['is_enabled' => false, 'revoked_at' => now()])],
    'disabled' => [fn (CapellAgentBridgeToken $token): mixed => CapellAgentBridgeToken::query()->whereKey($token->getKey())->update(['is_enabled' => false])],
    'expired' => [fn (CapellAgentBridgeToken $token): mixed => CapellAgentBridgeToken::query()->whereKey($token->getKey())->update(['expires_at' => now()->subMinute()])],
    'de-scoped' => [fn (CapellAgentBridgeToken $token): mixed => CapellAgentBridgeToken::query()->whereKey($token->getKey())->update(['scopes' => json_encode(['capell.pages.read'], JSON_THROW_ON_ERROR)])],
    'reassigned' => [function (CapellAgentBridgeToken $token): mixed {
        $otherUser = User::query()->create(['name' => 'New owner', 'email' => 'reassigned-owner@example.com', 'password' => 'secret']);

        return CapellAgentBridgeToken::query()->whereKey($token->getKey())->update(['user_id' => $otherUser->getKey()]);
    }],
]);

it('refuses confirmation when the token changed after the preview was issued', function (Closure $change): void {
    registerFakeCapability();

    $user = User::query()->create([
        'name' => 'Stale Credential User',
        'email' => 'stale-credential@example.com',
        'password' => 'secret',
    ]);

    $token = agentBridgeCreatedToken(CreateAgentBridgeTokenAction::run($user, 'Stale client', ['capell.fake.write']));
    $client = new AuthenticatedAgentBridgeClientData(
        tokenId: agentBridgeTokenId($token),
        name: 'Stale client',
        scopes: ['capell.fake.write'],
    );

    $preview = InvokeAgentBridgeCapabilityPreviewAction::run(
        capabilityKey: 'capell.fake.write',
        payload: ['name' => 'Stale'],
        client: $client,
        token: $token,
        user: $user,
    );

    $change($token);

    expect(fn (): array => ConfirmAgentBridgeCapabilityAction::run(
        confirmationToken: agentBridgeConfirmationToken($preview),
        payload: ['name' => 'Stale'],
        client: $client,
        token: $token,
        user: $user,
    ))->toThrow(AuthorizationException::class, 'The Agent Bridge token is no longer allowed to use this capability.');

    expect(CapellAgentBridgeConfirmation::query()->whereNotNull('used_at')->count())->toBe(0)
        ->and(CapellAgentBridgeAuditEntry::query()->where('event', 'capell_agent-bridge.fake.write')->count())->toBe(0);
})->with('agent bridge token changes between preview and confirm');

it('stops a long-lived client from running a capability without confirmation once its token is revoked', function (): void {
    resolve(CapellAgentBridgeCapabilityRegistry::class)->register(new CapabilityData(
        key: 'capell.fake.read_now',
        name: 'Fake read',
        description: 'Fake read capability that runs without confirmation.',
        scope: 'capell.fake.write',
        server: CapabilityServerEnum::Site,
        risk: CapabilityRiskEnum::Read,
        actionClass: FakeCapabilityAction::class,
        requiresConfirmation: false,
    ));

    $user = User::query()->create(['name' => 'Stdio user', 'email' => 'stdio-user@example.com', 'password' => 'secret']);
    $token = agentBridgeCreatedToken(CreateAgentBridgeTokenAction::run($user, 'Stdio client', ['capell.fake.write']));
    $client = new AuthenticatedAgentBridgeClientData(tokenId: agentBridgeTokenId($token), name: 'Stdio client', scopes: ['capell.fake.write']);
    $invoke = fn (): array => InvokeAgentBridgeCapabilityPreviewAction::run(
        capabilityKey: 'capell.fake.read_now',
        payload: ['name' => 'Low'],
        client: $client,
        token: $token,
        user: $user,
    );

    expect($invoke()['mode'])->toBe('executed');

    CapellAgentBridgeToken::query()->whereKey($token->getKey())->update(['is_enabled' => false, 'revoked_at' => now()]);

    expect($invoke)->toThrow(AuthorizationException::class, 'The Agent Bridge token is no longer allowed to use this capability.');
    expect(CapellAgentBridgeAuditEntry::query()->where('event', 'capell_agent-bridge.capability.executed')->count())->toBe(1);
});

function registerFakeImmediateCapability(?string $policyAbility = null): void
{
    resolve(CapellAgentBridgeCapabilityRegistry::class)->register(new CapabilityData(
        key: 'capell.fake.read_now',
        name: 'Fake read',
        description: 'Fake read capability that runs without confirmation.',
        scope: 'capell.fake.write',
        server: CapabilityServerEnum::Site,
        risk: CapabilityRiskEnum::Read,
        actionClass: FakeCapabilityAction::class,
        policyAbility: $policyAbility,
        requiresConfirmation: false,
    ));
}

it('runs an internal client inside a token request without borrowing that request token', function (): void {
    registerFakeImmediateCapability();
    registerFakeCapability();
    $requestOwner = User::query()->create(['name' => 'Request owner', 'email' => 'request-owner@example.com', 'password' => 'secret']);
    $requestToken = agentBridgeCreatedToken(CreateAgentBridgeTokenAction::run($requestOwner, 'Request client', ['capell.pages.read']));
    // The surrounding bridge request authenticated with its own token.
    app()->instance(CapellAgentBridgeToken::class, $requestToken);
    $internalClient = new AuthenticatedAgentBridgeClientData(tokenId: 0, name: 'Automation Studio', scopes: ['capell.fake.write']);

    $executed = InvokeAgentBridgeCapabilityPreviewAction::run(
        capabilityKey: 'capell.fake.read_now',
        payload: ['name' => 'Internal'],
        client: $internalClient,
        token: null,
        user: $requestOwner,
    );

    expect($executed['mode'])->toBe('executed')
        ->and(fn (): array => InvokeAgentBridgeCapabilityPreviewAction::run(
            capabilityKey: 'capell.fake.write',
            payload: ['name' => 'Internal'],
            client: $internalClient,
            token: null,
            user: $requestOwner,
        ))->toThrow(AuthorizationException::class, 'Agent Bridge capability [capell.fake.write] needs confirmation, which requires a token client.');

    expect(CapellAgentBridgeConfirmation::query()->count())->toBe(0);
});

it('refuses a token client whose token is missing rather than skipping the freshness check', function (): void {
    registerFakeImmediateCapability();
    $user = User::query()->create(['name' => 'Tokenless user', 'email' => 'tokenless@example.com', 'password' => 'secret']);
    $token = agentBridgeCreatedToken(CreateAgentBridgeTokenAction::run($user, 'Tokenless client', ['capell.fake.write']));
    $client = new AuthenticatedAgentBridgeClientData(tokenId: agentBridgeTokenId($token), name: 'Tokenless client', scopes: ['capell.fake.write']);

    expect(fn (): array => InvokeAgentBridgeCapabilityPreviewAction::run(
        capabilityKey: 'capell.fake.read_now',
        payload: ['name' => 'Tokenless'],
        client: $client,
        token: null,
        user: $user,
    ))->toThrow(AuthorizationException::class, 'Agent Bridge token context is required.');

    expect(CapellAgentBridgeAuditEntry::query()->where('event', 'capell_agent-bridge.capability.executed')->count())->toBe(0);
});

it('judges a long-lived client by its token owner as currently stored', function (): void {
    registerFakeImmediateCapability(policyAbility: 'use fake capability');
    // Stands in for a role check: it reads the owner's stored state.
    Gate::define('use fake capability', static fn (User $user): bool => $user->name === 'Allowed owner');
    $user = User::query()->create(['name' => 'Allowed owner', 'email' => 'stdio-owner@example.com', 'password' => 'secret']);
    $token = agentBridgeCreatedToken(CreateAgentBridgeTokenAction::run($user, 'Stdio client', ['capell.fake.write']));
    $client = new AuthenticatedAgentBridgeClientData(tokenId: agentBridgeTokenId($token), name: 'Stdio client', scopes: ['capell.fake.write']);
    // The stdio process keeps the owner it loaded when it started.
    $invoke = fn (): array => InvokeAgentBridgeCapabilityPreviewAction::run(
        capabilityKey: 'capell.fake.read_now',
        payload: ['name' => 'Owner'],
        client: $client,
        token: $token,
        user: $user,
    );

    expect($invoke()['mode'])->toBe('executed');

    User::query()->whereKey($user->getKey())->update(['name' => 'Demoted owner']);

    expect($invoke)->toThrow(AuthorizationException::class, 'This action is unauthorized.');

    User::query()->whereKey($user->getKey())->delete();

    expect($invoke)->toThrow(AuthorizationException::class, 'The Agent Bridge token owner no longer exists.')
        ->and(CapellAgentBridgeAuditEntry::query()->where('event', 'capell_agent-bridge.capability.executed')->count())->toBe(1);
});

it('confirms as the token owner currently stored', function (): void {
    registerFakeCapability(policyAbility: 'confirm fake capability');
    Gate::define('confirm fake capability', static fn (User $user): bool => $user->name === 'Allowed owner');
    $user = User::query()->create(['name' => 'Allowed owner', 'email' => 'confirm-owner@example.com', 'password' => 'secret']);
    $token = agentBridgeCreatedToken(CreateAgentBridgeTokenAction::run($user, 'Confirm client', ['capell.fake.write']));
    $client = new AuthenticatedAgentBridgeClientData(tokenId: agentBridgeTokenId($token), name: 'Confirm client', scopes: ['capell.fake.write']);
    $preview = InvokeAgentBridgeCapabilityPreviewAction::run(
        capabilityKey: 'capell.fake.write',
        payload: ['name' => 'Owner'],
        client: $client,
        token: $token,
        user: $user,
    );

    User::query()->whereKey($user->getKey())->update(['name' => 'Demoted owner']);

    expect(fn (): array => ConfirmAgentBridgeCapabilityAction::run(
        confirmationToken: agentBridgeConfirmationToken($preview),
        payload: ['name' => 'Owner'],
        client: $client,
        token: $token,
        user: $user,
    ))->toThrow(AuthorizationException::class, 'This action is unauthorized.');

    expect(CapellAgentBridgeConfirmation::query()->whereNotNull('used_at')->count())->toBe(0);
});

it('checks a token handed to an internal client instead of recording a confirmation for it', function (): void {
    registerFakeCapability();
    $user = User::query()->create(['name' => 'Handed token owner', 'email' => 'handed-token@example.com', 'password' => 'secret']);
    $token = agentBridgeCreatedToken(CreateAgentBridgeTokenAction::run($user, 'Handed client', ['capell.fake.write']));
    $internalClient = new AuthenticatedAgentBridgeClientData(tokenId: 0, name: 'Automation Studio', scopes: ['capell.fake.write']);

    expect(fn (): array => InvokeAgentBridgeCapabilityPreviewAction::run(
        capabilityKey: 'capell.fake.write',
        payload: ['name' => 'Handed'],
        client: $internalClient,
        token: $token,
        user: $user,
    ))->toThrow(AuthorizationException::class, 'The Agent Bridge token is no longer allowed to use this capability.');

    expect(CapellAgentBridgeConfirmation::query()->count())->toBe(0);
});

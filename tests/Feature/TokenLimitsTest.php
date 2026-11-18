<?php

declare(strict_types=1);

use Capell\Admin\Filament\Resources\Users\Pages\EditUser;
use Capell\AgentBridge\Actions\ConfirmAgentBridgeCapabilityAction;
use Capell\AgentBridge\Actions\CreateAgentBridgeTokenAction;
use Capell\AgentBridge\Actions\InvokeAgentBridgeCapabilityPreviewAction;
use Capell\AgentBridge\Actions\SuggestAgentBridgeCapabilitiesAction;
use Capell\AgentBridge\Contracts\CapellAgentBridgeCapabilityAction;
use Capell\AgentBridge\Data\AuthenticatedAgentBridgeClientData;
use Capell\AgentBridge\Data\CapabilityData;
use Capell\AgentBridge\Enums\CapabilityRiskEnum;
use Capell\AgentBridge\Enums\CapabilityServerEnum;
use Capell\AgentBridge\Filament\Resources\Users\RelationManagers\AgentBridgeTokensRelationManager;
use Capell\AgentBridge\Http\Middleware\ThrottleAgentBridgeRequests;
use Capell\AgentBridge\Http\Middleware\ThrottleAgentBridgeRequestsByIp;
use Capell\AgentBridge\Models\CapellAgentBridgeToken;
use Capell\AgentBridge\Support\AgentBridgePageAccess;
use Capell\AgentBridge\Support\AgentBridgeTokenSiteRestriction;
use Capell\AgentBridge\Support\CapellAgentBridgeCapabilityRegistry;
use Capell\AgentBridge\Tests\Fixtures\FakeCapabilityAction;
use Capell\AgentBridge\Tests\Fixtures\SiteScopedFakeCapabilityAction;
use Capell\AgentBridge\Tests\Fixtures\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/** @param  class-string<CapellAgentBridgeCapabilityAction>  $actionClass */
function tokenLimitsCapability(string $key, string $actionClass = FakeCapabilityAction::class): void
{
    resolve(CapellAgentBridgeCapabilityRegistry::class)->register(new CapabilityData(
        key: $key,
        name: 'Fake ' . $key,
        description: 'Fake capability for token limits.',
        scope: 'capell.fake.limits',
        server: CapabilityServerEnum::Site,
        risk: CapabilityRiskEnum::Medium,
        actionClass: $actionClass,
    ));
}

function tokenLimitsUser(string $email): User
{
    return User::query()->create(['name' => $email, 'email' => $email, 'password' => 'secret']);
}

/**
 * @param  list<int>|null  $siteIds
 * @param  list<string>|null  $capabilityKeys
 * @return array{0: CapellAgentBridgeToken, 1: AuthenticatedAgentBridgeClientData}
 */
function tokenLimitsToken(User $owner, ?array $siteIds = null, ?array $capabilityKeys = null, ?int $quota = null): array
{
    $token = CreateAgentBridgeTokenAction::run($owner, 'Limited', ['capell.fake.limits'], null, null, $siteIds, $capabilityKeys, $quota)['token'];

    return [$token, AuthenticatedAgentBridgeClientData::fromToken($token)];
}

/** @param  array<string, mixed>  $preview */
function tokenLimitsConfirmationToken(array $preview): string
{
    $confirmationToken = $preview['confirmationToken'] ?? null;

    throw_unless(is_string($confirmationToken), RuntimeException::class, 'Expected a confirmation token.');

    return $confirmationToken;
}

it('refuses a capability the token is not limited to, even within its scopes', function (): void {
    tokenLimitsCapability('capell.fake.allowed');
    tokenLimitsCapability('capell.fake.other');
    $owner = tokenLimitsUser('capability-limit@example.test');
    [$token, $client] = tokenLimitsToken($owner, capabilityKeys: ['capell.fake.allowed']);

    expect(InvokeAgentBridgeCapabilityPreviewAction::run('capell.fake.allowed', ['name' => 'Fine'], $client, $token, $owner)['mode'])->toBe('preview')
        ->and(fn (): array => InvokeAgentBridgeCapabilityPreviewAction::run('capell.fake.other', ['name' => 'No'], $client, $token, $owner))
        ->toThrow(AuthorizationException::class, 'This Agent Bridge token is not allowed to use capability [capell.fake.other].');

    // A client built before the limit was added is still held to the token as stored.
    $staleClient = new AuthenticatedAgentBridgeClientData(tokenId: $token->id, name: 'Limited', scopes: ['capell.fake.limits']);

    expect(fn (): array => InvokeAgentBridgeCapabilityPreviewAction::run('capell.fake.other', ['name' => 'No'], $staleClient, $token, $owner))
        ->toThrow(AuthorizationException::class, 'The Agent Bridge token is no longer allowed to use this capability.');
});

it('refuses to confirm once the capability is taken off the token after the preview', function (): void {
    tokenLimitsCapability('capell.fake.allowed');
    $owner = tokenLimitsUser('narrowed@example.test');
    [$token] = tokenLimitsToken($owner);
    $client = new AuthenticatedAgentBridgeClientData(tokenId: $token->id, name: 'Limited', scopes: ['capell.fake.limits']);
    $preview = InvokeAgentBridgeCapabilityPreviewAction::run('capell.fake.allowed', ['name' => 'Fine'], $client, $token, $owner);

    CapellAgentBridgeToken::query()->whereKey($token->id)->update(['capability_keys' => json_encode(['capell.fake.something_else'])]);

    expect(fn (): array => ConfirmAgentBridgeCapabilityAction::run(tokenLimitsConfirmationToken($preview), ['name' => 'Fine'], $client, $token, $owner))
        ->toThrow(AuthorizationException::class, 'The Agent Bridge token is no longer allowed to use this capability.');
});

it('only lists and suggests the capabilities a token is limited to', function (): void {
    tokenLimitsCapability('capell.fake.allowed');
    tokenLimitsCapability('capell.fake.other');

    $suggested = SuggestAgentBridgeCapabilitiesAction::run(
        query: '',
        server: CapabilityServerEnum::Site,
        scopes: ['capell.fake.limits'],
        limit: 20,
        capabilityKeys: ['capell.fake.allowed'],
    );

    expect(array_column($suggested['suggestions'], 'key'))->toBe(['capell.fake.allowed']);
});

it('keeps a site-limited token to its sites while the capability runs, then lifts the limit', function (): void {
    tokenLimitsCapability('capell.fake.site_write', SiteScopedFakeCapabilityAction::class);
    $owner = tokenLimitsUser('site-limit@example.test');
    $owner->globalAdmin = true;
    [$token, $client] = tokenLimitsToken($owner, siteIds: [7]);

    $allowed = InvokeAgentBridgeCapabilityPreviewAction::run('capell.fake.site_write', ['site_id' => 7], $client, $token, $owner);

    expect($allowed['mode'])->toBe('preview')
        ->and(fn (): array => InvokeAgentBridgeCapabilityPreviewAction::run('capell.fake.site_write', ['site_id' => 8], $client, $token, $owner))
        ->toThrow(ValidationException::class)
        ->and(AgentBridgeTokenSiteRestriction::current())->toBeNull();

    // Narrowed after the preview: the confirmation runs under the token as stored now.
    CapellAgentBridgeToken::query()->whereKey($token->id)->update(['site_ids' => json_encode([9])]);

    expect(fn (): array => ConfirmAgentBridgeCapabilityAction::run(tokenLimitsConfirmationToken($allowed), ['site_id' => 7], $client, $token, $owner))
        ->toThrow(ValidationException::class);
});

it('refuses to run a capability for a token deleted after it authenticated, instead of lifting its site limit', function (): void {
    $owner = tokenLimitsUser('deleted-token@example.test');
    $owner->globalAdmin = true;
    [$token] = tokenLimitsToken($owner, siteIds: [7]);
    $ran = false;

    CapellAgentBridgeToken::query()->whereKey($token->id)->delete();

    expect(function () use ($token, &$ran): void {
        AgentBridgeTokenSiteRestriction::within($token, function () use (&$ran): void {
            $ran = true;
        });
    })->toThrow(AuthorizationException::class, 'The Agent Bridge token is no longer valid.')
        ->and($ran)->toBeFalse()
        ->and(AgentBridgeTokenSiteRestriction::current())->toBeNull();
});

it('refuses to create a token limited to a site its owner cannot reach or a capability that does not exist', function (): void {
    tokenLimitsCapability('capell.fake.allowed');
    $owner = tokenLimitsUser('unreachable@example.test');

    // An owner without site access must not end up with an unlimited token instead.
    expect(fn (): array => CreateAgentBridgeTokenAction::run($owner, 'Unreachable', ['*'], null, null, [42]))
        ->toThrow(ValidationException::class)
        ->and(fn (): array => CreateAgentBridgeTokenAction::run($owner, 'Unknown', ['*'], null, null, null, ['capell.fake.allowed', 'capell.not.registered']))
        ->toThrow(ValidationException::class)
        ->and(CapellAgentBridgeToken::query()->whereIn('name', ['Unreachable', 'Unknown'])->exists())->toBeFalse();
});

it('narrows even a global administrator to the token sites, and hides pages outside every site', function (): void {
    $administrator = tokenLimitsUser('global-admin@example.test');
    $administrator->globalAdmin = true;

    $token = CreateAgentBridgeTokenAction::run($administrator, 'Limited', ['*'], null, null, [3, 4])['token'];

    expect(AgentBridgePageAccess::authorizedSiteIds($administrator))->toBeNull();

    AgentBridgeTokenSiteRestriction::within($token, function () use ($administrator): void {
        expect(AgentBridgePageAccess::authorizedSiteIds($administrator))->toBe([3, 4])
            ->and(fn () => AgentBridgePageAccess::authorizeSite($administrator, 5))->toThrow(ValidationException::class);

        AgentBridgePageAccess::authorizeSite($administrator, 4);
    });
});

it('applies the stricter of the token quota and the site-wide limit', function (): void {
    $owner = tokenLimitsUser('quota@example.test');
    [$token] = tokenLimitsToken($owner, quota: 2);
    config()->set('capell-agent-bridge.rate_limit_per_minute', 60);

    expect(ThrottleAgentBridgeRequests::maxAttemptsFor($token))->toBe(2);

    config()->set('capell-agent-bridge.rate_limit_enabled', false);

    expect(ThrottleAgentBridgeRequests::maxAttemptsFor($token))->toBe(2);

    app()->instance(CapellAgentBridgeToken::class, $token);
    $middleware = new ThrottleAgentBridgeRequests;
    $statuses = [];

    foreach (range(1, 3) as $attempt) {
        $statuses[] = $middleware->handle(Request::create('/agent-bridge/capell'), fn (Request $request): Response => response('ok'))->getStatusCode();
    }

    expect($statuses)->toBe([200, 200, 429])
        ->and(fn (): array => CreateAgentBridgeTokenAction::run($owner, 'Zero', ['*'], null, null, null, null, 0))
        ->toThrow(InvalidArgumentException::class);
});

it('refuses a request once a concurrent one has used the last of the quota', function (): void {
    $owner = tokenLimitsUser('quota-race@example.test');
    [$token] = tokenLimitsToken($owner, quota: 2);
    app()->instance(CapellAgentBridgeToken::class, $token);

    // Another request for the same token is counted between this request's check and
    // its own count, which the counter's atomic increment reports as the third hit.
    $limiter = RateLimiter::partialMock();
    $limiter->shouldReceive('hit')->once()->andReturn(3);
    $limiter->shouldReceive('availableIn')->andReturn(30);
    $passedThrough = false;

    $response = (new ThrottleAgentBridgeRequests)->handle(Request::create('/agent-bridge/capell'), function (Request $request) use (&$passedThrough): Response {
        $passedThrough = true;

        return response('ok');
    });

    expect($response->getStatusCode())->toBe(429)
        ->and($passedThrough)->toBeFalse();
});

it('refuses an address once a concurrent request has used the last of its allowance', function (): void {
    config()->set('capell-agent-bridge.rate_limit_per_ip_per_minute', 2);
    $limiter = RateLimiter::partialMock();
    $limiter->shouldReceive('hit')->once()->andReturn(3);
    $limiter->shouldReceive('availableIn')->andReturn(30);
    $passedThrough = false;

    $response = (new ThrottleAgentBridgeRequestsByIp)->handle(Request::create('/agent-bridge/capell'), function (Request $request) use (&$passedThrough): Response {
        $passedThrough = true;

        return response('ok');
    });

    expect($response->getStatusCode())->toBe(429)
        ->and($passedThrough)->toBeFalse();
});

it('counts each address request once and refuses the one past its allowance', function (): void {
    config()->set('capell-agent-bridge.rate_limit_per_ip_per_minute', 2);
    $middleware = new ThrottleAgentBridgeRequestsByIp;
    $statuses = [];

    foreach (range(1, 3) as $attempt) {
        $statuses[] = $middleware->handle(Request::create('/agent-bridge/capell', server: ['REMOTE_ADDR' => '203.0.113.9']), fn (Request $request): Response => response('ok'))->getStatusCode();
    }

    expect($statuses)->toBe([200, 200, 429]);
});

it('creates a limited token from the user page and refuses sites or capabilities out of reach', function (): void {
    tokenLimitsCapability('capell.fake.allowed');
    $panel = Panel::make()->id('admin')->path('admin')->default();
    Filament::registerPanel($panel);
    Filament::setCurrentPanel($panel);
    Filament::bootCurrentPanel();
    Filament::setServingStatus();
    $owner = tokenLimitsUser('form-owner@example.test');
    $this->actingAs($owner);

    // A site the owner cannot reach, a capability that is not installed, or one a token
    // can never run because it is outside the MCP v1 contract, is refused.
    Livewire::test(AgentBridgeTokensRelationManager::class, ['ownerRecord' => $owner, 'pageClass' => EditUser::class])
        ->callAction(TestAction::make('createToken')->table(), [
            'name' => 'Too wide',
            'scopes' => ['capell.pages.read'],
            'site_ids' => [42],
            'capability_keys' => ['capell.not.registered'],
        ])
        ->assertHasActionErrors(['site_ids.0', 'capability_keys.0']);

    Livewire::test(AgentBridgeTokensRelationManager::class, ['ownerRecord' => $owner, 'pageClass' => EditUser::class])
        ->callAction(TestAction::make('createToken')->table(), [
            'name' => 'Never runnable',
            'scopes' => ['capell.pages.read'],
            'capability_keys' => ['capell.fake.allowed'],
        ])
        ->assertHasActionErrors(['capability_keys.0']);

    Livewire::test(AgentBridgeTokensRelationManager::class, ['ownerRecord' => $owner, 'pageClass' => EditUser::class])
        ->callAction(TestAction::make('createToken')->table(), [
            'name' => 'Limited assistant',
            'scopes' => ['capell.pages.read'],
            'capability_keys' => ['capell.pages.search'],
            'requests_per_minute' => 5,
        ])
        ->assertHasNoActionErrors();

    $token = CapellAgentBridgeToken::query()->where('name', 'Limited assistant')->firstOrFail();

    expect(CapellAgentBridgeToken::query()->whereIn('name', ['Too wide', 'Never runnable'])->exists())->toBeFalse()
        ->and($token->site_ids)->toBeNull()
        ->and($token->capability_keys)->toBe(['capell.pages.search'])
        ->and($token->requests_per_minute)->toBe(5);
});

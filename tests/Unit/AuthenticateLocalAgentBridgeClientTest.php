<?php

declare(strict_types=1);

use Capell\AgentBridge\Actions\AuthenticateLocalAgentBridgeClientAction;
use Capell\AgentBridge\Actions\CreateAgentBridgeTokenAction;
use Capell\AgentBridge\Actions\RevokeAgentBridgeTokenAction;
use Capell\AgentBridge\Actions\RotateAgentBridgeTokenAction;
use Capell\AgentBridge\Data\AuthenticatedAgentBridgeClientData;
use Capell\AgentBridge\Models\CapellAgentBridgeToken;
use Capell\AgentBridge\Tests\Fixtures\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;

it('authenticates the local client as its token owner and binds its granted context', function (): void {
    $user = User::query()->create(['name' => 'Local user', 'email' => 'local-use@example.test', 'password' => 'secret']);
    $created = CreateAgentBridgeTokenAction::run($user, 'Local client', ['capell.pages.read']);
    config()->set('auth.guards.local-agent', ['driver' => 'session', 'provider' => 'users']);
    config()->set('capell-agent-bridge.site_auth_guard', 'local-agent');
    config()->set('capell-agent-bridge.local.token', $created['plainTextToken']);

    expect($created['token']->fresh()?->last_used_at)->toBeNull()
        ->and(Auth::guard('local-agent')->guest())->toBeTrue();

    AuthenticateLocalAgentBridgeClientAction::run();

    $client = resolve(AuthenticatedAgentBridgeClientData::class);

    expect($created['token']->fresh()?->last_used_at)->not->toBeNull()
        ->and(Auth::getDefaultDriver())->toBe('local-agent')
        ->and(Auth::user())->toBeInstanceOf(User::class)
        ->and(Auth::id())->toBe($user->getAuthIdentifier())
        ->and(resolve(CapellAgentBridgeToken::class)->is($created['token']))->toBeTrue()
        ->and($client->tokenId)->toBe($created['token']->getKey())
        ->and($client->name)->toBe('Local client')
        ->and($client->scopes)->toBe(['capell.pages.read'])
        ->and($client->can('capell.pages.read'))->toBeTrue()
        ->and($client->can('capell.pages.write'))->toBeFalse();
});

it('rejects invalid local credentials with the same denial and no authenticated context', function (string $credential): void {
    $user = User::query()->create(['name' => 'Local user', 'email' => 'local-denied@example.test', 'password' => 'secret']);
    $created = CreateAgentBridgeTokenAction::run($user, 'Local client', ['capell.pages.read']);
    $plainTextToken = $created['plainTextToken'];
    $rotated = null;

    if ($credential === 'unknown') {
        $plainTextToken = CapellAgentBridgeToken::generatePlainTextToken();
    } elseif ($credential === 'revoked') {
        RevokeAgentBridgeTokenAction::run($created['token']);
    } elseif ($credential === 'rotated') {
        $rotated = RotateAgentBridgeTokenAction::run($created['token'], $user);
    }

    config()->set('capell-agent-bridge.local.token', $plainTextToken);

    try {
        AuthenticateLocalAgentBridgeClientAction::run();
        $this->fail('Invalid local credentials must be denied.');
    } catch (AuthorizationException $exception) {
        expect($exception->getMessage())->toBe(__('capell-agent-bridge::messages.mcp_v1.denied'))
            ->and($exception->getCode())->toBe(0)
            ->and($exception->status())->toBeNull()
            ->and($exception->toResponse()->toArray())->toBe([
                'allowed' => false,
                'message' => __('capell-agent-bridge::messages.mcp_v1.denied'),
                'code' => 0,
            ]);
    }

    expect(Auth::guest())->toBeTrue()
        ->and(app()->bound(AuthenticatedAgentBridgeClientData::class))->toBeFalse()
        ->and(app()->bound(CapellAgentBridgeToken::class))->toBeFalse()
        ->and($created['token']->fresh()?->last_used_at)->toBeNull();

    if ($rotated !== null) {
        config()->set('capell-agent-bridge.local.token', $rotated['plainTextToken']);

        AuthenticateLocalAgentBridgeClientAction::run();

        expect(Auth::id())->toBe($user->getAuthIdentifier())
            ->and(resolve(AuthenticatedAgentBridgeClientData::class)->tokenId)->toBe($created['token']->getKey());
    }
})->with(['unknown', 'revoked', 'rotated']);

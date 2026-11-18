<?php

declare(strict_types=1);

use Capell\AgentBridge\Actions\AuthenticateLocalAgentBridgeClientAction;
use Capell\AgentBridge\Actions\CreateAgentBridgeTokenAction;
use Capell\AgentBridge\Actions\RevokeAgentBridgeTokenAction;
use Capell\AgentBridge\Data\AuthenticatedAgentBridgeClientData;
use Capell\AgentBridge\Models\CapellAgentBridgeToken;
use Capell\AgentBridge\Tests\Fixtures\User;
use Illuminate\Auth\Access\AuthorizationException;

it('stamps last use when the local transport authenticates a token', function (): void {
    $user = User::query()->create(['name' => 'Local user', 'email' => 'local-use@example.test', 'password' => 'secret']);
    $created = CreateAgentBridgeTokenAction::run($user, 'Local client', ['capell.pages.read']);
    config()->set('capell-agent-bridge.local.token', $created['plainTextToken']);

    expect($created['token']->fresh()?->last_used_at)->toBeNull();

    AuthenticateLocalAgentBridgeClientAction::run();

    expect($created['token']->fresh()?->last_used_at)->not->toBeNull()
        ->and(resolve(AuthenticatedAgentBridgeClientData::class)->tokenId)->toBe($created['token']->getKey());
});

it('refuses a revoked local token without binding a client', function (): void {
    $user = User::query()->create(['name' => 'Local revoked', 'email' => 'local-revoked@example.test', 'password' => 'secret']);
    $created = CreateAgentBridgeTokenAction::run($user, 'Local revoked client', ['capell.pages.read']);
    RevokeAgentBridgeTokenAction::run($created['token']);
    config()->set('capell-agent-bridge.local.token', $created['plainTextToken']);
    app()->forgetInstance(AuthenticatedAgentBridgeClientData::class);
    app()->forgetInstance(CapellAgentBridgeToken::class);

    expect(fn () => AuthenticateLocalAgentBridgeClientAction::run())->toThrow(AuthorizationException::class);

    expect(app()->bound(AuthenticatedAgentBridgeClientData::class))->toBeFalse()
        ->and($created['token']->fresh()?->last_used_at)->toBeNull();
});

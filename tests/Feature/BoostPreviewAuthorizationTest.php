<?php

declare(strict_types=1);

use Capell\AgentBridge\Data\CapabilityData;
use Capell\AgentBridge\Enums\CapabilityRiskEnum;
use Capell\AgentBridge\Enums\CapabilityServerEnum;
use Capell\AgentBridge\Support\CapellAgentBridgeCapabilityRegistry;
use Capell\AgentBridge\Tests\Fixtures\FakeCapabilityAction;
use Capell\AgentBridge\Tests\Fixtures\User;
use Capell\AgentBridge\Tools\Boost\PreviewBoostCapabilityTool;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;

function boostPreviewRegistry(CapabilityRiskEnum $risk, ?string $policyAbility, CapabilityServerEnum $server = CapabilityServerEnum::Site): CapellAgentBridgeCapabilityRegistry
{
    $registry = new CapellAgentBridgeCapabilityRegistry;
    $registry->register(new CapabilityData(
        key: 'capell.fake.boost',
        name: 'Fake boost capability',
        description: 'Fake capability for Boost previews.',
        scope: 'capell.fake.boost',
        server: $server,
        risk: $risk,
        actionClass: FakeCapabilityAction::class,
        policyAbility: $policyAbility,
    ));

    return $registry;
}

function boostPreviewRequest(): Request
{
    return new Request(['capability' => 'capell.fake.boost', 'payload' => ['name' => 'Example']]);
}

it('refuses a Boost preview of a capability whose policy ability the user lacks', function (): void {
    Gate::define('boost fake ability', static fn (): bool => false);
    $this->actingAs(new User(['name' => 'Denied']));

    expect(fn () => (new PreviewBoostCapabilityTool)->handle(boostPreviewRequest(), boostPreviewRegistry(CapabilityRiskEnum::High, 'boost fake ability')))
        ->toThrow(AuthorizationException::class);
});

it('refuses a Boost preview of a policy-protected capability with no user', function (): void {
    Gate::define('boost fake ability', static fn (): bool => true);

    expect(fn () => (new PreviewBoostCapabilityTool)->handle(boostPreviewRequest(), boostPreviewRegistry(CapabilityRiskEnum::Read, 'boost fake ability')))
        ->toThrow(AuthorizationException::class);
});

it('previews a policy-protected capability for a user the ability allows', function (): void {
    Gate::define('boost fake ability', static fn (): bool => true);
    $this->actingAs(new User(['name' => 'Allowed']));

    $response = (new PreviewBoostCapabilityTool)->handle(boostPreviewRequest(), boostPreviewRegistry(CapabilityRiskEnum::High, 'boost fake ability'));

    expect($response)->not->toBeNull();
});

it('refuses a Boost preview of a mutating capability that names no ability', function (): void {
    expect(fn () => (new PreviewBoostCapabilityTool)->handle(boostPreviewRequest(), boostPreviewRegistry(CapabilityRiskEnum::Medium, null)))
        ->toThrow(AuthorizationException::class);
});

it('refuses a Boost preview of a capability hidden from the site server', function (): void {
    expect(fn () => (new PreviewBoostCapabilityTool)->handle(boostPreviewRequest(), boostPreviewRegistry(CapabilityRiskEnum::Read, null, CapabilityServerEnum::Knowledge)))
        ->toThrow(AuthorizationException::class);
});

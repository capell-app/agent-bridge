<?php

declare(strict_types=1);

use Capell\AgentBridge\Actions\ConfirmAgentBridgeAdminCapabilityAction;
use Capell\AgentBridge\Actions\PreviewAgentBridgeAdminCapabilityAction;
use Capell\AgentBridge\Data\CapabilityData;
use Capell\AgentBridge\Enums\CapabilityRiskEnum;
use Capell\AgentBridge\Enums\CapabilityServerEnum;
use Capell\AgentBridge\Models\CapellAgentBridgeAuditEntry;
use Capell\AgentBridge\Support\CapellAgentBridgeCapabilityRegistry;
use Capell\AgentBridge\Tests\Fixtures\FakeCapabilityAction;
use Capell\AgentBridge\Tests\Fixtures\FakeRejectedCapabilityAction;
use Capell\AgentBridge\Tests\Fixtures\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;

beforeEach(function (): void {
    Cache::clear();

    $registry = new CapellAgentBridgeCapabilityRegistry;
    $registry->register(new CapabilityData(
        key: 'capell.fake.read',
        name: 'Fake read',
        description: 'Read fake state.',
        scope: 'capell.fake.read',
        server: CapabilityServerEnum::Site,
        risk: CapabilityRiskEnum::Read,
        actionClass: FakeCapabilityAction::class,
        requiresConfirmation: false,
        auditEvent: 'capell_agent-bridge.fake.read',
    ));
    $registry->register(new CapabilityData(
        key: 'capell.fake.write',
        name: 'Fake write',
        description: 'Change fake state.',
        scope: 'capell.fake.write',
        server: CapabilityServerEnum::Site,
        risk: CapabilityRiskEnum::High,
        actionClass: FakeCapabilityAction::class,
        auditEvent: 'capell_agent-bridge.fake.write',
    ));
    $registry->register(new CapabilityData(
        key: 'capell.fake.rejected',
        name: 'Fake rejected write',
        description: 'Reject a fake change.',
        scope: 'capell.fake.write',
        server: CapabilityServerEnum::Site,
        risk: CapabilityRiskEnum::High,
        actionClass: FakeRejectedCapabilityAction::class,
    ));

    app()->instance(CapellAgentBridgeCapabilityRegistry::class, $registry);
});

it('executes read capabilities immediately and audits the result', function (): void {
    $user = User::query()->create([
        'name' => 'Command Reader',
        'email' => 'command-reader@example.com',
        'password' => 'secret',
    ]);

    $response = PreviewAgentBridgeAdminCapabilityAction::run(
        capabilityKey: 'capell.fake.read',
        payload: ['query' => 'About'],
        user: $user,
    );

    expect($response)
        ->toMatchArray([
            'mode' => 'executed',
            'capability' => 'capell.fake.read',
        ])
        ->and(data_get($response, 'result.data.payload.query'))->toBe('About')
        ->and(CapellAgentBridgeAuditEntry::query()->value('event'))->toBe('capell_agent-bridge.fake.read');
});

it('requires a one-use, user-bound confirmation before executing mutations', function (): void {
    $user = User::query()->create([
        'name' => 'Command Writer',
        'email' => 'command-writer@example.com',
        'password' => 'secret',
    ]);
    $otherUser = User::query()->create([
        'name' => 'Other Writer',
        'email' => 'other-command-writer@example.com',
        'password' => 'secret',
    ]);

    $preview = PreviewAgentBridgeAdminCapabilityAction::run(
        capabilityKey: 'capell.fake.write',
        payload: ['name' => 'Example'],
        user: $user,
    );
    $confirmationToken = $preview['confirmationToken'] ?? null;

    expect($preview['mode'])->toBe('preview')
        ->and($confirmationToken)->toBeString()
        ->and(CapellAgentBridgeAuditEntry::query()->value('event'))->toBe('capell_agent-bridge.admin.previewed');

    expect(fn (): array => ConfirmAgentBridgeAdminCapabilityAction::run(
        confirmationToken: (string) $confirmationToken,
        user: $otherUser,
    ))->toThrow(AuthorizationException::class);

    $confirmed = ConfirmAgentBridgeAdminCapabilityAction::run(
        confirmationToken: (string) $confirmationToken,
        user: $user,
    );

    expect($confirmed['mode'])->toBe('confirmed')
        ->and(data_get($confirmed, 'result.data.payload.name'))->toBe('Example')
        ->and(CapellAgentBridgeAuditEntry::query()->count())->toBe(2);

    expect(fn (): array => ConfirmAgentBridgeAdminCapabilityAction::run(
        confirmationToken: (string) $confirmationToken,
        user: $user,
    ))->toThrow(AuthorizationException::class);
});

it('never issues a confirmation token for a rejected preview', function (): void {
    $user = User::query()->create([
        'name' => 'Rejected Writer',
        'email' => 'rejected-command-writer@example.com',
        'password' => 'secret',
    ]);

    $response = PreviewAgentBridgeAdminCapabilityAction::run(
        capabilityKey: 'capell.fake.rejected',
        payload: ['name' => 'Example'],
        user: $user,
    );

    expect($response['mode'])->toBe('rejected')
        ->and($response)->not->toHaveKey('confirmationToken')
        ->and(CapellAgentBridgeAuditEntry::query()->count())->toBe(0);
});

it('rechecks policy authorization at preview and confirmation time', function (): void {
    $registry = resolve(CapellAgentBridgeCapabilityRegistry::class);
    $registry->register(new CapabilityData(
        key: 'capell.fake.protected',
        name: 'Fake protected write',
        description: 'Change protected fake state.',
        scope: 'capell.fake.write',
        server: CapabilityServerEnum::Site,
        risk: CapabilityRiskEnum::High,
        actionClass: FakeCapabilityAction::class,
        policyAbility: 'manage fake capability',
    ));

    $user = User::query()->create([
        'name' => 'Policy Writer',
        'email' => 'policy-command-writer@example.com',
        'password' => 'secret',
    ]);
    $isAllowed = true;
    Gate::define('manage fake capability', static function () use (&$isAllowed): bool {
        return $isAllowed;
    });

    $preview = PreviewAgentBridgeAdminCapabilityAction::run(
        capabilityKey: 'capell.fake.protected',
        payload: ['name' => 'Example'],
        user: $user,
    );
    $isAllowed = false;

    ConfirmAgentBridgeAdminCapabilityAction::run(
        confirmationToken: (string) $preview['confirmationToken'],
        user: $user,
    );
})->throws(AuthorizationException::class);

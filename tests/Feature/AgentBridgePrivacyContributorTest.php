<?php

declare(strict_types=1);

use Capell\AgentBridge\Actions\CreateAgentBridgeTokenAction;
use Capell\AgentBridge\Actions\EraseAgentBridgePrivacyDataAction;
use Capell\AgentBridge\Models\CapellAgentBridgeAuditEntry;
use Capell\AgentBridge\Models\CapellAgentBridgeConfirmation;
use Capell\AgentBridge\Models\CapellAgentBridgeToken;
use Capell\AgentBridge\Providers\AgentBridgeServiceProvider;
use Capell\AgentBridge\Tests\Fixtures\User;
use Capell\Core\Data\Privacy\PrivacySubject;
use Capell\Core\Enums\PrivacyErasureMode;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Packages\InstalledRuntimeLifecycle;
use Capell\Core\Support\Privacy\PrivacySubjectRegistry;

function agentBridgePrivacyStaff(string $email): User
{
    return User::query()->create(['name' => 'Staff', 'email' => $email, 'password' => 'secret']);
}

function agentBridgePrivacyToken(User $user, string $address): CapellAgentBridgeToken
{
    $token = CreateAgentBridgeTokenAction::run($user, 'Staff token', ['*'], createdFromIp: $address)['token'];

    CapellAgentBridgeAuditEntry::query()->forceCreate([
        'agent_bridge_token_id' => $token->getKey(),
        'user_type' => $user->getMorphClass(),
        'user_id' => $user->getKey(),
        'event' => 'capell.pages.create_draft',
        'ip_address_hash' => str_repeat('a', 64),
        'user_agent' => 'Mozilla/5.0 test browser',
    ]);

    return $token;
}

it('exports and erases a staff member\'s token and audit data through Core\'s privacy registry', function (): void {
    CapellCore::forcePackageInstalled(AgentBridgeServiceProvider::$packageName);
    app()->instance(InstalledRuntimeLifecycle::class, new InstalledRuntimeLifecycle(app()));
    app()->forgetInstance(PrivacySubjectRegistry::class);
    app()->register(AgentBridgeServiceProvider::class, force: true);

    $staff = agentBridgePrivacyStaff('privacy-staff@example.test');
    $other = agentBridgePrivacyStaff('privacy-other@example.test');
    $token = agentBridgePrivacyToken($staff, '203.0.113.10');
    $otherToken = agentBridgePrivacyToken($other, '203.0.113.20');
    CapellAgentBridgeConfirmation::query()->forceCreate([
        'agent_bridge_token_id' => $token->getKey(),
        'user_type' => $staff->getMorphClass(),
        'user_id' => $staff->getKey(),
        'capability_key' => 'capell.pages.create_draft',
        'scope' => 'capell.pages.write',
        'token' => hash('sha256', 'confirmation'),
        'payload_hash' => hash('sha256', 'payload'),
        'payload' => ['title' => 'Draft'],
        'preview' => [],
        'expires_at' => now()->addHour(),
    ]);

    $registry = resolve(PrivacySubjectRegistry::class);
    $subject = PrivacySubject::forModel($staff);
    $export = $registry->export($subject)['agent-bridge']->data;
    /** @var list<array<string, mixed>> $tokens */
    $tokens = $export['tokens'] ?? [];
    /** @var list<array<string, mixed>> $entries */
    $entries = $export['audit_entries'] ?? [];
    $result = $registry->erase($subject, PrivacyErasureMode::Delete);

    expect($tokens)->toHaveCount(1)
        ->and($tokens[0])->not->toHaveKeys(['token_hash', 'id', 'user_id'])
        ->and($entries)->toHaveCount(1)
        ->and($entries[0]['user_agent'])->toBe('Mozilla/5.0 test browser')
        ->and($result->failures)->toBe([])
        ->and($result->receipts)->toHaveCount(1)
        ->and($result->receipts[0]->contributor)->toBe('agent-bridge')
        ->and($result->receipts[0]->mode)->toBe(PrivacyErasureMode::Anonymize)
        ->and($result->receipts[0]->affectedRecords)->toBe(3)
        ->and($token->refresh()->created_from_ip)->toBeNull()
        ->and($token->is_enabled)->toBeFalse()
        ->and($token->revoked_at)->not->toBeNull()
        ->and(CapellAgentBridgeConfirmation::query()->where('agent_bridge_token_id', $token->getKey())->count())->toBe(0)
        ->and(CapellAgentBridgeAuditEntry::query()->where('agent_bridge_token_id', $token->getKey())->firstOrFail()->user_agent)->toBeNull()
        ->and(CapellAgentBridgeAuditEntry::query()->where('agent_bridge_token_id', $token->getKey())->firstOrFail()->ip_address_hash)->toBeNull()
        ->and(CapellAgentBridgeAuditEntry::query()->where('agent_bridge_token_id', $token->getKey())->firstOrFail()->event)->toBe('capell.pages.create_draft')
        ->and($otherToken->refresh()->is_enabled)->toBeTrue()
        ->and(CapellAgentBridgeAuditEntry::query()->where('agent_bridge_token_id', $otherToken->getKey())->firstOrFail()->user_agent)->toBe('Mozilla/5.0 test browser')
        ->and($registry->erase($subject, PrivacyErasureMode::Delete)->receipts[0]->affectedRecords)->toBe(0);
});

it('erases nothing the second time', function (): void {
    $staff = agentBridgePrivacyStaff('privacy-twice@example.test');
    agentBridgePrivacyToken($staff, '203.0.113.30');

    $first = EraseAgentBridgePrivacyDataAction::run($staff);
    $second = EraseAgentBridgePrivacyDataAction::run($staff);

    expect($first)->toBeGreaterThan(0)->and($second)->toBe(0);
});

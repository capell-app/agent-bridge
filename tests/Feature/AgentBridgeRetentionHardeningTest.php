<?php

declare(strict_types=1);

use Capell\AgentBridge\Actions\AuditAgentBridgeCapabilityAction;
use Capell\AgentBridge\Actions\CreateAgentBridgeTokenAction;
use Capell\AgentBridge\Actions\HashAgentBridgeIpAddressAction;
use Capell\AgentBridge\Actions\InvokeAgentBridgeCapabilityPreviewAction;
use Capell\AgentBridge\Actions\PruneAgentBridgeAuditEntriesAction;
use Capell\AgentBridge\Data\AuthenticatedAgentBridgeClientData;
use Capell\AgentBridge\Data\CapabilityData;
use Capell\AgentBridge\Enums\CapabilityRiskEnum;
use Capell\AgentBridge\Enums\CapabilityServerEnum;
use Capell\AgentBridge\Models\CapellAgentBridgeConfirmation;
use Capell\AgentBridge\Models\CapellAgentBridgeToken;
use Capell\AgentBridge\Support\CapellAgentBridgeCapabilityRegistry;
use Capell\AgentBridge\Tests\Fixtures\FakeCapabilityAction;
use Capell\AgentBridge\Tests\Fixtures\User;
use Capell\Core\Support\TableName;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;
use Illuminate\Validation\ValidationException;

function retentionHardeningUser(string $email): User
{
    return User::query()->create(['name' => 'Retention User', 'email' => $email, 'password' => 'secret']);
}

function retentionHardeningTokenKey(CapellAgentBridgeToken $token): int
{
    $key = $token->getKey();
    throw_unless(is_int($key), RuntimeException::class, 'Expected an integer token key.');

    return $key;
}

it('stores the previewed payload with secrets redacted, because confirming re-sends it', function (): void {
    resolve(CapellAgentBridgeCapabilityRegistry::class)->register(new CapabilityData(
        key: 'capell.fake.write',
        name: 'Fake write',
        description: 'Fake mutating capability.',
        scope: 'capell.fake.write',
        server: CapabilityServerEnum::Site,
        risk: CapabilityRiskEnum::High,
        actionClass: FakeCapabilityAction::class,
    ));
    $user = retentionHardeningUser('confirmation-payload@example.test');
    $token = CreateAgentBridgeTokenAction::run($user, 'Client', ['capell.fake.write'])['token'];
    $secret = 'sk-live-' . str_repeat('a1b2c3d4', 12);

    InvokeAgentBridgeCapabilityPreviewAction::run(
        capabilityKey: 'capell.fake.write',
        payload: ['name' => 'Example', 'api_key' => $secret],
        client: new AuthenticatedAgentBridgeClientData(tokenId: retentionHardeningTokenKey($token), name: 'Client', scopes: ['capell.fake.write']),
        token: $token,
        user: $user,
    );

    $stored = CapellAgentBridgeConfirmation::query()->sole();

    expect(json_encode([$stored->payload, $stored->preview], JSON_THROW_ON_ERROR))->not->toContain($secret)
        ->and($stored->payload['name'] ?? null)->toBe('Example');
});

it('prunes long-expired confirmations and keeps one that expired within the last day', function (): void {
    $user = retentionHardeningUser('confirmation-prune@example.test');
    $token = CreateAgentBridgeTokenAction::run($user, 'Client', ['capell.fake.write'])['token'];
    $make = static function (CarbonImmutable $expiresAt) use ($token, $user): CapellAgentBridgeConfirmation {
        $confirmation = new CapellAgentBridgeConfirmation([
            'token' => CapellAgentBridgeConfirmation::hashPlainTextToken(bin2hex(random_bytes(16))),
            'agent_bridge_token_id' => $token->getKey(),
            'capability_key' => 'capell.fake.write',
            'scope' => 'capell.fake.write',
            'payload_hash' => str_repeat('0', 64),
            'payload' => ['body' => 'Private page draft'],
            'preview' => [],
            'expires_at' => $expiresAt,
        ]);
        $confirmation->user()->associate($user);
        $confirmation->save();

        return $confirmation;
    };
    $old = $make(CarbonImmutable::now()->subDays(120));
    $recent = $make(CarbonImmutable::now()->subHours(12));

    PruneAgentBridgeAuditEntriesAction::run(90);

    expect(CapellAgentBridgeConfirmation::query()->pluck('id')->all())->toBe([$recent->getKey()])
        ->and(CapellAgentBridgeConfirmation::query()->whereKey($old->getKey())->exists())->toBeFalse();
});

it('records an over-long user agent without failing the audited action', function (): void {
    request()->headers->set('User-Agent', str_repeat('Agent/1.0 ', 60));

    $entry = AuditAgentBridgeCapabilityAction::run(event: 'capell_agent-bridge.test', capabilityKey: 'capell.fake.write');

    expect(mb_strlen((string) $entry->refresh()->user_agent))->toBe(255);
});

it('gives a new token the default lifetime and refuses an expiry beyond the maximum', function (): void {
    $user = retentionHardeningUser('token-expiry@example.test');

    $token = CreateAgentBridgeTokenAction::run($user, 'Client', ['capell.pages.read'])['token'];

    expect($token->expires_at?->toDateString())->toBe(CarbonImmutable::now()->addDays(90)->toDateString());

    expect(fn (): array => CreateAgentBridgeTokenAction::run($user, 'Forever', ['capell.pages.read'], CarbonImmutable::now()->addDays(366)))
        ->toThrow(ValidationException::class)
        ->and(fn (): array => CreateAgentBridgeTokenAction::run($user, 'Past', ['capell.pages.read'], CarbonImmutable::now()->subMinute()))
        ->toThrow(ValidationException::class)
        ->and(CapellAgentBridgeToken::query()->count())->toBe(1);
});

it('hashes plaintext creation IPs already stored on tokens and leaves existing hashes alone', function (): void {
    $user = retentionHardeningUser('token-ip-migration@example.test');
    $plain = CreateAgentBridgeTokenAction::run($user, 'Plain', ['capell.pages.read'])['token'];
    $hashed = CreateAgentBridgeTokenAction::run($user, 'Hashed', ['capell.pages.read'], null, '10.0.0.9')['token'];
    DB::table(TableName::resolve('capell_agent_bridge_tokens'))->where('id', $plain->getKey())->update(['created_from_ip' => '203.0.113.7']);
    $existingHash = $hashed->refresh()->created_from_ip;

    $migration = require dirname(__DIR__, 2) . '/database/migrations/2026_11_12_000001_hash_created_from_ip_on_agent_bridge_tokens_table.php';
    $migration->up();
    $migration->up();

    expect($plain->refresh()->created_from_ip)->toBe(HashAgentBridgeIpAddressAction::run('203.0.113.7'))
        ->and($hashed->refresh()->created_from_ip)->toBe($existingHash);
});

it('gives tokens created without an expiry the default lifetime when upgrading', function (): void {
    CarbonImmutable::setTestNow('2026-11-12 10:00:00');
    $user = retentionHardeningUser('legacy-expiry@example.test');
    $legacy = CreateAgentBridgeTokenAction::run($user, 'Legacy', ['capell.pages.read'])['token'];
    $bounded = CreateAgentBridgeTokenAction::run($user, 'Bounded', ['capell.pages.read'], CarbonImmutable::now()->addDays(5))['token'];
    DB::table(TableName::resolve('capell_agent_bridge_tokens'))->where('id', $legacy->getKey())->update(['expires_at' => null]);

    expect(CapellAgentBridgeToken::query()->whereKey($legacy->getKey())->value('expires_at'))->toBeNull();

    $migration = require dirname(__DIR__, 2) . '/database/migrations/2026_11_12_000002_bound_agent_bridge_tokens_without_expiry.php';
    $migration->up();

    expect($legacy->refresh()->expires_at?->toDateTimeString())->toBe('2027-02-10 10:00:00')
        ->and($legacy->isExpired())->toBeFalse()
        ->and($bounded->refresh()->expires_at?->toDateTimeString())->toBe('2026-11-17 10:00:00');

    CarbonImmutable::setTestNow();
});

it('prunes expired confirmations a day after expiry, whatever the audit retention window', function (): void {
    $user = retentionHardeningUser('confirmation-grace@example.test');
    $token = CreateAgentBridgeTokenAction::run($user, 'Client', ['capell.fake.write'])['token'];
    $make = static function (CarbonImmutable $expiresAt) use ($token, $user): CapellAgentBridgeConfirmation {
        $confirmation = new CapellAgentBridgeConfirmation([
            'token' => CapellAgentBridgeConfirmation::hashPlainTextToken(bin2hex(random_bytes(16))),
            'agent_bridge_token_id' => $token->getKey(),
            'capability_key' => 'capell.fake.write',
            'scope' => 'capell.fake.write',
            'payload_hash' => str_repeat('0', 64),
            'payload' => ['password' => 'stored before redaction'],
            'preview' => [],
            'expires_at' => $expiresAt,
        ]);
        $confirmation->user()->associate($user);
        $confirmation->save();

        return $confirmation;
    };
    $make(CarbonImmutable::now()->subDays(2));
    $justExpired = $make(CarbonImmutable::now()->subHour());
    $pending = $make(CarbonImmutable::now()->addHour());

    $result = PruneAgentBridgeAuditEntriesAction::run(365);

    expect($result->expiredConfirmations)->toBe(1)
        ->and($result->auditEntries)->toBe(0)
        ->and(CapellAgentBridgeConfirmation::query()->orderBy('id')->pluck('id')->all())->toBe([$justExpired->getKey(), $pending->getKey()]);
});

it('reports pruned audit entries and expired confirmations separately', function (): void {
    $command = $this->artisan('capell:agent-bridge-prune-audit', ['--days' => 30]);

    throw_unless($command instanceof PendingCommand, RuntimeException::class, 'The prune command did not return a pending command.');

    $command->expectsOutputToContain('Pruned 0 Agent Bridge audit entries and 0 expired confirmations.')->assertSuccessful();
});

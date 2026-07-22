<?php

declare(strict_types=1);

use Capell\AgentBridge\Actions\AuditAgentBridgeCapabilityAction;
use Capell\AgentBridge\Actions\HashAgentBridgeIpAddressAction;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('stores only a keyed hash of audit request IP addresses', function (): void {
    $request = Request::create('/', 'GET', server: ['REMOTE_ADDR' => '203.0.113.41']);
    $this->app->instance('request', $request);

    $entry = AuditAgentBridgeCapabilityAction::run('capell.audit.ip-privacy');

    expect(Schema::hasColumn($entry->getTable(), 'ip_address'))->toBeFalse()
        ->and(Schema::hasColumn($entry->getTable(), 'ip_address_hash'))->toBeTrue()
        ->and($entry->ip_address_hash)->toBe(HashAgentBridgeIpAddressAction::run('203.0.113.41'))
        ->and($entry->ip_address_hash)->not->toBe('203.0.113.41');
});

it('backfills legacy plaintext audit IP addresses before dropping the column', function (): void {
    $tableName = 'capell_agent_bridge_audit_entries';

    Schema::table($tableName, static function (Blueprint $table): void {
        $table->dropColumn('ip_address_hash');
        $table->string('ip_address', 45)->nullable();
    });

    $entryId = DB::table($tableName)->insertGetId([
        'event' => 'capell.audit.legacy-ip',
        'ip_address' => '198.51.100.22',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $addHashMigration = require dirname(__DIR__, 2) . '/database/migrations/2026_07_09_000001_add_ip_address_hash_to_agent_bridge_audit_entries_table.php';
    $addHashMigration->up();

    $dropPlaintextMigration = require dirname(__DIR__, 2) . '/database/migrations/2026_07_10_000001_drop_plaintext_ip_from_agent_bridge_audit_entries_table.php';
    $dropPlaintextMigration->up();

    expect(Schema::hasColumn($tableName, 'ip_address'))->toBeFalse()
        ->and(Schema::hasColumn($tableName, 'ip_address_hash'))->toBeTrue()
        ->and(DB::table($tableName)->where('id', $entryId)->value('ip_address_hash'))
        ->toBe(HashAgentBridgeIpAddressAction::run('198.51.100.22'));
});

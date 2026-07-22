<?php

declare(strict_types=1);

use Capell\AgentBridge\Actions\HashAgentBridgeIpAddressAction;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const string TABLE = 'capell_agent_bridge_audit_entries';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        if (! Schema::hasColumn(self::TABLE, 'ip_address_hash')) {
            Schema::table(self::TABLE, static function (Blueprint $table): void {
                $table->char('ip_address_hash', 64)->nullable()->after('ip_address');
            });
        }

        if (Schema::hasColumn(self::TABLE, 'ip_address')) {
            $this->backfillHashes();
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE) || ! Schema::hasColumn(self::TABLE, 'ip_address_hash')) {
            return;
        }

        Schema::table(self::TABLE, static function (Blueprint $table): void {
            $table->dropColumn('ip_address_hash');
        });
    }

    private function backfillHashes(): void
    {
        DB::table(self::TABLE)
            ->select(['id', 'ip_address'])
            ->orderBy('id')
            ->chunkById(500, function (Collection $rows): void {
                foreach ($rows as $row) {
                    DB::table(self::TABLE)
                        ->where('id', $row->id)
                        ->update([
                            'ip_address_hash' => HashAgentBridgeIpAddressAction::run($row->ip_address),
                        ]);
                }
            });
    }
};

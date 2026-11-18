<?php

declare(strict_types=1);

use Capell\Core\Support\TableName;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const string TABLE = 'capell_agent_bridge_tokens';

    /**
     * A token can be narrowed below its owner's own access: to some sites, to some
     * capabilities, and to its own request quota. Empty columns keep the existing
     * behaviour, so tokens created before this migration are unchanged.
     */
    public function up(): void
    {
        $table = TableName::resolve(self::TABLE);

        if (! Schema::hasTable($table) || Schema::hasColumn($table, 'site_ids')) {
            return;
        }

        Schema::table($table, static function (Blueprint $table): void {
            $table->json('site_ids')->nullable()->after('scopes');
            $table->json('capability_keys')->nullable()->after('site_ids');
            $table->unsignedInteger('requests_per_minute')->nullable()->after('capability_keys');
        });
    }

    public function down(): void
    {
        $table = TableName::resolve(self::TABLE);

        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'site_ids')) {
            return;
        }

        Schema::table($table, static function (Blueprint $table): void {
            $table->dropColumn(['site_ids', 'capability_keys', 'requests_per_minute']);
        });
    }
};

<?php

declare(strict_types=1);

use Capell\Core\Support\TableName;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (TableName::existsAndIsOwned('capell_agent_bridge_audit_entries', basename(__FILE__, '.php'))) {
            return;
        }

        Schema::create(TableName::resolve('capell_agent_bridge_audit_entries'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agent_bridge_token_id')->nullable()->constrained(TableName::resolve('capell_agent_bridge_tokens'))->nullOnDelete();
            // Nullable: tokenless public-read capabilities are audited without an authenticated user.
            $table->nullableMorphs('user');
            $table->string('event');
            $table->string('capability_key')->nullable();
            $table->string('scope')->nullable();
            $table->json('payload')->nullable();
            $table->json('result')->nullable();
            $table->char('ip_address_hash', 64)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(TableName::resolve('capell_agent_bridge_audit_entries'));
    }
};

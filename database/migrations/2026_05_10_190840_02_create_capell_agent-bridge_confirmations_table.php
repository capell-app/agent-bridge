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
        if (TableName::existsAndIsOwned('capell_agent_bridge_confirmations', basename(__FILE__, '.php'))) {
            return;
        }

        Schema::create(TableName::resolve('capell_agent_bridge_confirmations'), function (Blueprint $table): void {
            $table->id();
            $table->string('token', 64)->unique();
            $table->foreignId('agent_bridge_token_id')->constrained(TableName::resolve('capell_agent_bridge_tokens'))->cascadeOnDelete();
            $table->morphs('user');
            $table->string('capability_key');
            $table->string('scope');
            $table->string('payload_hash', 64);
            $table->json('payload');
            $table->json('preview');
            $table->dateTime('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(TableName::resolve('capell_agent_bridge_confirmations'));
    }
};

<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Actions;

use Capell\AgentBridge\Models\CapellAgentBridgeAuditEntry;
use Capell\AgentBridge\Models\CapellAgentBridgeConfirmation;
use Capell\AgentBridge\Models\CapellAgentBridgeToken;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use RuntimeException;

final class PrepareAgentBridgeScreenshotAction
{
    public function handle(Model $user): void
    {
        throw_unless(app()->environment('local', 'testing') && getenv('CAPELL_SCREENSHOT_FIXTURE') === 'record-state', RuntimeException::class, 'Agent Bridge screenshot fixtures require the disposable screenshot environment.');

        (new CapellAgentBridgeToken)->getConnection()->transaction(static function () use ($user): void {
            $token = CapellAgentBridgeToken::query()->firstOrNew([
                'name' => 'Screenshot review token',
                'user_type' => $user->getMorphClass(),
                'user_id' => $user->getKey(),
            ]);
            $token->forceFill([
                'user_type' => $user->getMorphClass(),
                'user_id' => $user->getKey(),
                'token_hash' => CapellAgentBridgeToken::hashPlainTextToken(Str::random(64)),
                'scopes' => ['capell.pages.read'],
                'is_enabled' => false,
            ])->save();

            $confirmation = CapellAgentBridgeConfirmation::query()->firstOrNew([
                'agent_bridge_token_id' => $token->getKey(),
                'capability_key' => 'capell.pages.inspect',
            ]);
            $confirmation->forceFill([
                'user_type' => $user->getMorphClass(),
                'user_id' => $user->getKey(),
                'token' => CapellAgentBridgeConfirmation::hashPlainTextToken(Str::random(64)),
                'scope' => 'capell.pages.read',
                'payload_hash' => hash('sha256', '{}'),
                'payload' => [],
                'preview' => ['summary' => 'Read the screenshot page without changing content.'],
                'expires_at' => now()->addHour(),
                'used_at' => null,
            ])->save();

            CapellAgentBridgeAuditEntry::query()->updateOrCreate([
                'agent_bridge_token_id' => $token->getKey(),
                'event' => 'screenshot.previewed',
            ], [
                'capability_key' => 'capell.pages.inspect',
                'scope' => 'capell.pages.read',
                'payload' => [],
                'result' => ['summary' => 'Read-only preview completed.'],
            ]);
        });
    }
}

<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Actions\Fixtures;

use Capell\AgentBridge\Models\CapellAgentBridgeAuditEntry;
use Capell\AgentBridge\Models\CapellAgentBridgeConfirmation;
use Capell\AgentBridge\Models\CapellAgentBridgeToken;
use Capell\ScreenshotFixtures\FixtureState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use RuntimeException;

final class PrepareAgentBridgeScreenshotAction
{
    public function handle(Model $user): void
    {
        $configuredAppPath = getenv('CAPELL_SCREENSHOT_APP_PATH');
        $appPath = is_string($configuredAppPath) ? realpath($configuredAppPath) : false;
        $basePath = realpath(base_path());
        throw_unless(
            app()->environment('local', 'testing')
                && getenv('CAPELL_SCREENSHOT_FIXTURE') === 'record-state'
                && is_string($appPath)
                && is_string($basePath)
                && $appPath === $basePath
                && (realpath(base_path()) === realpath((str_contains(__DIR__, '/vendor/capell-app/') ? dirname(__DIR__, 6) : dirname(__DIR__, 5)) . '/vendor/orchestra/testbench-core/laravel')
                    || str_starts_with((string) realpath(base_path()), (str_contains(__DIR__, '/vendor/capell-app/') ? dirname(__DIR__, 6) : dirname(__DIR__, 5)) . '/tmp/testbench-skeletons/')),
            RuntimeException::class,
            'Agent Bridge screenshot fixtures require the explicit disposable screenshot environment.',
        );

        require_once (str_contains(__DIR__, '/vendor/capell-app/') ? dirname(__DIR__, 6) : dirname(__DIR__, 5)) . '/scripts/screenshots/FixtureState.php';
        FixtureState::boot();

        $captureEmail = getenv('CAPELL_SCREENSHOT_ADMIN_EMAIL') ?: (getenv('CAPELL_ADMIN_EMAIL') ?: 'admin@example.com');
        $authenticated = auth()->user();
        throw_if(
            $authenticated !== null && data_get($authenticated, 'email') !== $captureEmail,
            RuntimeException::class,
            'Screenshot fixtures require the selected capture actor.',
        );
        throw_unless(data_get($user, 'email') === $captureEmail, RuntimeException::class, 'Screenshot fixtures require the selected capture actor.');

        (new CapellAgentBridgeToken)->getConnection()->transaction(static function () use ($user): void {
            $tokenDefinitions = [
                ['name' => 'Screenshot review token', 'scopes' => ['capell.pages.read'], 'revoked_at' => null],
                ['name' => 'Screenshot API token', 'scopes' => ['capell.pages.read', 'capell.pages.write'], 'revoked_at' => null],
                ['name' => 'Screenshot read-only token', 'scopes' => ['capell.pages.read'], 'revoked_at' => null],
                ['name' => 'Screenshot revoked token', 'scopes' => ['capell.pages.read'], 'revoked_at' => now()->subHour()],
            ];

            $token = null;

            foreach ($tokenDefinitions as $tokenDefinition) {
                $screenshotToken = CapellAgentBridgeToken::query()
                    ->where('name', $tokenDefinition['name'])
                    ->where('user_type', $user->getMorphClass())
                    ->where('user_id', $user->getKey())
                    ->lockForUpdate()
                    ->first() ?? new CapellAgentBridgeToken;
                if ($screenshotToken->exists) {
                    throw_unless(CapellAgentBridgeAuditEntry::query()->where('agent_bridge_token_id', $screenshotToken->getKey())->where('event', 'screenshot.fixture-owned')->where('result->screenshot_fixture', true)->exists(), RuntimeException::class, 'The Agent Bridge screenshot token name is already in use.');
                }

                $screenshotToken->forceFill([
                    'name' => $tokenDefinition['name'],
                    'user_type' => $user->getMorphClass(),
                    'user_id' => $user->getKey(),
                    'token_hash' => CapellAgentBridgeToken::hashPlainTextToken(Str::random(64)),
                    'scopes' => $tokenDefinition['scopes'],
                    'is_enabled' => true,
                    'revoked_at' => $tokenDefinition['revoked_at'],
                    'created_from_ip' => '127.0.0.1',
                    'expires_at' => null,
                    'rotated_at' => null,
                ])->save();

                CapellAgentBridgeAuditEntry::query()->firstOrCreate([
                    'agent_bridge_token_id' => $screenshotToken->getKey(),
                    'event' => 'screenshot.fixture-owned',
                ], ['result' => ['screenshot_fixture' => true]]);

                if ($tokenDefinition['name'] === 'Screenshot review token') {
                    $token = $screenshotToken;
                }
            }

            throw_unless($token instanceof CapellAgentBridgeToken, RuntimeException::class, 'The screenshot review token was not prepared.');

            $confirmation = CapellAgentBridgeConfirmation::query()->firstOrNew([
                'agent_bridge_token_id' => $token->getKey(),
                'capability_key' => 'capell.pages.inspect',
            ]);
            throw_if($confirmation->exists && ($confirmation->user_type !== $user->getMorphClass() || $confirmation->user_id !== $user->getKey() || data_get($confirmation->preview, 'screenshot_fixture') !== true), RuntimeException::class, 'The Agent Bridge screenshot confirmation belongs to another record.');
            $confirmation->forceFill([
                'user_type' => $user->getMorphClass(),
                'user_id' => $user->getKey(),
                'token' => CapellAgentBridgeConfirmation::hashPlainTextToken(Str::random(64)),
                'scope' => 'capell.pages.read',
                'payload_hash' => hash('sha256', '{}'),
                'payload' => [],
                'preview' => ['summary' => 'Read the screenshot page without changing content.', 'screenshot_fixture' => true],
                'expires_at' => now()->addHour(),
                'used_at' => null,
            ])->save();

            $audit = CapellAgentBridgeAuditEntry::query()->where('agent_bridge_token_id', $token->getKey())->where('event', 'screenshot.previewed')->lockForUpdate()->first();
            throw_if($audit !== null && ($audit->getAttribute('user_type') !== null || $audit->getAttribute('user_id') !== null || data_get($audit->result, 'screenshot_fixture') !== true), RuntimeException::class, 'The Agent Bridge screenshot audit entry belongs to another record.');
            CapellAgentBridgeAuditEntry::query()->updateOrCreate([
                'agent_bridge_token_id' => $token->getKey(),
                'event' => 'screenshot.previewed',
            ], [
                'capability_key' => 'capell.pages.inspect',
                'scope' => 'capell.pages.read',
                'payload' => [],
                'result' => ['summary' => 'Read-only preview completed.', 'screenshot_fixture' => true],
            ]);
        });
    }
}

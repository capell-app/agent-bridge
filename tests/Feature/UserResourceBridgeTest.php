<?php

declare(strict_types=1);

use Capell\Admin\Contracts\Bridges\UserResourceBridge;
use Capell\Admin\Data\Bridges\AdminBridgeContextData;
use Capell\Admin\Data\Schemas\UserSchemaContextData;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Support\Bridges\AdminBridgeRegistrar;
use Capell\Admin\Support\CapellAdminManager;
use Capell\Admin\Support\Extensions\ExtensionManagementSurfaceRegistry;
use Capell\Admin\Support\Extensions\ExtensionPageRegistry;
use Capell\AgentBridge\Bridges\AgentBridgeAdminBridge;
use Capell\AgentBridge\Extenders\AgentBridgeUserSchemaExtender;
use Capell\AgentBridge\Filament\Pages\CapellAgentBridgePromptBuilderPage;
use Capell\AgentBridge\Filament\Resources\Users\RelationManagers\AgentBridgeAuditEntriesRelationManager;
use Capell\AgentBridge\Filament\Resources\Users\RelationManagers\AgentBridgeConfirmationsRelationManager;
use Capell\AgentBridge\Filament\Resources\Users\RelationManagers\AgentBridgeTokensRelationManager;
use Capell\AgentBridge\Filament\Settings\AgentBridgeSettingsSchema;
use Capell\AgentBridge\Models\CapellAgentBridgeAuditEntry;
use Capell\AgentBridge\Models\CapellAgentBridgeConfirmation;
use Capell\AgentBridge\Models\CapellAgentBridgeToken;
use Capell\AgentBridge\Providers\AgentBridgeServiceProvider;
use Capell\AgentBridge\Settings\AgentBridgeSettings;
use Capell\AgentBridge\Tests\Fixtures\User;
use Capell\Core\Support\Settings\SettingsSchemaRegistry;
use Capell\Tests\Support\LegacyAdminBridgeFallbackHost;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Icon;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Livewire\Component as LivewireComponent;
use Spatie\LaravelSettings\Migrations\SettingsMigrator;

function createAgentBridgeUser(string $email): User
{
    return User::query()->create([
        'name' => 'Agent Bridge User',
        'email' => $email,
        'password' => 'secret',
    ]);
}

function seedAgentBridgeSettings(): void
{
    $settingsMigration = require dirname(__DIR__, 2) . '/database/settings/2026_05_10_190841_01_add_agent_bridge_settings.php';
    $settingsMigration->up();
}

function invokeAgentBridgeProviderMethod(object $provider, string $method): void
{
    $reflection = new ReflectionMethod($provider, $method);
    $reflection->invoke($provider);
}

function resetAgentBridgeAdminBridgeState(): void
{
    app()->forgetInstance(CapellAdminManager::class);
    app()->forgetInstance(ExtensionPageRegistry::class);
    app()->singleton(ExtensionPageRegistry::class, fn (): ExtensionPageRegistry => new ExtensionPageRegistry);
    CapellAdmin::clearResolvedInstance(CapellAdminManager::class);
    CapellAdmin::clearAdminSurfaceContributions();
}

function updateSetting(string $settingKey, mixed $value): void
{
    /** @var SettingsMigrator $settingsMigrator */
    $settingsMigrator = resolve(SettingsMigrator::class);

    if ($settingsMigrator->exists($settingKey)) {
        $settingsMigrator->update($settingKey, fn (): mixed => $value);

        app()->forgetInstance(AgentBridgeSettings::class);

        return;
    }

    $settingsMigrator->add($settingKey, $value);

    app()->forgetInstance(AgentBridgeSettings::class);
}

function createAgentBridgeTokenFor(Model $user, string $name, string $plainTextToken, ?DateTimeInterface $expiresAt = null): CapellAgentBridgeToken
{
    $token = new CapellAgentBridgeToken([
        'name' => $name,
        'token_hash' => CapellAgentBridgeToken::hashPlainTextToken($plainTextToken),
        'scopes' => ['capell.pages.read'],
        'expires_at' => $expiresAt,
    ]);

    $token->user()->associate($user);
    $token->save();

    return $token;
}

it('hydrates agent bridge settings and registers the settings schema', function (): void {
    seedAgentBridgeSettings();

    expect(resolve(AgentBridgeSettings::class)->enable_user_resource_bridge)->toBeTrue();

    /** @var SettingsSchemaRegistry $registry */
    $registry = resolve(SettingsSchemaRegistry::class);

    expect($registry->getSettingsClass('agent_bridge'))->toBe(AgentBridgeSettings::class)
        ->and($registry->getSchemas('agent_bridge'))->toContain(AgentBridgeSettingsSchema::class);

    $surfaces = resolve(ExtensionManagementSurfaceRegistry::class)
        ->surfacesForPackage(AgentBridgeServiceProvider::$packageName);

    expect($surfaces[0]->settingsGroup ?? null)->toBe('agent_bridge');
});

it('exposes a translated settings toggle', function (): void {
    $livewire = new class extends LivewireComponent implements HasSchemas
    {
        use InteractsWithSchemas;
    };
    $schema = Filament\Schemas\Schema::make($livewire);
    /** @var list<Component> $settingsComponents */
    $settingsComponents = AgentBridgeSettingsSchema::make($schema);
    $schema->components($settingsComponents);
    $components = $schema->getComponents();
    $helperText = 'Controls whether Agent Bridge activity details and related records are added to supported user edit pages.';
    $translationKey = 'capell-agent-bridge::admin.enable_user_resource_bridge_helper';

    /** @var Toggle $toggle */
    $toggle = $components[0] ?? null;
    throw_unless($toggle instanceof Toggle, RuntimeException::class, 'Expected the Agent Bridge setting to be a toggle.');
    $afterLabelSchema = $toggle->getChildSchema(Toggle::AFTER_LABEL_SCHEMA_KEY);
    $helperIcon = $afterLabelSchema?->getComponents()[0] ?? null;
    throw_unless($helperIcon instanceof Icon, RuntimeException::class, 'Expected the Agent Bridge setting to have a helper icon.');

    expect($components)->toHaveCount(1)
        ->and($toggle->getName())->toBe('enable_user_resource_bridge')
        ->and(__($translationKey))->toBe($helperText)
        ->and($helperIcon->getTooltip())->toBe($helperText);
});

it('runs the agent bridge settings migration idempotently', function (): void {
    seedAgentBridgeSettings();
    seedAgentBridgeSettings();

    /** @var SettingsMigrator $settingsMigrator */
    $settingsMigrator = resolve(SettingsMigrator::class);

    expect($settingsMigrator->exists('agent_bridge.enable_user_resource_bridge'))->toBeTrue()
        ->and(Schema::hasTable('settings'))->toBeTrue();
});

it('gates the bridge behind its package setting', function (): void {
    seedAgentBridgeSettings();

    $extender = new AgentBridgeUserSchemaExtender;
    $context = UserSchemaContextData::forCreate();

    expect($extender->supports($context))->toBeTrue();

    updateSetting('agent_bridge.enable_user_resource_bridge', false);

    $packageSettings = new AgentBridgeSettings;
    $packageSettings->enable_user_resource_bridge = false;

    app()->instance(AgentBridgeSettings::class, $packageSettings);

    expect($extender->supports($context))->toBeFalse();
});

it('tags the user resource bridge when the admin bridge host exists', function (): void {
    $taggedBridges = iterator_to_array(app()->tagged(UserResourceBridge::TAG));

    expect(collect($taggedBridges)->contains(fn (object $bridge): bool => $bridge instanceof AgentBridgeUserSchemaExtender))->toBeTrue();
});

it('registers the current agent bridge admin surface', function (): void {
    resetAgentBridgeAdminBridgeState();

    (new AgentBridgeAdminBridge)->register(
        resolve(AdminBridgeRegistrar::class),
        AdminBridgeContextData::forPackage(AgentBridgeServiceProvider::$packageName),
    );

    expect(resolve(ExtensionPageRegistry::class)->get(AgentBridgeServiceProvider::$packageName))
        ->toBe(CapellAgentBridgePromptBuilderPage::class)
        ->and(collect(app()->tagged(UserResourceBridge::TAG))->contains(
            fn (object $bridge): bool => $bridge instanceof AgentBridgeUserSchemaExtender,
        ))->toBeTrue();
});

it('keeps the legacy admin fallback when the bridge host is unavailable', function (): void {
    $host = new LegacyAdminBridgeFallbackHost;
    CapellAdmin::swap($host);

    try {
        invokeAgentBridgeProviderMethod(new AgentBridgeServiceProvider(app()), 'registerAdminIntegration');

        $taggedBridges = iterator_to_array(app()->tagged(UserResourceBridge::TAG));

        expect($host->extensionPages[AgentBridgeServiceProvider::$packageName] ?? [])
            ->toContain(CapellAgentBridgePromptBuilderPage::class)
            ->and(collect($taggedBridges)->contains(fn (object $bridge): bool => $bridge instanceof AgentBridgeUserSchemaExtender))->toBeTrue();
    } finally {
        app()->forgetInstance(CapellAdminManager::class);
        CapellAdmin::clearResolvedInstance(CapellAdminManager::class);
    }
});

it('scopes tokens confirmations and audit entries to the edited user', function (): void {
    $editedUser = createAgentBridgeUser('edited@example.test');
    $otherUser = createAgentBridgeUser('other@example.test');
    $editedToken = createAgentBridgeTokenFor($editedUser, 'Edited token', 'edited-token');
    $otherToken = createAgentBridgeTokenFor($otherUser, 'Other token', 'other-token');

    $editedConfirmation = new CapellAgentBridgeConfirmation;
    $editedConfirmation->forceFill([
        'token' => str_repeat('a', 64),
        'agent_bridge_token_id' => $editedToken->getKey(),
        'capability_key' => 'capell.pages.update_draft',
        'scope' => 'capell.pages.write',
        'payload_hash' => str_repeat('b', 64),
        'payload' => ['page' => 1],
        'preview' => ['ok' => true],
        'expires_at' => now()->addMinutes(10),
    ]);
    $editedConfirmation->user()->associate($editedUser);
    $editedConfirmation->save();

    $otherConfirmation = new CapellAgentBridgeConfirmation;
    $otherConfirmation->forceFill([
        'token' => str_repeat('c', 64),
        'agent_bridge_token_id' => $otherToken->getKey(),
        'capability_key' => 'capell.pages.update_draft',
        'scope' => 'capell.pages.write',
        'payload_hash' => str_repeat('d', 64),
        'payload' => ['page' => 2],
        'preview' => ['ok' => true],
        'expires_at' => now()->addMinutes(10),
    ]);
    $otherConfirmation->user()->associate($otherUser);
    $otherConfirmation->save();

    $editedAuditEntry = new CapellAgentBridgeAuditEntry;
    $editedAuditEntry->forceFill([
        'agent_bridge_token_id' => $editedToken->getKey(),
        'event' => 'capell_agent-bridge.pages.updated',
    ]);
    $editedAuditEntry->user()->associate($editedUser);
    $editedAuditEntry->save();

    $otherAuditEntry = new CapellAgentBridgeAuditEntry;
    $otherAuditEntry->forceFill([
        'agent_bridge_token_id' => $otherToken->getKey(),
        'event' => 'capell_agent-bridge.pages.updated',
    ]);
    $otherAuditEntry->user()->associate($otherUser);
    $otherAuditEntry->save();

    expect(AgentBridgeTokensRelationManager::scopedQueryForUser(CapellAgentBridgeToken::query(), $editedUser)->pluck('name')->all())->toBe(['Edited token'])
        ->and(count(AgentBridgeConfirmationsRelationManager::scopedQueryForUser(CapellAgentBridgeConfirmation::query(), $editedUser)->pluck('id')->all()))->toBe(1)
        ->and(count(AgentBridgeAuditEntriesRelationManager::scopedQueryForUser(CapellAgentBridgeAuditEntry::query(), $editedUser)->pluck('id')->all()))->toBe(1);
});

it('builds user bridge relation manager tables with scoped queries', function (): void {
    $editedUser = createAgentBridgeUser('relation-manager-edited@example.test');
    $otherUser = createAgentBridgeUser('relation-manager-other@example.test');
    $editedToken = createAgentBridgeTokenFor($editedUser, 'Edited token', 'edited-token');
    createAgentBridgeTokenFor($otherUser, 'Other token', 'other-token');

    $confirmation = new CapellAgentBridgeConfirmation;
    $confirmation->forceFill([
        'token' => str_repeat('a', 64),
        'agent_bridge_token_id' => $editedToken->getKey(),
        'capability_key' => 'capell.pages.update_draft',
        'scope' => 'capell.pages.write',
        'payload_hash' => str_repeat('b', 64),
        'payload' => ['page' => 1],
        'preview' => ['ok' => true],
        'expires_at' => now()->addMinutes(10),
    ]);
    $confirmation->user()->associate($editedUser);
    $confirmation->save();

    $auditEntry = new CapellAgentBridgeAuditEntry;
    $auditEntry->forceFill([
        'agent_bridge_token_id' => $editedToken->getKey(),
        'event' => 'capell_agent-bridge.pages.updated',
    ]);
    $auditEntry->user()->associate($editedUser);
    $auditEntry->save();

    expect(agentBridgeRelationManagerQuery(new AgentBridgeTokensRelationManager, $editedUser)->pluck('name')->all())
        ->toBe(['Edited token'])
        ->and(agentBridgeRelationManagerQuery(new AgentBridgeConfirmationsRelationManager, $editedUser)->pluck('capability_key')->all())
        ->toBe(['capell.pages.update_draft'])
        ->and(agentBridgeRelationManagerQuery(new AgentBridgeAuditEntriesRelationManager, $editedUser)->pluck('event')->all())
        ->toBe(['capell_agent-bridge.pages.updated']);
});

/**
 * @return Builder<Model>
 */
function agentBridgeRelationManagerQuery(RelationManager $relationManager, Model $ownerRecord): Builder
{
    $relationManager->ownerRecord = $ownerRecord;

    $query = $relationManager->table(Table::make($relationManager))->getQuery();

    throw_unless($query instanceof Builder, RuntimeException::class, 'Expected relation manager table to expose an Eloquent query.');

    return $query;
}

it('summarizes token status without exposing token secrets', function (): void {
    $user = createAgentBridgeUser('safe@example.test');
    $plainTextToken = 'cagent-bridge_plain-secret';
    $token = createAgentBridgeTokenFor($user, 'Safe token', $plainTextToken, now()->addDay());
    $token->forceFill(['last_used_at' => now()->subHour()])->save();

    $summary = (new AgentBridgeUserSchemaExtender)->summarizeUserActivity($user);
    $summaryText = implode(' ', array_map(strval(...), $summary));

    expect($summary)->toMatchArray([
        'tokens' => 1,
        'active' => 1,
        'expired' => 0,
    ])->and($summaryText)->not->toContain($plainTextToken)
        ->and($summaryText)->not->toContain($token->token_hash);
});

it('extends user sidebar components and relation managers for persisted users', function (): void {
    $user = createAgentBridgeUser('sidebar@example.test');
    $expiredToken = createAgentBridgeTokenFor($user, 'Expired token', 'expired-token', now()->subDay());

    $usedConfirmation = new CapellAgentBridgeConfirmation;
    $usedConfirmation->forceFill([
        'token' => str_repeat('e', 64),
        'agent_bridge_token_id' => $expiredToken->getKey(),
        'capability_key' => 'capell.pages.update_draft',
        'scope' => 'capell.pages.write',
        'payload_hash' => str_repeat('f', 64),
        'payload' => ['page' => 3],
        'preview' => ['ok' => true],
        'expires_at' => now()->addMinutes(10),
        'used_at' => now(),
    ]);
    $usedConfirmation->user()->associate($user);
    $usedConfirmation->save();

    $extender = new AgentBridgeUserSchemaExtender;
    $context = UserSchemaContextData::forEdit($user, [], 'default');

    expect($extender->extendSidebarComponents(resolve(Filament\Schemas\Schema::class), $context))->toHaveCount(1)
        ->and($extender->extendRelationManagers($user, [], $context))->toContain(
            AgentBridgeTokensRelationManager::class,
            AgentBridgeConfirmationsRelationManager::class,
            AgentBridgeAuditEntriesRelationManager::class,
        )
        ->and($extender->summarizeUserActivity($user))->toMatchArray([
            'tokens' => 1,
            'confirmations' => 1,
            'approved' => 1,
            'active' => 0,
            'expired' => 1,
        ]);
});

it('does not extend sidebar components without a persisted user record', function (): void {
    $extender = new AgentBridgeUserSchemaExtender;

    expect($extender->extendSidebarComponents(resolve(Filament\Schemas\Schema::class), UserSchemaContextData::forCreate()))
        ->toBe([]);
});

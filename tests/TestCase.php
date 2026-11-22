<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Tests;

use Aimeos\Nestedset\NestedSetServiceProvider;
use Bkwld\Cloner\ServiceProvider as ClonerServiceProvider;
use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Capell\Admin\Providers\AdminServiceProvider;
use Capell\AgentBridge\Models\CapellAgentBridgeToken;
use Capell\AgentBridge\Providers\AgentBridgeServiceProvider;
use Capell\AgentBridge\Tests\Fixtures\InstalledAgentBridgePackageServiceProvider;
use Capell\AgentBridge\Tests\Fixtures\SiteAssignedUser;
use Capell\AgentBridge\Tests\Fixtures\User;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Providers\CapellServiceProvider;
use Capell\Core\Support\CapellCoreManager;
use Capell\Tests\Support\Concerns\DeclaresMonorepoMigrationSources;
use Capell\Tests\Support\Concerns\IsolatesTestbenchPaths;
use Capell\Tests\Support\Concerns\RecordsInstalledPackageLifecycles;
use Filament\Actions\ActionsServiceProvider;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Laravel\Mcp\Server\McpServiceProvider as LaravelMcpServiceProvider;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Override;
use Spatie\EventSourcing\EventSourcingServiceProvider;
use Spatie\LaravelSettings\LaravelSettingsServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    use DeclaresMonorepoMigrationSources;
    use IsolatesTestbenchPaths;
    use RecordsInstalledPackageLifecycles;
    use RefreshDatabase;

    protected function setUp(): void
    {
        CapellCore::clearResolvedInstance(CapellCoreManager::class);

        parent::setUp();

        CapellCore::clearResolvedInstance(CapellCoreManager::class);
        CapellCore::forcePackageInstalled(AgentBridgeServiceProvider::$packageName);

        if (! in_array(CapellAgentBridgeToken::class, CapellCore::getModels(), true)) {
            $application = $this->app;
            $provider = $application instanceof Application
                ? $application->getProvider(AgentBridgeServiceProvider::class)
                : null;

            if ($provider instanceof AgentBridgeServiceProvider) {
                $provider->boot();
            }
        }

        Relation::morphMap([
            'agent-bridge_user' => User::class,
            'agent-bridge_site_assigned_user' => SiteAssignedUser::class,
        ]);
    }

    /**
     * @return array<int, class-string>
     */
    #[Override]
    protected function getPackageProviders(mixed $app): array
    {
        $providers = [
            BladeIconsServiceProvider::class,
            BladeHeroiconsServiceProvider::class,
            NestedSetServiceProvider::class,
            ClonerServiceProvider::class,
            SupportServiceProvider::class,
            ActionsServiceProvider::class,
            NotificationsServiceProvider::class,
            SchemasServiceProvider::class,
            FormsServiceProvider::class,
            FilamentServiceProvider::class,
            LivewireServiceProvider::class,
            TablesServiceProvider::class,
            LaravelSettingsServiceProvider::class,
            EventSourcingServiceProvider::class,
            CapellServiceProvider::class,
            AdminServiceProvider::class,
            InstalledAgentBridgePackageServiceProvider::class,
            AgentBridgeServiceProvider::class,
        ];

        if (class_exists(LaravelMcpServiceProvider::class)) {
            array_unshift($providers, LaravelMcpServiceProvider::class);
        }

        return $providers;
    }

    #[Override]
    protected function defineEnvironment(mixed $app): void
    {
        /** @var array<string, mixed> $permissionConfig */
        $permissionConfig = require dirname(__DIR__, 3) . '/vendor/spatie/laravel-permission/config/permission.php';

        Config::set('database.default', 'sqlite');
        Config::set('database.connections.sqlite.database', ':memory:');
        Config::set('database.connections.sqlite.url');
        Config::set('app.key', 'base64:' . base64_encode(str_repeat('x', 32)));
        Config::set('permission', $permissionConfig);

    }

    #[Override]
    protected function defineDatabaseMigrations(): void
    {
        $this->declareMonorepoMigrationSources();
    }

    /**
     * Keep this package's focused suite independent from the prepared
     * Testbench skeleton, which may contain a broader published migration set.
     *
     * @return array<string, mixed>
     */
    protected function migrateFreshUsing(): array
    {
        $root = dirname(__DIR__, 3);

        return [
            '--path' => [
                $root . '/tests/database/migrations',
                $root . '/vendor/capell-app/core/database/migrations',
                __DIR__ . '/../database/migrations',
            ],
            '--realpath' => true,
        ];
    }
}

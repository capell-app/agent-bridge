<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Providers;

use Capell\Admin\Contracts\Bridges\UserResourceBridge;
use Capell\Admin\Data\Extensions\ExtensionManagementSurfaceData;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Support\Bridges\AdminBridgeRegistrar;
use Capell\AgentBridge\Bridges\AgentBridgeAdminBridge;
use Capell\AgentBridge\Capabilities\BuiltInAgentBridgeCapabilityProvider;
use Capell\AgentBridge\Console\Commands\PruneAgentBridgeAuditEntriesCommand;
use Capell\AgentBridge\Contracts\CapellAgentBridgeCapabilityProvider;
use Capell\AgentBridge\Extenders\AgentBridgeUserSchemaExtender;
use Capell\AgentBridge\Filament\Pages\CapellAgentBridgePromptBuilderPage;
use Capell\AgentBridge\Filament\Settings\AgentBridgeSettingsSchema;
use Capell\AgentBridge\Livewire\PromptBuilderToolbarAction;
use Capell\AgentBridge\Models\CapellAgentBridgeAuditEntry;
use Capell\AgentBridge\Models\CapellAgentBridgeConfirmation;
use Capell\AgentBridge\Models\CapellAgentBridgeMediaUpload;
use Capell\AgentBridge\Models\CapellAgentBridgeSavedPrompt;
use Capell\AgentBridge\Models\CapellAgentBridgeToken;
use Capell\AgentBridge\Settings\AgentBridgeSettings;
use Capell\AgentBridge\Support\AgentBridgePolicyAuthorizer;
use Capell\AgentBridge\Support\AgentBridgeValue;
use Capell\AgentBridge\Support\CapellAgentBridgeCapabilityRegistry;
use Capell\AgentBridge\Tools\Boost\ListBoostCapabilitiesTool;
use Capell\AgentBridge\Tools\Boost\PreviewBoostCapabilityTool;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Packages\PackageSurfaceRegistrar;
use Capell\Core\Support\Packages\RegistersInstalledRuntime;
use Capell\Core\Support\Settings\SettingsGroupMetadata;
use Filament\Pages\Page;
use Filament\Support\Facades\FilamentView;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Application;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Laravel\Boost\AgentBridge\Boost;
use Livewire\Livewire;
use Override;
use ReflectionProperty;
use Throwable;

final class AgentBridgeServiceProvider extends ServiceProvider
{
    use RegistersInstalledRuntime;

    public static string $packageName = 'capell-app/agent-bridge';

    #[Override]
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/capell-agent-bridge.php', 'capell-agent-bridge');
        $this->loadTranslationsFrom(__DIR__ . '/../../resources/lang', 'capell-agent-bridge');

        $this->registerInstalledRuntime(self::$packageName);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../../resources/views', 'capell-agent-bridge');
        Livewire::component('capell-agent-bridge.prompt-builder-toolbar-action', PromptBuilderToolbarAction::class);
    }

    protected function bootInstalledRuntime(): void
    {
        $this->app->singleton(CapellAgentBridgeCapabilityRegistry::class, fn (): CapellAgentBridgeCapabilityRegistry => new CapellAgentBridgeCapabilityRegistry);

        $this->publishes([
            __DIR__ . '/../../config/capell-agent-bridge.php' => config_path('capell-agent-bridge.php'),
        ], 'capell-agent-bridge-config');

        $router = $this->app->make(Router::class);
        $groups = new ReflectionProperty(Router::class, 'groupStack');
        $previous = $groups->getValue($router);
        $groups->setValue($router, []);
        try {
            $this->loadRoutesFrom(__DIR__ . '/../../routes/agent-bridge.php');
        } finally {
            $groups->setValue($router, $previous);
        }

        $this->registerModels();
        $this->registerSettingsIntegration();
        $this->registerAdminIntegration();
        $this->registerPolicyAbilities();
        $this->registerBoostIntegration();
        $this->registerBuiltInCapabilities();
        $this->registerTaggedCapabilityProviders();
        $this->registerCommands();
    }

    private function registerBoostIntegration(): void
    {
        if (! class_exists(Boost::class)) {
            return;
        }

        $includedTools = AgentBridgeValue::stringList(
            config('boost.agent-bridge.tools.include', []),
            'Boost Agent Bridge tools',
        );

        config([
            'boost.agent-bridge.tools.include' => array_values(array_unique([
                ...$includedTools,
                ListBoostCapabilitiesTool::class,
                PreviewBoostCapabilityTool::class,
            ])),
        ]);
    }

    private function registerPolicyAbilities(): void
    {
        Gate::define(
            AgentBridgePolicyAuthorizer::CLEAR_CACHE_ABILITY,
            static fn (mixed $user): bool => $user instanceof Authenticatable
                && resolve(AgentBridgePolicyAuthorizer::class)->isGlobalAdmin($user),
        );
    }

    private function registerModels(): void
    {
        CapellCore::registerModels([
            CapellAgentBridgeToken::class,
            CapellAgentBridgeConfirmation::class,
            CapellAgentBridgeMediaUpload::class,
            CapellAgentBridgeAuditEntry::class,
            CapellAgentBridgeSavedPrompt::class,
        ]);
    }

    private function registerAdminIntegration(): void
    {
        $adminFacade = CapellAdmin::class;
        $filamentPage = Page::class;
        $promptBuilderPage = CapellAgentBridgePromptBuilderPage::class;

        if (! class_exists($adminFacade) || ! class_exists($filamentPage)) {
            return;
        }

        if (! class_exists($promptBuilderPage)) {
            return;
        }

        if ($this->supportsAdminBridges()) {
            CapellAdmin::registerAdminBridge(self::$packageName, AgentBridgeAdminBridge::class);
            CapellAdmin::bootAdminBridges(self::$packageName);
        } else {
            $adminFacade::registerExtensionPage(self::$packageName, $promptBuilderPage);
            $this->registerUserResourceBridgeFallback();
        }

        if (! class_exists(FilamentView::class) || ! class_exists(PanelsRenderHook::class)) {
            return;
        }

        FilamentView::registerRenderHook(
            PanelsRenderHook::GLOBAL_SEARCH_BEFORE,
            fn (): string => Blade::render('@livewire($component)', ['component' => 'capell-agent-bridge.prompt-builder-toolbar-action']),
        );
    }

    private function registerSettingsIntegration(): void
    {
        $settings = AgentBridgeValue::stringList(config('settings.settings', []), 'Registered settings classes');

        if (! in_array(AgentBridgeSettings::class, $settings, true)) {
            $settings[] = AgentBridgeSettings::class;
        }

        config(['settings.settings' => $settings]);

        resolve(PackageSurfaceRegistrar::class)->settingsClass(AgentBridgeSettings::group(), AgentBridgeSettings::class);
        resolve(PackageSurfaceRegistrar::class)->settingsSchema(AgentBridgeSettings::group(), AgentBridgeSettingsSchema::class);

        CapellAdmin::registerExtensionManagementSurface(ExtensionManagementSurfaceData::settings(
            packageName: self::$packageName,
            label: 'capell-agent-bridge::admin.settings_title',
            settingsGroup: AgentBridgeSettings::group(),
            icon: Heroicon::OutlinedSparkles,
        ));

        resolve(PackageSurfaceRegistrar::class)->settingsMetadata(new SettingsGroupMetadata(
            group: AgentBridgeSettings::group(),
            label: 'capell-agent-bridge::admin.settings_title',
            icon: Heroicon::OutlinedSparkles,
            navigationGroup: 'capell-admin::navigation.group_system',
            navigationSort: 94,
            packageName: self::$packageName,
        ));
    }

    private function registerUserResourceBridgeFallback(): void
    {
        if (! interface_exists(UserResourceBridge::class)) {
            return;
        }

        $this->app->scoped(AgentBridgeUserSchemaExtender::class);
        $this->app->tag([AgentBridgeUserSchemaExtender::class], UserResourceBridge::TAG);
    }

    private function supportsAdminBridges(): bool
    {
        try {
            $admin = CapellAdmin::getFacadeRoot();
        } catch (Throwable) {
            return false;
        }

        return is_object($admin)
            && method_exists($admin, 'registerAdminBridge')
            && method_exists($admin, 'bootAdminBridges')
            && class_exists(AgentBridgeAdminBridge::class)
            && class_exists(AdminBridgeRegistrar::class);

    }

    private function registerBuiltInCapabilities(): void
    {
        resolve(BuiltInAgentBridgeCapabilityProvider::class)->registerCapabilities(
            $this->app->make(CapellAgentBridgeCapabilityRegistry::class),
        );
    }

    private function registerCommands(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            PruneAgentBridgeAuditEntriesCommand::class,
        ]);

        if (
            $this->app instanceof Application
            && $this->app->isBooted()
            && $this->app->bound('installed-runtime.initially-installed')
            && $this->app->make('installed-runtime.initially-installed') === false
        ) {
            Artisan::registerCommand($this->app->make(PruneAgentBridgeAuditEntriesCommand::class));
        }
    }

    private function registerTaggedCapabilityProviders(): void
    {
        $registry = $this->app->make(CapellAgentBridgeCapabilityRegistry::class);

        foreach ($this->app->tagged(CapellAgentBridgeCapabilityProvider::class) as $provider) {
            if ($provider instanceof CapellAgentBridgeCapabilityProvider) {
                $provider->registerCapabilities($registry);
            }
        }
    }
}

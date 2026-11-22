<?php

declare(strict_types=1);

use Capell\AgentBridge\Data\CapabilityData;
use Capell\AgentBridge\Enums\CapabilityRiskEnum;
use Capell\AgentBridge\Enums\CapabilityServerEnum;
use Capell\AgentBridge\Support\CapellAgentBridgeCapabilityRegistry;
use Capell\AgentBridge\Tests\Fixtures\FakeCapabilityAction;
use Capell\AgentBridge\Tests\Fixtures\InstalledAgentBridgePackageServiceProvider;
use Capell\AgentBridge\Tests\TestCase;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Config;
use Laravel\Boost\BoostServiceProvider;
use Symfony\Component\Console\Input\ArgvInput;

require dirname(__DIR__, 4) . '/vendor/autoload.php';

$fixture = new class('createApplication') extends TestCase
{
    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    #[Override]
    protected function getPackageProviders(mixed $app): array
    {
        // Boost only starts in a local or debug application outside Laravel's testing environment.
        $app->detectEnvironment(static fn (): string => 'local');
        Config::set(['boost.enabled' => true, 'boost.browser_logs_watcher' => false]);

        $providers = [...parent::getPackageProviders($app), BoostServiceProvider::class];
        if (getenv('AGENT_BRIDGE_FIXTURE_STATE') === 'disabled') {
            return array_values(array_diff($providers, [InstalledAgentBridgePackageServiceProvider::class]));
        }

        return $providers;
    }
};

$app = $fixture->createApplication();

if ($app->bound(CapellAgentBridgeCapabilityRegistry::class)) {
    $app->make(CapellAgentBridgeCapabilityRegistry::class)->register(new CapabilityData(
        key: 'capell.fixture.read',
        name: 'Fixture read capability',
        description: 'Preview the supplied fixture payload.',
        scope: 'capell.fixture.read',
        server: CapabilityServerEnum::Site,
        risk: CapabilityRiskEnum::Read,
        actionClass: FakeCapabilityAction::class,
    ));
}

$input = new ArgvInput;
if ($input->getFirstArgument() === 'boost:mcp') {
    // Boost starts its worker at base_path('artisan'). Use this same fixture in the worker.
    $hostPath = $app->storagePath();
    throw_unless(symlink(__FILE__, $hostPath . '/artisan'), RuntimeException::class, 'Could not link the Boost Artisan fixture.');
    $app->setBasePath($hostPath);
}

exit($app->handleCommand($input));

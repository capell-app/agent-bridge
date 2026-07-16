<?php

declare(strict_types=1);

use Capell\AgentBridge\Actions\Packages\InspectPackageCapabilityAction;
use Capell\AgentBridge\Actions\Packages\InstallPackageCapabilityAction;
use Capell\AgentBridge\Actions\Packages\SearchPackagesCapabilityAction;
use Capell\AgentBridge\Data\CapabilityData;
use Capell\AgentBridge\Data\CapabilityInvocationData;
use Capell\AgentBridge\Data\CapabilityResultData;
use Capell\AgentBridge\Enums\CapabilityRiskEnum;
use Capell\AgentBridge\Enums\CapabilityServerEnum;
use Capell\AgentBridge\Tests\Fixtures\FakeCapabilityAction;
use Capell\AgentBridge\Tests\Fixtures\User;
use Capell\Core\Enums\PackageTypeEnum;
use Capell\Core\Support\CapellCoreManager;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    Gate::before(static fn (): bool => true);
});

it('searches and inspects discovered packages without exposing implementation paths', function (): void {
    $manager = resolve(CapellCoreManager::class);
    $manager->registerPackage(
        name: 'vendor/analytics-suite',
        type: PackageTypeEnum::Plugin,
        description: 'Privacy-aware analytics reports.',
    );
    $manager->forcePackageInstalled('vendor/analytics-suite', false);

    $search = (new SearchPackagesCapabilityAction)->execute(packageInvocation(
        'capell.packages.search',
        ['query' => 'analytics'],
    ));
    $inspect = (new InspectPackageCapabilityAction)->execute(packageInvocation(
        'capell.packages.inspect',
        ['package' => 'Analytics Suite'],
    ));

    expect($search->data['packages'])->toHaveCount(1)
        ->and($search->data['packages'][0])->toMatchArray([
            'name' => 'vendor/analytics-suite',
            'label' => 'Analytics Suite',
            'installed' => false,
            'composer_available' => false,
            'installable' => false,
        ])->not->toHaveKeys(['path', 'service_provider', 'install_command'])
        ->and($inspect->data['package']['install_impact'])
        ->toHaveKeys([
            'requirements',
            'missing_requirements',
            'conflicts',
            'scopes',
            'schema_migrations',
            'settings_migrations',
            'permissions',
        ]);
});

it('installs only a Composer-present discovered package after an impact preview', function (): void {
    $manager = resolve(CapellCoreManager::class);
    $manager->registerPackage(
        name: 'vendor/agent-installable',
        type: PackageTypeEnum::Plugin,
        path: dirname(__DIR__) . '/Fixtures/installable-package',
        description: 'Safe local install fixture.',
    );
    $manager->forcePackageInstalled('vendor/agent-installable', false);
    $invocation = packageInvocation('capell.packages.install', [
        'package' => 'Agent Installable',
    ]);
    $action = new InstallPackageCapabilityAction;

    $preview = $action->preview($invocation);

    expect($preview->ok)->toBeTrue()
        ->and($preview->data['source_policy'])->toBe('composer-present-only')
        ->and($preview->data['package'])->toMatchArray([
            'name' => 'vendor/agent-installable',
            'composer_available' => true,
            'installable' => true,
        ])
        ->and($preview->warnings)->toHaveCount(1)
        ->and($manager->isPackageInstalled('vendor/agent-installable'))->toBeFalse();

    $result = $action->execute($invocation);

    expect($result)->toBeInstanceOf(CapabilityResultData::class)
        ->and($result->ok)->toBeTrue()
        ->and($result->data['package']['installed'])->toBeTrue()
        ->and($manager->isPackageInstalled('vendor/agent-installable'))->toBeTrue();
});

it('refuses to install a registered package whose code is not in Composer', function (): void {
    $manager = resolve(CapellCoreManager::class);
    $manager->registerPackage(
        name: 'vendor/remote-only',
        type: PackageTypeEnum::Plugin,
    );
    $manager->forcePackageInstalled('vendor/remote-only', false);

    expect(fn (): CapabilityResultData => (new InstallPackageCapabilityAction)->preview(packageInvocation(
        'capell.packages.install',
        ['package' => 'vendor/remote-only'],
    )))->toThrow(ValidationException::class, 'will not download remote code');
});

/** @param array<string, mixed> $payload */
function packageInvocation(string $capabilityKey, array $payload): CapabilityInvocationData
{
    return new CapabilityInvocationData(
        capability: new CapabilityData(
            key: $capabilityKey,
            name: 'Package capability test',
            description: 'Package capability test.',
            scope: 'capell.packages.test',
            server: CapabilityServerEnum::Site,
            risk: CapabilityRiskEnum::High,
            actionClass: FakeCapabilityAction::class,
        ),
        payload: $payload,
        user: new User(['id' => 1, 'name' => 'Package admin']),
    );
}

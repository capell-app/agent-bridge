<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Support;

use BackedEnum;
use Capell\Core\Data\PackageData;
use Capell\Core\Support\CapellCoreManager;

final class AgentBridgePackagePresenter
{
    /** @return array<string, mixed> */
    public function present(PackageData $package, bool $includeInstallImpact = false): array
    {
        $manager = resolve(CapellCoreManager::class);
        $installed = $manager->isPackageInstalled($package->name);
        $available = $manager->isPackageAvailable($package->name);
        $missingRequirements = $manager->getMissingRequirements($package->name);
        $installedConflicts = array_values(array_filter(
            $package->conflicts,
            fn (string $conflict): bool => $manager->isPackageInstalled($conflict),
        ));
        $payload = [
            'name' => $package->name,
            'label' => $package->getLabel(),
            'description' => $package->getDescription(),
            'kind' => $package->getKind(),
            'product_group' => $package->getProductGroup(),
            'tier' => $package->getTier(),
            'version' => $package->version,
            'installed' => $installed,
            'composer_available' => $available,
            'installable' => ! $installed
                && $available
                && $missingRequirements === []
                && $installedConflicts === []
                && $manager->canInstallPackage($package->name),
            'documentation_url' => $package->getDocumentationUrl(),
        ];

        if (! $includeInstallImpact) {
            return $payload;
        }

        $payload['install_impact'] = [
            'requirements' => $package->getRequirements(),
            'missing_requirements' => $missingRequirements,
            'conflicts' => $package->conflicts,
            'installed_conflicts' => $installedConflicts,
            'scopes' => array_map(
                static fn (BackedEnum $scope): string => (string) $scope->value,
                $package->getScopes(),
            ),
            'schema_migrations' => $package->declaresSchemaMigrations(),
            'settings_migrations' => $package->declaresSettingsMigrations(),
            'required_arguments' => $package->getInstallParams(),
            'permissions' => $package->permissions,
            'contribution_count' => count($package->getContributions()),
            'public_output' => $package->hasFrontendScope(),
        ];

        return $payload;
    }
}

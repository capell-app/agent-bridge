<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Support;

use Capell\Core\Data\PackageData;
use Capell\Core\Support\CapellCoreManager;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class AgentBridgePackageResolver
{
    /**
     * @return Collection<int, PackageData>
     */
    public function search(
        ?string $query = null,
        string $status = 'all',
        bool $includeCore = false,
        int $limit = 20,
    ): Collection {
        $manager = resolve(CapellCoreManager::class);
        $needle = $this->normalize((string) $query);
        $limit = max(1, min(100, $limit));

        return $manager->getPackages(withoutCore: ! $includeCore)
            ->filter(static fn (PackageData $package): bool => $package->isVisibleInCatalogue() && ! $package->isHiddenFromMarketplace())
            ->filter(fn (PackageData $package): bool => $this->matchesStatus($manager, $package, $status))
            ->filter(fn (PackageData $package): bool => $needle === '' || $this->matches($package, $needle, exact: false))
            ->sortBy(fn (PackageData $package): string => sprintf(
                '%d-%s',
                $manager->isPackageInstalled($package->name) ? 0 : 1,
                $this->normalize($package->getLabel()),
            ))
            ->take($limit)
            ->values();
    }

    public function package(string $reference): PackageData
    {
        $reference = trim($reference);

        if ($reference === '') {
            throw ValidationException::withMessages([
                'package' => __('capell-agent-bridge::admin.capability_package_not_found', ['package' => $reference]),
            ]);
        }

        $packages = resolve(CapellCoreManager::class)->getPackages(withoutCore: false)->values();
        $needle = $this->normalize($reference);
        $exact = $packages
            ->filter(fn (PackageData $package): bool => $this->matches($package, $needle, exact: true))
            ->values();

        if ($exact->count() === 1) {
            return $exact->firstOrFail();
        }

        $partial = $packages
            ->filter(fn (PackageData $package): bool => $this->matches($package, $needle, exact: false))
            ->values();

        if ($partial->count() === 1) {
            return $partial->firstOrFail();
        }

        $matches = $exact->isNotEmpty() ? $exact : $partial;

        throw ValidationException::withMessages([
            'package' => $matches->isEmpty()
                ? __('capell-agent-bridge::admin.capability_package_not_found', ['package' => $reference])
                : __('capell-agent-bridge::admin.capability_package_ambiguous', [
                    'packages' => $matches
                        ->take(10)
                        ->map(static fn (PackageData $package): string => sprintf('%s (%s)', $package->getLabel(), $package->name))
                        ->implode(', '),
                ]),
        ]);
    }

    private function matchesStatus(CapellCoreManager $manager, PackageData $package, string $status): bool
    {
        return match ($status) {
            'installed' => $manager->isPackageInstalled($package->name),
            'available' => ! $manager->isPackageInstalled($package->name) && $manager->isPackageAvailable($package->name),
            default => true,
        };
    }

    private function matches(PackageData $package, string $needle, bool $exact): bool
    {
        $values = array_filter([
            $this->normalize($package->name),
            $this->normalize($package->getLabel()),
            $this->normalize((string) $package->key),
            $this->normalize((string) $package->getThemeKey()),
        ]);

        if ($exact) {
            return in_array($needle, $values, true);
        }

        $haystack = implode(' ', [
            ...$values,
            $this->normalize((string) $package->getDescription()),
            $this->normalize($package->getProductGroup()),
        ]);

        return str_contains($haystack, $needle);
    }

    private function normalize(string $value): string
    {
        return mb_strtolower(trim($value));
    }
}

<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Data\Capabilities;

use Spatie\LaravelData\Data;

final class InstallPackageCapabilityInputData extends Data
{
    /** @param array<string, mixed> $arguments */
    public function __construct(
        public readonly string $package,
        public readonly array $arguments = [],
    ) {}
}

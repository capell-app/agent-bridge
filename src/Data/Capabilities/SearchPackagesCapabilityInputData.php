<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Data\Capabilities;

use Spatie\LaravelData\Data;

final class SearchPackagesCapabilityInputData extends Data
{
    public function __construct(
        public readonly ?string $query = null,
        public readonly string $status = 'all',
        public readonly bool $include_core = false,
        public readonly int $limit = 20,
    ) {}
}

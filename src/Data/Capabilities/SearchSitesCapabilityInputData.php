<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Data\Capabilities;

use Spatie\LaravelData\Data;

final class SearchSitesCapabilityInputData extends Data
{
    public function __construct(
        public readonly ?string $query = null,
        public readonly int $limit = 20,
    ) {}
}

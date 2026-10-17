<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Tests\Fixtures;

use Capell\Admin\Support\CapellAdminManager;
use Capell\Tests\Support\LegacyAdminBridgeFallbackHost;

final class LegacyAgentBridgeAdminHost
{
    public readonly LegacyAdminBridgeFallbackHost $legacy;

    public function __construct(private readonly CapellAdminManager $admin)
    {
        $this->legacy = new LegacyAdminBridgeFallbackHost;
    }

    /** @param list<mixed> $arguments */
    public function __call(string $method, array $arguments): mixed
    {
        $target = is_callable([$this->legacy, $method]) ? $this->legacy : $this->admin;

        return $target->{$method}(...$arguments);
    }
}

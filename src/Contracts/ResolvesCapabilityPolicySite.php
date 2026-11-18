<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Contracts;

/**
 * Implemented by a capability action whose policy ability is held per site.
 *
 * Permission teams follow the admin's selected site in a request and no site in a
 * process without one, so a policy check made outside the capability's own site reads
 * the wrong team. An action that names its site here has its ability checked in that
 * site's team on every entry point, which agrees with the check the action makes itself.
 */
interface ResolvesCapabilityPolicySite
{
    /**
     * The site the payload reaches, or null when none is known. Null is read outside
     * every site team, so only a permission held for the whole installation counts.
     *
     * @param  array<array-key, mixed>  $payload
     */
    public static function policySiteId(array $payload): ?int;
}

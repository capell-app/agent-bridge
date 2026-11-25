<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Bridges\Navigation;

use Capell\Core\Facades\CapellCore;
use Capell\Navigation\Models\Navigation;

/** Names the Navigation model for the site-state tool, or nothing when Navigation is not available. */
final class NavigationSiteStateBridge
{
    /** @return class-string<Navigation>|null */
    public static function modelClass(): ?string
    {
        if (! CapellCore::isPackageAvailable('capell-app/navigation')) {
            return null;
        }

        return Navigation::class;
    }
}

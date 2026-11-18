<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Tests\Fixtures;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Collection;

/**
 * A user assigned to sites 1 and 2 wherever it is loaded from, so a check that reloads
 * a token's owner from storage still sees the owner's site access.
 */
final class SiteAssignedUser extends Authenticatable
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    protected $table = 'users';

    protected $guarded = [];

    /** @return Collection<int, int> */
    public function getAssignedSiteIds(): Collection
    {
        return collect([1, 2]);
    }
}

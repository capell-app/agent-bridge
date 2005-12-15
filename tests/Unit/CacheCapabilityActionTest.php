<?php

declare(strict_types=1);

use Capell\AgentBridge\Actions\Cache\ClearCapellCacheCapabilityAction;
use Capell\AgentBridge\Data\CapabilityData;
use Capell\AgentBridge\Data\CapabilityInvocationData;
use Capell\AgentBridge\Enums\CapabilityRiskEnum;
use Capell\AgentBridge\Enums\CapabilityServerEnum;
use Capell\AgentBridge\Tests\Fixtures\FakeCapabilityAction;
use Capell\AgentBridge\Tests\Fixtures\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Artisan;

it('previews registered cache clear commands before executing them', function (): void {
    $result = (new ClearCapellCacheCapabilityAction)->preview(cacheInvocation());

    expect($result->ok)->toBeTrue()
        ->and($result->message)->toBe(__('capell-agent-bridge::admin.result_cache_clear_preview'))
        ->and($result->data['commands'])->toContain('cache:clear');
});

it('executes registered cache clear commands and returns command output', function (): void {
    $result = (new ClearCapellCacheCapabilityAction)->execute(cacheInvocation());

    expect($result->ok)->toBeTrue()
        ->and($result->message)->toBe(__('capell-agent-bridge::admin.result_cache_cleared'))
        ->and($result->data['results'])->toHaveKey('cache:clear');
});

it('rejects cache operations for non global administrators', function (): void {
    $user = new User;

    (new ClearCapellCacheCapabilityAction)->preview(cacheInvocation($user));
})->throws(AuthorizationException::class);

it('reports failed cache clear commands instead of returning success', function (int $exitCode): void {
    Artisan::swap(Mockery::mock(ConsoleKernel::class));
    Artisan::shouldReceive('all')->once()->andReturn(['cache:clear' => new stdClass, 'capell:admin-clear-cache' => new stdClass]);
    Artisan::shouldReceive('call')->once()->with('cache:clear')->andReturn(0);
    Artisan::shouldReceive('call')->once()->with('capell:admin-clear-cache')->andReturn($exitCode);
    Artisan::shouldReceive('output')->twice()->andReturn('Cache cleared.', 'Origin cache deletion failed.');

    $result = new ClearCapellCacheCapabilityAction()->execute(cacheInvocation());

    expect($result->ok)->toBeFalse()
        ->and($result->message)->toBe(__('capell-agent-bridge::admin.result_cache_clear_failed'))
        ->and($result->data['exit_codes'])->toBe(['cache:clear' => 0, 'capell:admin-clear-cache' => $exitCode])
        ->and($result->data['results'])->toBe(['cache:clear' => 'Cache cleared.', 'capell:admin-clear-cache' => 'Origin cache deletion failed.']);
})->with([1, 2]);

function cacheInvocation(?User $user = null): CapabilityInvocationData
{
    if (! $user instanceof User) {
        $user = new User;
        $user->globalAdmin = true;
    }

    return new CapabilityInvocationData(
        capability: new CapabilityData(
            key: 'capell.cache.clear',
            name: 'Clear cache',
            description: 'Clear cache capability.',
            scope: 'capell.cache.clear',
            server: CapabilityServerEnum::Site,
            risk: CapabilityRiskEnum::High,
            actionClass: FakeCapabilityAction::class,
        ),
        payload: [],
        user: $user,
    );
}

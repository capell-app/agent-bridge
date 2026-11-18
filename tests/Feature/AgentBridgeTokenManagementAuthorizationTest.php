<?php

declare(strict_types=1);

use Capell\Admin\Filament\Resources\Users\Pages\EditUser;
use Capell\AgentBridge\Actions\CreateAgentBridgeTokenAction;
use Capell\AgentBridge\Filament\Resources\Users\RelationManagers\AgentBridgeTokensRelationManager;
use Capell\AgentBridge\Models\CapellAgentBridgeToken;
use Capell\AgentBridge\Support\AgentBridgeTokenManagementAccess;
use Capell\AgentBridge\Tests\Fixtures\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

beforeEach(function (): void {
    $panel = Panel::make()->id('admin')->path('admin')->default();
    Filament::registerPanel($panel);
    Filament::setCurrentPanel($panel);
    Filament::bootCurrentPanel();
    Filament::setServingStatus();
});

function tokenManagementUser(string $email): User
{
    return User::query()->create(['name' => $email, 'email' => $email, 'password' => 'secret']);
}

/**
 * @param  list<string>  $abilities
 */
function grantTokenManagementAbilities(Model $actor, Model $owner, array $abilities): void
{
    Gate::before(static function (mixed $user, string $ability, array $arguments) use ($actor, $owner, $abilities): ?bool {
        if (! $user instanceof Model || ! $user->is($actor)) {
            return null;
        }

        // The host's user policy decides "update"; this actor is refused unless granted it here.
        if (! in_array($ability, $abilities, true)) {
            return $ability === 'update' ? false : null;
        }

        if ($ability === 'update') {
            return ($arguments[0] ?? null) instanceof Model && $arguments[0]->is($owner) ? true : null;
        }

        return true;
    });
}

it('refuses to mint a token for another user from their user page', function (): void {
    $owner = tokenManagementUser('super-admin@example.test');
    $this->actingAs(tokenManagementUser('editor@example.test'));

    Livewire::test(AgentBridgeTokensRelationManager::class, ['ownerRecord' => $owner, 'pageClass' => EditUser::class])
        ->assertActionHidden(TestAction::make('createToken')->table());

    expect(CapellAgentBridgeToken::query()->count())->toBe(0);
});

it('refuses to rotate or revoke another user\'s token', function (string $action): void {
    $owner = tokenManagementUser('owner@example.test');
    $token = CreateAgentBridgeTokenAction::run($owner, 'Owner client', ['capell.pages.read'])['token'];
    $before = $token->fresh()?->getRawOriginal();
    $this->actingAs(tokenManagementUser('other-admin@example.test'));

    Livewire::test(AgentBridgeTokensRelationManager::class, ['ownerRecord' => $owner, 'pageClass' => EditUser::class])
        ->assertActionHidden(TestAction::make($action)->table($token));

    expect($token->fresh()?->getRawOriginal())->toBe($before);
})->with(['rotate', 'revoke']);

it('needs both the token permission and the right to edit the owner', function (string $grantedAbility): void {
    $abilities = [$grantedAbility];
    $owner = tokenManagementUser('owner-two@example.test');
    $actor = tokenManagementUser('partial-admin@example.test');
    grantTokenManagementAbilities($actor, $owner, $abilities);
    $this->actingAs($actor);

    Livewire::test(AgentBridgeTokensRelationManager::class, ['ownerRecord' => $owner, 'pageClass' => EditUser::class])
        ->assertActionHidden(TestAction::make('createToken')->table());

    expect(CapellAgentBridgeToken::query()->count())->toBe(0);
})->with([
    'permission only' => [AgentBridgeTokenManagementAccess::MANAGE_PERMISSION],
    'update only' => ['update'],
]);

it('lets a token manager who may edit the owner create a token for them', function (): void {
    $owner = tokenManagementUser('managed@example.test');
    $actor = tokenManagementUser('token-manager@example.test');
    grantTokenManagementAbilities($actor, $owner, [AgentBridgeTokenManagementAccess::MANAGE_PERMISSION, 'update']);
    $this->actingAs($actor);

    Livewire::test(AgentBridgeTokensRelationManager::class, ['ownerRecord' => $owner, 'pageClass' => EditUser::class])
        ->callAction(TestAction::make('createToken')->table(), ['name' => 'Managed client', 'scopes' => ['capell.pages.read']]);

    expect(CapellAgentBridgeToken::query()->sole()->user_id)->toBe($owner->getKey());
});

it('lets owners create and rotate their own tokens', function (): void {
    $owner = tokenManagementUser('self@example.test');
    $this->actingAs($owner);
    $parameters = ['ownerRecord' => $owner, 'pageClass' => EditUser::class];

    Livewire::test(AgentBridgeTokensRelationManager::class, $parameters)
        ->callAction(TestAction::make('createToken')->table(), ['name' => 'Own client', 'scopes' => ['capell.pages.read']]);

    $token = CapellAgentBridgeToken::query()->sole();
    $hash = $token->token_hash;

    Livewire::test(AgentBridgeTokensRelationManager::class, $parameters)
        ->callAction(TestAction::make('rotate')->table($token));

    expect($token->fresh()?->token_hash)->not->toBe($hash);
});

it('refuses token management inside the action even when the button is reached', function (): void {
    $owner = tokenManagementUser('direct-owner@example.test');
    $intruder = tokenManagementUser('direct-intruder@example.test');

    expect(AgentBridgeTokenManagementAccess::allows($intruder, $owner))->toBeFalse()
        ->and(AgentBridgeTokenManagementAccess::allows(null, $owner))->toBeFalse()
        ->and(AgentBridgeTokenManagementAccess::allows($owner, $owner))->toBeTrue()
        ->and(fn () => AgentBridgeTokenManagementAccess::authorize($intruder, $owner))->toThrow(AuthorizationException::class);
});

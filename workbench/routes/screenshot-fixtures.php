<?php

declare(strict_types=1);

use Capell\AgentBridge\Actions\PrepareAgentBridgeScreenshotAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->get('/screenshot-fixtures/agent-bridge/user', static function (): RedirectResponse {
    $user = auth()->user();
    abort_unless($user instanceof Model, 403);
    app(PrepareAgentBridgeScreenshotAction::class)->handle($user);
    $routeKey = $user->getRouteKey();
    throw_unless(is_int($routeKey) || is_string($routeKey), RuntimeException::class, 'The Agent Bridge screenshot user route key must be scalar.');

    return redirect('/admin/users/' . $routeKey . '/edit');
});

<?php

declare(strict_types=1);

use Capell\AgentBridge\Actions\InterpretAgentBridgeCommandAction;

it('turns common voice and text requests into exact capability plans', function (
    string $command,
    string $capabilityKey,
    array $payload,
): void {
    $plan = InterpretAgentBridgeCommandAction::run($command);

    expect($plan->matched)->toBeTrue()
        ->and($plan->capabilityKey)->toBe($capabilityKey)
        ->and($plan->payload)->toBe($payload)
        ->and($plan->confidence)->toBe(1.0);
})->with([
    'open a page by title' => [
        'Open the About page',
        'capell.pages.open_editor',
        ['page' => 'About'],
    ],
    'create a draft page' => [
        'Create a draft page called Summer Campaign',
        'capell.pages.create_draft',
        ['name' => 'Summer Campaign', 'title' => 'Summer Campaign'],
    ],
    'clone an internal page' => [
        'Clone the Services page as Consulting',
        'capell.pages.clone',
        ['page' => 'Services', 'name' => 'Consulting', 'title' => 'Consulting'],
    ],
    'rename a page' => [
        'Update the About page title to About Capell',
        'capell.pages.update_draft',
        ['page' => 'About', 'title' => 'About Capell'],
    ],
    'publish a page' => [
        'Publish the Launch page now',
        'capell.pages.publish_now',
        ['page' => 'Launch'],
    ],
    'schedule publication' => [
        'Schedule the Launch page to publish at 2026-08-01 09:00',
        'capell.pages.schedule_publish',
        ['page' => 'Launch', 'publish_at' => '2026-08-01 09:00'],
    ],
    'open a site record by name' => [
        'Edit the Main website site record',
        'capell.sites.open_editor',
        ['site' => 'Main website'],
    ],
    'install a package' => [
        'Install the package SEO Suite',
        'capell.packages.install',
        ['package' => 'SEO Suite'],
    ],
    'clear caches' => [
        'Refresh the Capell cache',
        'capell.cache.clear',
        [],
    ],
]);

it('returns ranked capability suggestions instead of guessing an unmatched command', function (): void {
    $plan = InterpretAgentBridgeCommandAction::run('Help me improve the About page');

    expect($plan->matched)->toBeFalse()
        ->and($plan->capabilityKey)->toBeNull()
        ->and($plan->confidence)->toBe(0.0)
        ->and($plan->suggestions)->toBeArray();
});

it('does not plan blank commands', function (): void {
    $plan = InterpretAgentBridgeCommandAction::run('   ');

    expect($plan->matched)->toBeFalse()
        ->and($plan->suggestions)->toBe([]);
});

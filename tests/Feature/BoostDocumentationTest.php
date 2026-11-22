<?php

declare(strict_types=1);

use Capell\AgentBridge\Data\CapabilityData;
use Capell\AgentBridge\Enums\CapabilityRiskEnum;
use Capell\AgentBridge\Enums\CapabilityServerEnum;
use Capell\AgentBridge\Support\CapellAgentBridgeCapabilityRegistry;
use Capell\AgentBridge\Tests\Fixtures\RecordingCapabilityAction;
use Capell\AgentBridge\Tools\Boost\ListBoostCapabilitiesTool;
use Capell\AgentBridge\Tools\Boost\PreviewBoostCapabilityTool;
use Laravel\Mcp\Request;

function boostWorkflowCapability(string $key, CapabilityServerEnum $server, CapabilityRiskEnum $risk): CapabilityData
{
    return new CapabilityData(
        key: $key,
        name: 'Capability ' . $key,
        description: 'Capability for the documented Boost workflow.',
        scope: $key,
        server: $server,
        risk: $risk,
        actionClass: RecordingCapabilityAction::class,
    );
}

beforeEach(fn () => RecordingCapabilityAction::reset());

it('advertises the tool names the Boost guide tells an agent to call, as non-mutating tools', function (): void {
    $list = (new ListBoostCapabilitiesTool)->toArray();
    $preview = (new PreviewBoostCapabilityTool)->toArray();

    expect(data_get($list, 'name'))->toBe('capell-list-capabilities')
        ->and(data_get($preview, 'name'))->toBe('capell-preview-capability')
        ->and(data_get($list, 'annotations.readOnlyHint'))->toBeTrue()
        ->and(data_get($preview, 'annotations.destructiveHint'))->toBeFalse()
        ->and(array_keys((array) data_get($preview, 'inputSchema.properties')))->toEqualCanonicalizing(['capability', 'payload']);
});

it('lists only capabilities visible on the site server for the local agent', function (): void {
    $registry = new CapellAgentBridgeCapabilityRegistry;
    $registry->register(boostWorkflowCapability('capell.test.site', CapabilityServerEnum::Site, CapabilityRiskEnum::Read));
    $registry->register(boostWorkflowCapability('capell.test.knowledge', CapabilityServerEnum::Knowledge, CapabilityRiskEnum::Read));

    $content = (new ListBoostCapabilitiesTool)->handle($registry)->getStructuredContent();

    expect(array_column((array) data_get($content, 'capabilities'), 'key'))->toBe(['capell.test.site'])
        ->and(data_get($content, 'confirmation'))->toContain('authenticated Capell Site Agent Bridge');
});

it('previews a read capability for the local agent without executing it', function (): void {
    $registry = new CapellAgentBridgeCapabilityRegistry;
    $registry->register(boostWorkflowCapability('capell.test.read', CapabilityServerEnum::Site, CapabilityRiskEnum::Read));

    $content = (new PreviewBoostCapabilityTool)
        ->handle(new Request(['capability' => 'capell.test.read', 'payload' => ['name' => 'Example']]), $registry)
        ->getStructuredContent();

    expect(data_get($content, 'mode'))->toBe('preview')
        ->and(data_get($content, 'capability'))->toBe('capell.test.read')
        ->and(data_get($content, 'preview.data.payload'))->toBe(['name' => 'Example'])
        ->and(RecordingCapabilityAction::$previews)->toBe(1)
        ->and(RecordingCapabilityAction::$executions)->toBe(0);
});

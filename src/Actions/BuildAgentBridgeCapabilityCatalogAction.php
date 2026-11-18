<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Actions;

use Capell\AgentBridge\Data\CapabilityData;
use Capell\AgentBridge\Support\CapellAgentBridgeCapabilityRegistry;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static list<array<string, mixed>> run()
 */
final class BuildAgentBridgeCapabilityCatalogAction
{
    use AsFake;
    use AsObject;

    /**
     * Projects what an agent needs to choose and call a capability. Class names, policy
     * abilities, required packages and audit events describe the host application, so
     * the projection never carries them.
     *
     * @return list<array<string, mixed>>
     */
    public function handle(?CapellAgentBridgeCapabilityRegistry $registry = null): array
    {
        $registry ??= resolve(CapellAgentBridgeCapabilityRegistry::class);

        return array_values($registry
            ->all()
            ->sortBy(fn (CapabilityData $capability): string => $capability->server->value . ':' . $capability->scope . ':' . $capability->key)
            ->map(fn (CapabilityData $capability): array => [
                'key' => $capability->key,
                'name' => $capability->name,
                'scope' => $capability->scope,
                'server' => $capability->server->value,
                'risk' => $capability->risk->value,
                'requires_confirmation' => $capability->needsConfirmation(),
                'supports_preview' => $capability->supportsPreview,
                'input_schema' => $capability->inputSchema,
                'output_schema' => $capability->outputSchema,
            ])
            ->values()
            ->all());
    }
}

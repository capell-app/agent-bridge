<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Tools\Boost;

use Capell\AgentBridge\Data\CapabilityData;
use Capell\AgentBridge\Data\CapabilityInvocationData;
use Capell\AgentBridge\Enums\CapabilityRiskEnum;
use Capell\AgentBridge\Enums\CapabilityServerEnum;
use Capell\AgentBridge\Support\AgentBridgeCapabilityPolicy;
use Capell\AgentBridge\Support\AgentBridgeContentTrust;
use Capell\AgentBridge\Support\AgentBridgeValue;
use Capell\AgentBridge\Support\CapellAgentBridgeCapabilityRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Override;

#[Name('capell-preview-capability')]
#[Title('Preview Capell Capability')]
#[Description('Preview a registered Capell Agent Bridge capability from the Laravel Boost Agent Bridge server. Mutating capabilities return preview data only and must be confirmed through the authenticated Capell Site Agent Bridge server.')]
#[IsDestructive(false)]
final class PreviewBoostCapabilityTool extends Tool
{
    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'capability' => $schema->string()->description('Registered Capell capability key.')->required(),
            'payload' => $schema->object()->description('Capability payload.')->required(),
        ];
    }

    public function handle(Request $request, CapellAgentBridgeCapabilityRegistry $registry): ResponseFactory
    {
        return $this->previewCapability($request, $registry);
    }

    public function previewCapability(Request $request, CapellAgentBridgeCapabilityRegistry $registry): ResponseFactory
    {
        $data = $request->validate([
            'capability' => ['required', 'string'],
            'payload' => ['required', 'array'],
        ]);

        $capability = $registry->get(AgentBridgeValue::string($data['capability'] ?? null, 'Capability key'));
        $payload = AgentBridgeValue::associativeArray($data['payload'] ?? null, 'Capability payload');
        $this->authorizePreview($registry, $capability, $request->user(), $payload);

        $action = resolve($capability->actionClass);
        $preview = $action->preview(new CapabilityInvocationData(
            capability: $capability,
            payload: $payload,
            user: $request->user(),
        ));

        return Response::structured([
            'mode' => 'preview',
            'capability' => $capability->key,
            'preview' => $preview->toPayload(),
            'confirmation' => 'Use the authenticated Capell Site Agent Bridge server to confirm mutating capability previews.',
            'contentTrust' => AgentBridgeContentTrust::forCapabilityResult('preview'),
        ]);
    }

    /**
     * A Boost client carries no Agent Bridge token, so the preview is limited to what the
     * acting user could already do: the capability must be visible on the site server, a
     * policy ability must pass for the user, and a capability that changes data and names
     * no ability is refused because nothing could vouch for the caller.
     */
    /**
     * @param  array<array-key, mixed>  $payload
     */
    private function authorizePreview(CapellAgentBridgeCapabilityRegistry $registry, CapabilityData $capability, ?Authenticatable $user, array $payload): void
    {
        $visible = $registry
            ->visibleFor(CapabilityServerEnum::Site)
            ->contains(static fn (CapabilityData $candidate): bool => $candidate->key === $capability->key);

        throw_unless($visible, AuthorizationException::class, sprintf('Capability [%s] is not available.', $capability->key));

        if ($capability->policyAbility !== null) {
            throw_unless(
                $user instanceof Authenticatable,
                AuthorizationException::class,
                sprintf('Capability [%s] needs an authenticated user.', $capability->key),
            );

            AgentBridgeCapabilityPolicy::authorize($capability, $user, $payload);

            return;
        }

        throw_unless(
            $capability->risk === CapabilityRiskEnum::Read,
            AuthorizationException::class,
            sprintf('Capability [%s] can change data and is previewed only through the authenticated Site server.', $capability->key),
        );
    }
}

<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Tools\Site;

use Capell\AgentBridge\Actions\InvokeAgentBridgeMcpV1CapabilityAction;
use Capell\AgentBridge\Data\AuthenticatedAgentBridgeClientData;
use Capell\AgentBridge\Models\CapellAgentBridgeToken;
use Capell\AgentBridge\Support\AgentBridgeMcpV1ErrorMapper;
use Capell\AgentBridge\Support\AgentBridgeValue;
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
use Throwable;

#[Name('capell-site-run-capability')]
#[Title('Run Site Capability Preview')]
#[Description('Preview or directly execute a registered Capell site capability. Mutating capabilities return a confirmation token.')]
// This tool can reach destructive capabilities: read-risk / no-confirmation capabilities execute directly,
// while mutating ones return a confirmation token. The annotation reflects the worst-case reachable behaviour
// so MCP clients treat the tool as destructive rather than trusting an understated read-only hint.
#[IsDestructive]
final class RunSiteCapabilityTool extends Tool
{
    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'capability' => $schema->string()->description('Registered capability key.')->required(),
            'payload' => $schema->object()->description('Capability payload.')->required(),
        ];
    }

    public function handle(Request $request, AuthenticatedAgentBridgeClientData $client, CapellAgentBridgeToken $token): ResponseFactory
    {
        try {
            $data = $request->validate([
                'capability' => ['required', 'string', 'max:64', 'regex:/^capell\.[a-z0-9_.]+$/'],
                'payload' => ['required', 'array'],
            ]);
            $result = InvokeAgentBridgeMcpV1CapabilityAction::run(
                capabilityKey: AgentBridgeValue::string($data['capability'] ?? null, 'Capability key'),
                payload: AgentBridgeValue::associativeArray($data['payload'] ?? null, 'Capability payload'),
                client: $client,
                token: $token,
                user: $request->user(),
            );
        } catch (Throwable $exception) {
            $result = resolve(AgentBridgeMcpV1ErrorMapper::class)->map($exception);
        }

        return Response::structured($result);
    }
}

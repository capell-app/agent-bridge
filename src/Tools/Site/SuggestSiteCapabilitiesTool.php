<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Tools\Site;

use Capell\AgentBridge\Actions\SuggestAgentBridgeCapabilitiesAction;
use Capell\AgentBridge\Data\AuthenticatedAgentBridgeClientData;
use Capell\AgentBridge\Enums\CapabilityServerEnum;
use Capell\AgentBridge\Support\AgentBridgeValue;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Override;

#[Name('capell-site-suggest-capabilities')]
#[Title('Suggest Site Capabilities')]
#[Description('Match a natural-language or voice-transcribed request to the Capell site capabilities available to this client.')]
#[IsReadOnly]
final class SuggestSiteCapabilitiesTool extends Tool
{
    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Natural-language request or partial command. Leave empty for popular actions.'),
            'limit' => $schema->integer()->description('Maximum suggestions to return, from 1 to 20.'),
        ];
    }

    public function handle(
        Request $request,
        AuthenticatedAgentBridgeClientData $client,
    ): ResponseFactory {
        $data = $request->validate([
            'query' => ['sometimes', 'nullable', 'string', 'max:500'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:20'],
        ]);

        return Response::structured(SuggestAgentBridgeCapabilitiesAction::run(
            query: trim(AgentBridgeValue::nullableString($data['query'] ?? null, 'Suggestion query') ?? ''),
            server: CapabilityServerEnum::Site,
            scopes: array_values($client->scopes),
            capabilityKeys: $client->capabilityKeys,
            limit: AgentBridgeValue::integer($data['limit'] ?? 5, 'Suggestion limit'),
        ));
    }
}

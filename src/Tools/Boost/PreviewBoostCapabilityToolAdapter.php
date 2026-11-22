<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Tools\Boost;

use Capell\AgentBridge\Support\CapellAgentBridgeCapabilityRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Override;
use UnexpectedValueException;

#[Name('capell-preview-capability')]
#[Title('Preview Capell Capability')]
#[Description('Preview a registered Capell Agent Bridge capability from the Laravel Boost Agent Bridge server. Mutating capabilities return preview data only and must be confirmed through the authenticated Capell Site Agent Bridge server.')]
#[IsDestructive(false)]
final class PreviewBoostCapabilityToolAdapter extends Tool
{
    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return (new PreviewBoostCapabilityTool)->schema($schema);
    }

    public function handle(Request $request): Response
    {
        try {
            return (new PreviewBoostCapabilityTool)->previewCapability($request, resolve(CapellAgentBridgeCapabilityRegistry::class))
                ->responses()->sole();
        } catch (AuthenticationException|AuthorizationException|InvalidArgumentException|UnexpectedValueException|ValidationException $exception) {
            return Response::error($exception->getMessage());
        }
    }
}

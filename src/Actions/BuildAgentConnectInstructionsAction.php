<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Actions;

use Capell\AgentBridge\Data\AgentConnectInstructionsData;
use InvalidArgumentException;
use JsonException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static AgentConnectInstructionsData run(string $client, string $plainTextToken, string $serverUrl, bool $local = false, string $localHandle = 'capell-site')
 */
final class BuildAgentConnectInstructionsAction
{
    use AsFake;
    use AsObject;

    public const string CLIENT_CLAUDE_CODE = 'claude-code';

    public const string CLIENT_CURSOR = 'cursor';

    public const string CLIENT_JSON = 'json';

    public const string SERVER_NAME = 'capell';

    /**
     * @return list<string>
     */
    public static function clients(): array
    {
        return [self::CLIENT_CLAUDE_CODE, self::CLIENT_CURSOR, self::CLIENT_JSON];
    }

    /**
     * @throws JsonException
     */
    public function handle(string $client, string $plainTextToken, string $serverUrl, bool $local = false, string $localHandle = 'capell-site'): AgentConnectInstructionsData
    {
        throw_unless(in_array($client, self::clients(), true), InvalidArgumentException::class, sprintf('Unknown agent client [%s]. Use one of: %s.', $client, implode(', ', self::clients())));

        $snippet = $local
            ? $this->localSnippet($client, $plainTextToken, $localHandle)
            : $this->httpSnippet($client, $plainTextToken, $serverUrl);

        return new AgentConnectInstructionsData(
            client: $client,
            transport: $local ? 'stdio' : 'http',
            serverUrl: $local ? 'php artisan mcp:start ' . $localHandle : $serverUrl,
            tokenPreview: mb_substr($plainTextToken, 0, 18) . '…',
            snippet: $snippet,
            revokeHint: 'Revoke this key at any time from your user profile in the Capell admin, in the Agent Bridge Tokens section.',
        );
    }

    /**
     * @throws JsonException
     */
    private function httpSnippet(string $client, string $plainTextToken, string $serverUrl): string
    {
        if ($client === self::CLIENT_CLAUDE_CODE) {
            return sprintf(
                'claude mcp add --transport http %s %s --header "Authorization: Bearer %s"',
                self::SERVER_NAME,
                $serverUrl,
                $plainTextToken,
            );
        }

        return $this->json([
            'mcpServers' => [
                self::SERVER_NAME => [
                    'url' => $serverUrl,
                    'headers' => ['Authorization' => 'Bearer ' . $plainTextToken],
                ],
            ],
        ]);
    }

    /**
     * @throws JsonException
     */
    private function localSnippet(string $client, string $plainTextToken, string $localHandle): string
    {
        if ($client === self::CLIENT_CLAUDE_CODE) {
            return sprintf(
                'claude mcp add %s --env CAPELL_AGENT_BRIDGE_LOCAL_TOKEN=%s -- php artisan mcp:start %s',
                self::SERVER_NAME,
                $plainTextToken,
                $localHandle,
            );
        }

        return $this->json([
            'mcpServers' => [
                self::SERVER_NAME => [
                    'command' => 'php',
                    'args' => ['artisan', 'mcp:start', $localHandle],
                    'env' => ['CAPELL_AGENT_BRIDGE_LOCAL_TOKEN' => $plainTextToken],
                ],
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $value
     *
     * @throws JsonException
     */
    private function json(array $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}

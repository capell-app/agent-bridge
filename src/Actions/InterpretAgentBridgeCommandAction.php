<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Actions;

use Capell\AgentBridge\Data\AgentBridgeCommandPlanData;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/** @method static AgentBridgeCommandPlanData run(string $command) */
final class InterpretAgentBridgeCommandAction
{
    use AsFake;
    use AsObject;

    public function handle(string $command): AgentBridgeCommandPlanData
    {
        $command = Str::of($command)->squish()->trim()->toString();

        if ($command === '') {
            return $this->unmatched($command, []);
        }

        $patterns = [
            ['/^(?:clear|flush|refresh)\s+(?:the\s+)?(?:capell\s+)?caches?$/i', 'capell.cache.clear', fn (): array => []],
            ['/^(?:install|activate|enable)\s+(?:the\s+)?(?:package|extension)\s+(?<package>.+)$/i', 'capell.packages.install', fn (array $match): array => ['package' => $this->reference($match['package'])]],
            ['/^install\s+(?<package>.+?)(?:\s+package)?$/i', 'capell.packages.install', fn (array $match): array => ['package' => $this->reference($match['package'])]],
            ['/^(?:inspect|show|review)\s+(?:the\s+)?(?:package|extension)\s+(?<package>.+)$/i', 'capell.packages.inspect', fn (array $match): array => ['package' => $this->reference($match['package'])]],
            ['/^(?:find|search|list|show)\s+(?<query>.*?)\s*(?:packages|extensions)$/i', 'capell.packages.search', fn (array $match): array => array_filter(['query' => $this->reference($match['query'])])],
            ['/^(?:open|edit|manage)\s+(?:the\s+)?(?:site|website)(?:\s+record)?\s+(?<site>.+?)(?:\s+editor)?$/i', 'capell.sites.open_editor', fn (array $match): array => ['site' => $this->reference($match['site'])]],
            ['/^(?:open|edit|manage)\s+(?:the\s+)?(?<site>.+?)\s+(?:site|website)(?:\s+(?:record|editor))?$/i', 'capell.sites.open_editor', fn (array $match): array => ['site' => $this->reference($match['site'])]],
            ['/^(?:inspect|show)\s+(?:the\s+)?(?:site|website)\s+(?<site>.+)$/i', 'capell.sites.inspect', fn (array $match): array => ['site' => $this->reference($match['site'])]],
            ['/^(?:find|search|list|show)\s+(?<query>.*?)\s*(?:sites|websites)$/i', 'capell.sites.search', fn (array $match): array => array_filter(['query' => $this->reference($match['query'])])],
            ['/^(?:open|edit)\s+(?:the\s+)?(?:page\s+)?(?<page>.+?)\s+page(?:\s+(?:record|editor))?$/i', 'capell.pages.open_editor', fn (array $match): array => ['page' => $this->reference($match['page'])]],
            ['/^(?:open|edit)\s+(?:the\s+)?page\s+(?<page>.+?)(?:\s+(?:record|editor))?$/i', 'capell.pages.open_editor', fn (array $match): array => ['page' => $this->reference($match['page'])]],
            ['/^create\s+(?:a\s+|the\s+)?(?:new\s+)?(?:disabled\s+|draft\s+)?page(?:\s+(?:called|named))?\s+(?<name>.+)$/i', 'capell.pages.create_draft', fn (array $match): array => $this->newPagePayload($match['name'])],
            ['/^(?:clone|duplicate|copy)\s+(?:the\s+)?(?<page>.+?)\s+page(?:\s+(?:as|to)\s+(?<name>.+))?$/i', 'capell.pages.clone', fn (array $match): array => $this->clonePayload($match)],
            ['/^(?:update|change|rename)\s+(?:the\s+)?(?<page>.+?)\s+page\s+(?<field>title|slug|name)\s+(?:to|as)\s+(?<value>.+)$/i', 'capell.pages.update_draft', fn (array $match): array => [
                'page' => $this->reference($match['page']),
                mb_strtolower($match['field']) => $this->reference($match['value']),
            ]],
            ['/^schedule\s+(?:the\s+)?(?<page>.+?)\s+page\s+(?:to\s+publish\s+)?(?:at|for|on)\s+(?<publish_at>.+)$/i', 'capell.pages.schedule_publish', fn (array $match): array => [
                'page' => $this->reference($match['page']),
                'publish_at' => $this->reference($match['publish_at']),
            ]],
            ['/^schedule\s+(?:the\s+)?(?<page>.+?)\s+page\s+(?:to\s+)?(?:unpublish|expire|go\s+offline)\s+(?:at|for|on)\s+(?<unpublish_at>.+)$/i', 'capell.pages.schedule_unpublish', fn (array $match): array => [
                'page' => $this->reference($match['page']),
                'unpublish_at' => $this->reference($match['unpublish_at']),
            ]],
            ['/^(?:publish|launch|make\s+live)\s+(?:the\s+)?(?<page>.+?)\s+page(?:\s+now)?$/i', 'capell.pages.publish_now', fn (array $match): array => ['page' => $this->reference($match['page'])]],
            ['/^(?:unpublish|disable|take\s+offline)\s+(?:the\s+)?(?<page>.+?)\s+page(?:\s+now)?$/i', 'capell.pages.unpublish', fn (array $match): array => ['page' => $this->reference($match['page'])]],
            ['/^(?:revert|return|switch)\s+(?:the\s+)?(?<page>.+?)\s+page\s+(?:back\s+)?to\s+draft$/i', 'capell.pages.revert_to_draft', fn (array $match): array => ['page' => $this->reference($match['page'])]],
            ['/^(?:check|inspect|show)\s+(?:the\s+)?(?<page>.+?)\s+page\s+(?:publishing\s+)?readiness$/i', 'capell.pages.inspect_readiness', fn (array $match): array => ['page' => $this->reference($match['page'])]],
            ['/^(?:inspect|show)\s+(?:the\s+)?(?<page>.+?)\s+page(?:\s+details)?$/i', 'capell.pages.inspect', fn (array $match): array => ['page' => $this->reference($match['page'])]],
            ['/^(?:find|search|look\s+up)\s+(?:the\s+)?(?<query>.+?)\s+page$/i', 'capell.pages.search', fn (array $match): array => ['query' => $this->reference($match['query'])]],
        ];

        foreach ($patterns as [$pattern, $capabilityKey, $payloadResolver]) {
            if (preg_match($pattern, $command, $matches) !== 1) {
                continue;
            }

            /** @var array<string, mixed> $payload */
            $payload = $payloadResolver($matches);

            return new AgentBridgeCommandPlanData(
                command: $command,
                matched: true,
                capabilityKey: $capabilityKey,
                payload: $payload,
                summary: (string) __('capell-agent-bridge::admin.command_plan_ready', [
                    'capability' => $capabilityKey,
                ]),
                confidence: 1.0,
            );
        }

        $suggestions = SuggestAgentBridgeCapabilitiesAction::run($command)['suggestions'];

        return $this->unmatched($command, $suggestions);
    }

    /** @param list<array<string, mixed>> $suggestions */
    private function unmatched(string $command, array $suggestions): AgentBridgeCommandPlanData
    {
        return new AgentBridgeCommandPlanData(
            command: $command,
            matched: false,
            capabilityKey: null,
            payload: [],
            summary: (string) __('capell-agent-bridge::admin.command_plan_needs_choice'),
            confidence: 0.0,
            suggestions: $suggestions,
        );
    }

    /** @return array<string, string> */
    private function newPagePayload(string $name): array
    {
        $name = $this->reference($name);

        return ['name' => $name, 'title' => $name];
    }

    /** @param array<string, string> $match
     * @return array<string, string>
     */
    private function clonePayload(array $match): array
    {
        $payload = ['page' => $this->reference($match['page'])];
        $name = $this->reference($match['name'] ?? '');

        if ($name !== '') {
            $payload['name'] = $name;
            $payload['title'] = $name;
        }

        return $payload;
    }

    private function reference(string $value): string
    {
        return Str::of($value)
            ->trim()
            ->trim("\"'.,!? ")
            ->replaceMatches('/^the\s+/i', '')
            ->trim()
            ->toString();
    }
}

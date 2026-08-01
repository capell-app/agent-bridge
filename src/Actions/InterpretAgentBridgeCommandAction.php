<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Actions;

use Capell\AgentBridge\Data\AgentBridgeCommandPlanData;
use Capell\AgentBridge\Data\CapabilityCommandPatternData;
use Capell\AgentBridge\Data\CapabilityData;
use Capell\AgentBridge\Enums\CapabilityServerEnum;
use Capell\AgentBridge\Support\AgentBridgeCommandPatternMatcher;
use Capell\AgentBridge\Support\CapellAgentBridgeCapabilityRegistry;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Deterministic interpretation of one direct admin command.
 *
 * This is not language understanding. Every pattern is an anchored regular
 * expression contributed by the capability that owns it, and only capabilities
 * visible to the current server and scope context take part. Interpretation
 * never runs anything: preview, policy recheck, and confirmation remain the only
 * invocation path.
 *
 * @method static AgentBridgeCommandPlanData run(string $command, CapabilityServerEnum $server = CapabilityServerEnum::Site, ?array<int, string> $scopes = null)
 */
final class InterpretAgentBridgeCommandAction
{
    use AsFake;
    use AsObject;

    public function __construct(
        private readonly CapellAgentBridgeCapabilityRegistry $registry,
        private readonly AgentBridgeCommandPatternMatcher $matcher,
    ) {}

    /** @param  list<string>|null  $scopes */
    public function handle(
        string $command,
        CapabilityServerEnum $server = CapabilityServerEnum::Site,
        ?array $scopes = null,
    ): AgentBridgeCommandPlanData {
        $command = Str::of($command)->squish()->trim()->toString();

        if ($command === '') {
            return AgentBridgeCommandPlanData::unmatched(
                $command,
                (string) __('capell-agent-bridge::admin.command_plan_empty'),
            );
        }

        $capabilities = $this->orderedCapabilities($server, $scopes);

        foreach ($capabilities as $capability) {
            $plan = $this->matchCapability($capability, $command);

            if ($plan instanceof AgentBridgeCommandPlanData) {
                return $plan;
            }
        }

        return $this->rank($command, $server, $scopes);
    }

    /**
     * Visible capabilities in a stable interpretation order: most discoverable
     * first, then by key so two capabilities with the same priority always
     * resolve the same way.
     *
     * @param  list<string>|null  $scopes
     * @return list<CapabilityData>
     */
    private function orderedCapabilities(CapabilityServerEnum $server, ?array $scopes): array
    {
        $capabilities = $this->registry
            ->visibleFor($server, $scopes)
            ->filter(static fn (CapabilityData $capability): bool => $capability->commandPatterns !== [])
            ->values()
            ->all();

        usort(
            $capabilities,
            static fn (CapabilityData $left, CapabilityData $right): int => $right->discoveryPriority <=> $left->discoveryPriority
                ?: strcmp($left->key, $right->key),
        );

        return $capabilities;
    }

    /**
     * Null when no pattern on this capability matched the command at all.
     * A pattern that matched but could not extract a value it guarantees returns
     * an ambiguous plan rather than a partial exact match.
     */
    private function matchCapability(CapabilityData $capability, string $command): ?AgentBridgeCommandPlanData
    {
        foreach ($capability->commandPatterns as $pattern) {
            $match = $this->matcher->match($pattern, $command);

            if ($match === null) {
                continue;
            }

            if ($match['missingFields'] !== []) {
                return AgentBridgeCommandPlanData::ambiguous(
                    command: $command,
                    suggestions: [$this->suggestionFor($capability, $pattern)],
                    summary: (string) __('capell-agent-bridge::admin.command_plan_missing_values', [
                        'fields' => implode(', ', $match['missingFields']),
                    ]),
                    missingFields: $match['missingFields'],
                );
            }

            return AgentBridgeCommandPlanData::exact(
                command: $command,
                capabilityKey: $capability->key,
                payload: $match['payload'],
                summary: (string) __('capell-agent-bridge::admin.command_plan_ready', [
                    'capability' => $capability->name,
                ]),
            );
        }

        return null;
    }

    /**
     * Fallback ranking. Weak evidence stays unmatched, closely ranked
     * alternatives stay ambiguous, and a clear leader is only ever suggested.
     *
     * @param  list<string>|null  $scopes
     */
    private function rank(string $command, CapabilityServerEnum $server, ?array $scopes): AgentBridgeCommandPlanData
    {
        $ranking = SuggestAgentBridgeCapabilitiesAction::run(
            query: $command,
            server: $server,
            scopes: $scopes,
            limit: $this->configuredInt('suggestion_limit', 5),
        );

        /** @var list<array<string, mixed>> $suggestions */
        $suggestions = $ranking['suggestions'];
        /** @var list<string> $queryTokens */
        $queryTokens = $ranking['queryTokens'];

        $topScore = $this->scoreAt($suggestions, 0);
        $runnerUpScore = $this->scoreAt($suggestions, 1);

        /** @var list<string> $matchedTerms */
        $matchedTerms = is_array($suggestions[0]['matchedTerms'] ?? null)
            ? array_values(array_filter($suggestions[0]['matchedTerms'], 'is_string'))
            : [];

        $hasEnoughEvidence = $suggestions !== []
            && $topScore >= $this->configuredInt('minimum_evidence_score', 12)
            && $this->termCoverage($matchedTerms, $queryTokens) >= $this->configuredFloat('minimum_term_coverage', 0.5);

        if (! $hasEnoughEvidence) {
            return AgentBridgeCommandPlanData::unmatched(
                $command,
                (string) __('capell-agent-bridge::admin.command_plan_unmatched'),
            );
        }

        $separation = $runnerUpScore === 0
            ? INF
            : $topScore / $runnerUpScore;

        if ($separation < $this->configuredFloat('suggestion_separation_ratio', 1.35)) {
            return AgentBridgeCommandPlanData::ambiguous(
                command: $command,
                suggestions: $suggestions,
                summary: (string) __('capell-agent-bridge::admin.command_plan_ambiguous'),
            );
        }

        return AgentBridgeCommandPlanData::suggested(
            command: $command,
            suggestions: $suggestions,
            confidence: $this->normalizedConfidence($topScore, $runnerUpScore),
            summary: (string) __('capell-agent-bridge::admin.command_plan_suggested', [
                'capability' => is_string($suggestions[0]['name'] ?? null) ? $suggestions[0]['name'] : '',
            ]),
            matchedTerms: $matchedTerms,
        );
    }

    /**
     * Evidence, not authority: how far the leading capability separated itself
     * from the runner-up, expressed in 0..1 and capped below an exact match.
     */
    private function normalizedConfidence(int $topScore, int $runnerUpScore): float
    {
        if ($topScore <= 0) {
            return 0.0;
        }

        return round(min(0.95, $topScore / ($topScore + $runnerUpScore)), 2);
    }

    /**
     * How much of the command the leading capability actually accounts for.
     *
     * A raw score alone cannot tell a real match from a one-word collision: a
     * single common token repeated across a capability's key, name, description,
     * and intents accumulates a high score on its own. Coverage asks the harder
     * question — how much of what the administrator said was recognised.
     *
     * @param  list<string>  $matchedTerms
     * @param  list<string>  $queryTokens
     */
    private function termCoverage(array $matchedTerms, array $queryTokens): float
    {
        if ($queryTokens === []) {
            return 0.0;
        }

        return count(array_intersect($queryTokens, $matchedTerms)) / count($queryTokens);
    }

    /** @param list<array<string, mixed>> $suggestions */
    private function scoreAt(array $suggestions, int $index): int
    {
        $score = $suggestions[$index]['score'] ?? 0;

        return is_int($score) ? $score : 0;
    }

    /**
     * A single ranked entry describing the capability whose pattern matched but
     * whose guaranteed values could not all be extracted.
     *
     * @return array<string, mixed>
     */
    private function suggestionFor(CapabilityData $capability, CapabilityCommandPatternData $pattern): array
    {
        return [
            'key' => $capability->key,
            'name' => $capability->name,
            'description' => $capability->description,
            'category' => $capability->category,
            'scope' => $capability->scope,
            'risk' => $capability->risk->value,
            'requiresConfirmation' => $capability->needsConfirmation(),
            'inputSchema' => $capability->inputSchema,
            'examples' => $capability->examples,
            'commandPatterns' => [$pattern->toPayload()],
            'score' => 0,
            'matchedTerms' => [],
            'scoreComponents' => [],
        ];
    }

    private function configuredInt(string $key, int $default): int
    {
        $value = config('capell-agent-bridge.interpretation.' . $key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    private function configuredFloat(string $key, float $default): float
    {
        $value = config('capell-agent-bridge.interpretation.' . $key, $default);

        return is_numeric($value) ? (float) $value : $default;
    }
}

<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Actions;

use Capell\AgentBridge\Data\CapabilityData;
use Capell\AgentBridge\Data\CapabilitySuggestionData;
use Capell\AgentBridge\Enums\CapabilityServerEnum;
use Capell\AgentBridge\Support\CapellAgentBridgeCapabilityRegistry;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static array{query: string, queryTokens: list<string>, suggestions: list<array<string, mixed>>} run(string $query, CapabilityServerEnum $server = CapabilityServerEnum::Site, ?array<int, string> $scopes = null, int $limit = 5, ?array<int, string> $capabilityKeys = null)
 */
final class SuggestAgentBridgeCapabilitiesAction
{
    use AsFake;
    use AsObject;

    /** Awarded when the whole query is exactly a capability key or name. */
    private const int IDENTIFIER_SCORE = 200;

    /** Awarded when every token of a declared intent appears in the query. */
    private const int COVERED_INTENT_SCORE = 50;

    /** @var list<string> */
    private const array STOP_WORDS = [
        'a',
        'an',
        'and',
        'can',
        'could',
        'for',
        'from',
        'i',
        'in',
        'it',
        'make',
        'me',
        'my',
        'of',
        'on',
        'or',
        'please',
        'the',
        'this',
        'to',
        'want',
        'with',
        'would',
    ];

    public function __construct(
        private readonly CapellAgentBridgeCapabilityRegistry $registry,
    ) {}

    /**
     * @param  list<string>|null  $scopes
     * @param  list<string>|null  $capabilityKeys  the token's capability limit, when it has one
     * @return array{query: string, queryTokens: list<string>, suggestions: list<array<string, mixed>>}
     */
    public function handle(
        string $query,
        CapabilityServerEnum $server = CapabilityServerEnum::Site,
        ?array $scopes = null,
        int $limit = 5,
        ?array $capabilityKeys = null,
    ): array {
        $query = trim($query);
        $limit = max(1, min(20, $limit));
        $normalizedQuery = $this->normalize($query);
        $queryTokens = $this->tokens($normalizedQuery);

        /** @var list<CapabilitySuggestionData> $suggestions */
        $suggestions = $this->registry
            ->visibleFor($server, $scopes)
            ->filter(fn (CapabilityData $capability): bool => $capabilityKeys === null || in_array($capability->key, $capabilityKeys, true))
            ->map(fn (CapabilityData $capability): CapabilitySuggestionData => $this->score(
                capability: $capability,
                normalizedQuery: $normalizedQuery,
                queryTokens: $queryTokens,
            ))
            ->filter(fn (CapabilitySuggestionData $suggestion): bool => $normalizedQuery === '' || $suggestion->score > 0)
            ->all();

        usort($suggestions, static fn (CapabilitySuggestionData $left, CapabilitySuggestionData $right): int => $right->score <=> $left->score
                ?: $right->capability->discoveryPriority <=> $left->capability->discoveryPriority
                ?: strcmp($left->capability->key, $right->capability->key));

        return [
            'query' => $query,
            // Returned so callers can judge evidence coverage without re-implementing
            // this action's normalization and stop-word rules.
            'queryTokens' => $queryTokens,
            'suggestions' => array_values(array_map(
                static fn (CapabilitySuggestionData $suggestion): array => $suggestion->toPayload(),
                array_slice($suggestions, 0, $limit),
            )),
        ];
    }

    /**
     * @param  list<string>  $queryTokens
     */
    private function score(CapabilityData $capability, string $normalizedQuery, array $queryTokens): CapabilitySuggestionData
    {
        if ($normalizedQuery === '') {
            return new CapabilitySuggestionData(
                capability: $capability,
                score: max(0, $capability->discoveryPriority),
                scoreComponents: ['discovery_priority' => max(0, $capability->discoveryPriority)],
            );
        }

        $matchedTerms = [];
        $components = [];
        $normalizedKey = $this->normalize($capability->key);
        $normalizedName = $this->normalize($capability->name);

        if ($normalizedQuery === $normalizedKey || $normalizedQuery === $normalizedName) {
            $components['identifier'] = self::IDENTIFIER_SCORE;
        }

        /** @var list<array{group: string, value: string, weight: int, isIntent: bool}> $fields */
        $fields = [
            ['group' => 'key', 'value' => $capability->key, 'weight' => 8, 'isIntent' => false],
            ['group' => 'name', 'value' => $capability->name, 'weight' => 10, 'isIntent' => false],
            ['group' => 'description', 'value' => $capability->description, 'weight' => 4, 'isIntent' => false],
            ['group' => 'category', 'value' => $capability->category, 'weight' => 5, 'isIntent' => false],
            ...array_map(
                static fn (string $intent): array => ['group' => 'intent', 'value' => $intent, 'weight' => 14, 'isIntent' => true],
                $capability->intents,
            ),
            ...array_map(
                static fn (string $example): array => ['group' => 'example', 'value' => $example, 'weight' => 6, 'isIntent' => false],
                $capability->examples,
            ),
        ];

        foreach ($fields as $field) {
            $normalizedValue = $this->normalize($field['value']);

            if ($normalizedValue === '') {
                continue;
            }

            $group = $field['group'];
            $fieldTokens = $this->tokens($normalizedValue);
            $matches = array_values(array_intersect($queryTokens, $fieldTokens));

            if ($matches !== []) {
                $components[$group] = ($components[$group] ?? 0) + count($matches) * $field['weight'];
                $matchedTerms = [...$matchedTerms, ...$matches];
            }

            if (str_contains($normalizedQuery, $normalizedValue)) {
                $components[$group] = ($components[$group] ?? 0) + $field['weight'] * 4;
            }

            if ($field['isIntent'] && $fieldTokens !== [] && array_diff($fieldTokens, $queryTokens) === []) {
                $components['intent_covered'] = ($components['intent_covered'] ?? 0) + self::COVERED_INTENT_SCORE;
            }
        }

        $matchedTerms = array_values(array_unique($matchedTerms));
        sort($matchedTerms);
        ksort($components);

        return new CapabilitySuggestionData(
            capability: $capability,
            score: array_sum($components),
            matchedTerms: $matchedTerms,
            scoreComponents: $components,
        );
    }

    private function normalize(string $value): string
    {
        return Str::of($value)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->squish()
            ->toString();
    }

    /** @return list<string> */
    private function tokens(string $value): array
    {
        if ($value === '') {
            return [];
        }

        return array_values(array_unique(array_filter(
            explode(' ', $value),
            static fn (string $token): bool => mb_strlen($token) >= 2 && ! in_array($token, self::STOP_WORDS, true),
        )));
    }
}

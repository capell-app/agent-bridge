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
 * @method static array{query: string, suggestions: list<array<string, mixed>>} run(string $query, CapabilityServerEnum $server = CapabilityServerEnum::Site, ?array<int, string> $scopes = null, int $limit = 5)
 */
final class SuggestAgentBridgeCapabilitiesAction
{
    use AsFake;
    use AsObject;

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
     * @return array{query: string, suggestions: list<array<string, mixed>>}
     */
    public function handle(
        string $query,
        CapabilityServerEnum $server = CapabilityServerEnum::Site,
        ?array $scopes = null,
        int $limit = 5,
    ): array {
        $query = trim($query);
        $limit = max(1, min(20, $limit));
        $normalizedQuery = $this->normalize($query);
        $queryTokens = $this->tokens($normalizedQuery);

        /** @var list<CapabilitySuggestionData> $suggestions */
        $suggestions = $this->registry
            ->visibleFor($server, $scopes)
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
            );
        }

        $score = 0;
        $matchedTerms = [];
        $normalizedKey = $this->normalize($capability->key);
        $normalizedName = $this->normalize($capability->name);

        if ($normalizedQuery === $normalizedKey || $normalizedQuery === $normalizedName) {
            $score += 200;
        }

        $fields = [
            [$capability->key, 8, false],
            [$capability->name, 10, false],
            [$capability->description, 4, false],
            [$capability->category, 5, false],
            ...array_map(static fn (string $intent): array => [$intent, 14, true], $capability->intents),
            ...array_map(static fn (string $example): array => [$example, 6, false], $capability->examples),
        ];

        foreach ($fields as [$value, $weight, $isIntent]) {
            $normalizedValue = $this->normalize((string) $value);

            if ($normalizedValue === '') {
                continue;
            }

            $fieldTokens = $this->tokens($normalizedValue);
            $matches = array_values(array_intersect($queryTokens, $fieldTokens));

            if ($matches !== []) {
                $score += count($matches) * (int) $weight;
                $matchedTerms = [...$matchedTerms, ...$matches];
            }

            if (str_contains($normalizedQuery, $normalizedValue)) {
                $score += (int) $weight * 4;
            }

            if ($isIntent === true && $fieldTokens !== [] && array_diff($fieldTokens, $queryTokens) === []) {
                $score += 50;
            }
        }

        $matchedTerms = array_values(array_unique($matchedTerms));
        sort($matchedTerms);

        return new CapabilitySuggestionData(
            capability: $capability,
            score: $score,
            matchedTerms: $matchedTerms,
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

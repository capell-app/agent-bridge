<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Data;

use Spatie\LaravelData\Data;

final class CapabilitySuggestionData extends Data
{
    /**
     * @param  list<string>  $matchedTerms
     * @param  array<string, int>  $scoreComponents  Per-field contributions, for inspection by trusted clients.
     */
    public function __construct(
        public readonly CapabilityData $capability,
        public readonly int $score,
        public readonly array $matchedTerms = [],
        public readonly array $scoreComponents = [],
    ) {}

    /**
     * @return array{
     *     key: string,
     *     name: string,
     *     description: string,
     *     category: string,
     *     scope: string,
     *     risk: string,
     *     requiresConfirmation: bool,
     *     inputSchema: array<string, mixed>|null,
     *     examples: list<string>,
     *     commandPatterns: list<array{phrase: string, fields: list<string>, required: list<string>}>,
     *     score: int,
     *     matchedTerms: list<string>,
     *     scoreComponents: array<string, int>
     * }
     */
    public function toPayload(): array
    {
        return [
            'key' => $this->capability->key,
            'name' => $this->capability->name,
            'description' => $this->capability->description,
            'category' => $this->capability->category,
            'scope' => $this->capability->scope,
            'risk' => $this->capability->risk->value,
            'requiresConfirmation' => $this->capability->needsConfirmation(),
            'inputSchema' => $this->capability->inputSchema,
            'examples' => $this->capability->examples,
            'commandPatterns' => array_map(
                static fn (CapabilityCommandPatternData $pattern): array => $pattern->toPayload(),
                $this->capability->commandPatterns,
            ),
            'score' => $this->score,
            'matchedTerms' => $this->matchedTerms,
            'scoreComponents' => $this->scoreComponents,
        ];
    }
}

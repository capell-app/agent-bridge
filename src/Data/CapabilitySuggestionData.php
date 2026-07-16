<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Data;

use Spatie\LaravelData\Data;

final class CapabilitySuggestionData extends Data
{
    /**
     * @param  list<string>  $matchedTerms
     */
    public function __construct(
        public readonly CapabilityData $capability,
        public readonly int $score,
        public readonly array $matchedTerms = [],
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
     *     score: int,
     *     matchedTerms: list<string>
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
            'score' => $this->score,
            'matchedTerms' => $this->matchedTerms,
        ];
    }
}

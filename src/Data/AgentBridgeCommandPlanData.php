<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Data;

use Capell\AgentBridge\Enums\CommandInterpretationOutcomeEnum;
use Spatie\LaravelData\Data;

/**
 * The result of interpreting one direct command.
 *
 * A plan is a *proposal*, never an authorisation. Even an exact match still has
 * to pass preview, policy recheck, and one-use confirmation before anything
 * changes.
 */
final class AgentBridgeCommandPlanData extends Data
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $missingFields
     * @param  list<array<string, mixed>>  $suggestions
     * @param  list<string>  $matchedTerms
     */
    public function __construct(
        public readonly string $command,
        public readonly CommandInterpretationOutcomeEnum $outcome,
        public readonly bool $matched,
        public readonly ?string $capabilityKey,
        public readonly array $payload,
        public readonly string $summary,
        public readonly float $confidence,
        public readonly array $missingFields = [],
        public readonly array $suggestions = [],
        public readonly array $matchedTerms = [],
    ) {}

    /**
     * A deterministic pattern produced a complete, schema-compatible payload.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function exact(string $command, string $capabilityKey, array $payload, string $summary): self
    {
        return new self(
            command: $command,
            outcome: CommandInterpretationOutcomeEnum::Exact,
            matched: true,
            capabilityKey: $capabilityKey,
            payload: $payload,
            summary: $summary,
            confidence: 1.0,
        );
    }

    /**
     * One capability ranked clearly above the alternatives. It is offered, never selected.
     *
     * @param  list<array<string, mixed>>  $suggestions
     * @param  list<string>  $matchedTerms
     */
    public static function suggested(
        string $command,
        array $suggestions,
        float $confidence,
        string $summary,
        array $matchedTerms = [],
    ): self {
        return new self(
            command: $command,
            outcome: CommandInterpretationOutcomeEnum::Suggested,
            matched: false,
            capabilityKey: null,
            payload: [],
            summary: $summary,
            confidence: $confidence,
            suggestions: $suggestions,
            matchedTerms: $matchedTerms,
        );
    }

    /**
     * Several capabilities are plausible, or a pattern could not extract a value it guarantees.
     *
     * @param  list<array<string, mixed>>  $suggestions
     * @param  list<string>  $missingFields
     */
    public static function ambiguous(
        string $command,
        array $suggestions,
        string $summary,
        array $missingFields = [],
    ): self {
        return new self(
            command: $command,
            outcome: CommandInterpretationOutcomeEnum::Ambiguous,
            matched: false,
            capabilityKey: null,
            payload: [],
            summary: $summary,
            confidence: 0.0,
            missingFields: $missingFields,
            suggestions: $suggestions,
        );
    }

    /** No visible capability has meaningful evidence of a match. */
    public static function unmatched(string $command, string $summary): self
    {
        return new self(
            command: $command,
            outcome: CommandInterpretationOutcomeEnum::Unmatched,
            matched: false,
            capabilityKey: null,
            payload: [],
            summary: $summary,
            confidence: 0.0,
        );
    }

    /**
     * @return array{
     *     command: string,
     *     outcome: string,
     *     matched: bool,
     *     capabilityKey: string|null,
     *     payload: array<string, mixed>,
     *     summary: string,
     *     confidence: float,
     *     missingFields: list<string>,
     *     suggestions: list<array<string, mixed>>,
     *     matchedTerms: list<string>
     * }
     */
    public function toPayload(): array
    {
        return [
            'command' => $this->command,
            'outcome' => $this->outcome->value,
            'matched' => $this->matched,
            'capabilityKey' => $this->capabilityKey,
            'payload' => $this->payload,
            'summary' => $this->summary,
            'confidence' => $this->confidence,
            'missingFields' => $this->missingFields,
            'suggestions' => $this->suggestions,
            'matchedTerms' => $this->matchedTerms,
        ];
    }
}

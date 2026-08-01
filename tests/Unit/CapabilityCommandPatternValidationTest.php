<?php

declare(strict_types=1);

use Capell\AgentBridge\Data\Capabilities\ClonePageCapabilityInputData;
use Capell\AgentBridge\Data\CapabilityCommandPatternData;
use Capell\AgentBridge\Data\CapabilityData;
use Capell\AgentBridge\Enums\CapabilityRiskEnum;
use Capell\AgentBridge\Enums\CapabilityServerEnum;
use Capell\AgentBridge\Support\CapellAgentBridgeCapabilityRegistry;
use Capell\AgentBridge\Tests\Fixtures\FakeCapabilityAction;
use Spatie\LaravelData\Data;

/**
 * @param  list<CapabilityCommandPatternData>  $patterns
 * @param  array<string, mixed>|null  $inputSchema
 * @param  class-string<Data>|null  $inputDataClass
 */
function patternedCapability(
    array $patterns,
    ?array $inputSchema = null,
    ?string $inputDataClass = null,
): CapabilityData {
    return new CapabilityData(
        key: 'vendor.demo.operation',
        name: 'Demo operation',
        description: 'Exercises command pattern registration.',
        scope: 'vendor.demo.write',
        server: CapabilityServerEnum::Site,
        risk: CapabilityRiskEnum::Read,
        actionClass: FakeCapabilityAction::class,
        inputDataClass: $inputDataClass,
        inputSchema: $inputSchema ?? [
            'type' => 'object',
            'required' => ['page'],
            'properties' => [
                'page' => ['type' => 'string'],
                'title' => ['type' => 'string'],
            ],
            'additionalProperties' => false,
        ],
        commandPatterns: $patterns,
    );
}

/**
 * @param  list<CapabilityCommandPatternData>  $patterns
 * @param  array<string, mixed>|null  $inputSchema
 * @param  class-string<Data>|null  $inputDataClass
 */
function registeringCapabilityWith(array $patterns, ?array $inputSchema = null, ?string $inputDataClass = null): Closure
{
    return static function () use ($patterns, $inputSchema, $inputDataClass): void {
        (new CapellAgentBridgeCapabilityRegistry)->register(
            patternedCapability($patterns, $inputSchema, $inputDataClass),
        );
    };
}

it('accepts a pattern whose captures map onto declared input schema fields', function (): void {
    $registry = new CapellAgentBridgeCapabilityRegistry;

    $registry->register(patternedCapability([
        new CapabilityCommandPatternData(
            expression: '/^rename\s+(?<page>.+?)\s+to\s+(?<title>.+)$/i',
            phrase: 'Rename {page} to {title}',
            fields: ['page' => ['page'], 'title' => ['title']],
            required: ['page', 'title'],
        ),
    ]));

    expect($registry->has('vendor.demo.operation'))->toBeTrue();
});

it('rejects a pattern that emits a field the input schema does not declare', function (): void {
    expect(registeringCapabilityWith([
        new CapabilityCommandPatternData(
            expression: '/^rename\s+(?<page>.+?)\s+to\s+(?<nickname>.+)$/i',
            phrase: 'Rename {page} to {nickname}',
            fields: ['page' => ['page'], 'nickname' => ['nickname']],
        ),
    ]))->toThrow(
        InvalidArgumentException::class,
        'Agent Bridge capability [vendor.demo.operation] command pattern #0 field [fields] payload field [nickname] is not declared by the capability input boundary.',
    );
});

it('rejects a pattern that claims a required field no capture supplies', function (): void {
    expect(registeringCapabilityWith([
        new CapabilityCommandPatternData(
            expression: '/^rename\s+(?<page>.+)$/i',
            phrase: 'Rename {page}',
            fields: ['page' => ['page']],
            required: ['page', 'title'],
        ),
    ]))->toThrow(
        InvalidArgumentException::class,
        'payload field [title] is claimed as required but no capture supplies it.',
    );
});

it('rejects an unanchored pattern', function (): void {
    expect(registeringCapabilityWith([
        new CapabilityCommandPatternData(
            expression: '/rename\s+(?<page>.+)/i',
            phrase: 'Rename {page}',
            fields: ['page' => ['page']],
        ),
    ]))->toThrow(InvalidArgumentException::class, 'must be anchored with ^ and $.');
});

it('rejects an invalid regular expression', function (): void {
    expect(registeringCapabilityWith([
        new CapabilityCommandPatternData(
            expression: '/^rename\s+(?<page>.+$/i',
            phrase: 'Rename {page}',
            fields: ['page' => ['page']],
        ),
    ]))->toThrow(InvalidArgumentException::class, 'is not a valid regular expression.');
});

it('rejects an empty phrase', function (): void {
    expect(registeringCapabilityWith([
        new CapabilityCommandPatternData(
            expression: '/^rename\s+(?<page>.+)$/i',
            phrase: '',
            fields: ['page' => ['page']],
        ),
    ]))->toThrow(InvalidArgumentException::class, 'field [phrase] must not be empty.');
});

it('falls back to the input Data boundary when no schema properties are declared', function (): void {
    expect(registeringCapabilityWith(
        patterns: [
            new CapabilityCommandPatternData(
                expression: '/^clone\s+(?<page>.+?)\s+as\s+(?<nickname>.+)$/i',
                phrase: 'Clone {page} as {nickname}',
                fields: ['page' => ['page'], 'nickname' => ['nickname']],
            ),
        ],
        inputSchema: ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
        inputDataClass: ClonePageCapabilityInputData::class,
    ))->toThrow(InvalidArgumentException::class, 'payload field [nickname]');
});

it('exposes command patterns to trusted clients without the raw expression', function (): void {
    $pattern = new CapabilityCommandPatternData(
        expression: '/^rename\s+(?<page>.+?)\s+to\s+(?<title>.+)$/i',
        phrase: 'Rename {page} to {title}',
        fields: ['page' => ['page'], 'title' => ['title']],
        required: ['page', 'title'],
    );

    expect($pattern->toPayload())->toBe([
        'phrase' => 'Rename {page} to {title}',
        'fields' => ['page', 'title'],
        'required' => ['page', 'title'],
    ])
        ->and(patternedCapability([$pattern])->toPayload()['commandPatterns'])
        ->toBe([$pattern->toPayload()])
        ->and(patternedCapability([$pattern])->publicPayload())
        ->not->toHaveKey('commandPatterns');
});

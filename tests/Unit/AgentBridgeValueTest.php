<?php

declare(strict_types=1);

use Capell\AgentBridge\Support\AgentBridgeValue;

it('normalizes trusted scalar and collection boundary values', function (): void {
    expect(AgentBridgeValue::integer('42', 'ID'))->toBe(42)
        ->and(AgentBridgeValue::integer('-1', 'Limit'))->toBe(-1)
        ->and(AgentBridgeValue::nullableInteger(null, 'ID'))->toBeNull()
        ->and(AgentBridgeValue::identifier(42, 'ID'))->toBe('42')
        ->and(AgentBridgeValue::reference('homepage', 'Page'))->toBe('homepage')
        ->and(AgentBridgeValue::boolean('1', 'Enabled'))->toBeTrue()
        ->and(AgentBridgeValue::boolean(0, 'Enabled'))->toBeFalse()
        ->and(AgentBridgeValue::associativeArray(['name' => 'Homepage'], 'Payload'))
        ->toBe(['name' => 'Homepage'])
        ->and(AgentBridgeValue::stringList(['read', 'write'], 'Scopes'))
        ->toBe(['read', 'write']);
});

it('rejects malformed values instead of coercing unsafe AI input', function (mixed $value, string $method): void {
    expect(fn (): mixed => AgentBridgeValue::{$method}($value, 'Boundary value'))
        ->toThrow(UnexpectedValueException::class);
})->with([
    'object as integer' => [new stdClass, 'integer'],
    'overflowing integer string' => ['999999999999999999999999999999', 'integer'],
    'null as string' => [null, 'string'],
    'empty required string' => ['', 'nonEmptyString'],
    'object as identifier' => [new stdClass, 'identifier'],
    'array as reference' => [[], 'reference'],
    'word as boolean' => ['yes', 'boolean'],
    'list as associative payload' => [['first'], 'associativeArray'],
    'mixed string list' => [['read', 2], 'stringList'],
]);

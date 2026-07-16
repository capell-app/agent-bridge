<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Data;

use Capell\AgentBridge\Support\AgentBridgeValue;
use Illuminate\Support\Arr;

final readonly class AgentBridgePromptData
{
    public function __construct(
        public string $goal,
        public string $area = 'pages',
        public string $operation = 'inspect',
        public string $safety = 'preview_first',
        public string $target = '',
        public string $constraints = '',
        public string $successCriteria = '',
    ) {}

    /** @param array<string, mixed> $state */
    public static function fromArray(array $state): self
    {
        return new self(
            goal: trim(AgentBridgeValue::string(Arr::get($state, 'goal', ''), 'Prompt goal')),
            area: AgentBridgeValue::string(Arr::get($state, 'area', 'pages'), 'Prompt area'),
            operation: AgentBridgeValue::string(Arr::get($state, 'operation', 'inspect'), 'Prompt operation'),
            safety: AgentBridgeValue::string(Arr::get($state, 'safety', 'preview_first'), 'Prompt safety mode'),
            target: trim(AgentBridgeValue::string(Arr::get($state, 'target', ''), 'Prompt target')),
            constraints: trim(AgentBridgeValue::string(Arr::get($state, 'constraints', ''), 'Prompt constraints')),
            successCriteria: trim(AgentBridgeValue::string(Arr::get($state, 'success_criteria', ''), 'Prompt success criteria')),
        );
    }

    /** @return array<string, string> */
    public function toFormState(): array
    {
        return [
            'goal' => $this->goal,
            'area' => $this->area,
            'operation' => $this->operation,
            'safety' => $this->safety,
            'target' => $this->target,
            'constraints' => $this->constraints,
            'success_criteria' => $this->successCriteria,
        ];
    }
}

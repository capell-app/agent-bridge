<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Filament\Pages;

use BackedEnum;
use Capell\AgentBridge\Actions\BuildAgentBridgePromptAction;
use Capell\AgentBridge\Actions\ConfirmAgentBridgeAdminCapabilityAction;
use Capell\AgentBridge\Actions\InterpretAgentBridgeCommandAction;
use Capell\AgentBridge\Actions\PreviewAgentBridgeAdminCapabilityAction;
use Capell\AgentBridge\Actions\SuggestAgentBridgeCapabilitiesAction;
use Capell\AgentBridge\Data\AgentBridgePromptData;
use Capell\AgentBridge\Data\CapabilityCommandPatternData;
use Capell\AgentBridge\Data\CapabilityData;
use Capell\AgentBridge\Enums\CapabilityServerEnum;
use Capell\AgentBridge\Enums\CommandInterpretationOutcomeEnum;
use Capell\AgentBridge\Support\AgentBridgeAdminConfirmationStore;
use Capell\AgentBridge\Support\AgentBridgePolicyAuthorizer;
use Capell\AgentBridge\Support\AgentBridgeValue;
use Capell\AgentBridge\Support\CapellAgentBridgeCapabilityRegistry;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Validation\ValidationException;
use JsonException;
use Livewire\Attributes\Locked;
use Override;
use Throwable;

final class CapellAgentBridgePromptBuilderPage extends Page implements HasForms
{
    use InteractsWithForms;

    /** @var array<string, mixed> */
    public array $data = [
        'safety' => 'preview_first',
    ];

    public string $preparedPrompt = '';

    public string $command = '';

    /** @var list<array<string, mixed>> */
    public array $commandSuggestions = [];

    /** @var array<string, mixed> */
    public array $commandPlan = [];

    /** @var array<string, mixed> */
    public array $commandResponse = [];

    public string $commandOutcome = '';

    public string $selectedCapabilityKey = '';

    public string $payloadJson = '{}';

    public string $commandError = '';

    #[Locked]
    public ?string $pendingConfirmationToken = null;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static ?string $slug = 'agent-bridge/capell-agent-bridge/prompt-builder';

    protected static ?int $navigationSort = 10;

    protected string $view = 'capell-agent-bridge::filament.pages.prompt-builder';

    #[Override]
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    #[Override]
    public static function getNavigationLabel(): string
    {
        return (string) __('capell-agent-bridge::admin.prompt_builder_navigation');
    }

    /** @return array<string, string> */
    public static function areaOptions(): array
    {
        return [
            'pages' => (string) __('capell-agent-bridge::admin.area_pages'),
            'publishing' => (string) __('capell-agent-bridge::admin.area_publishing'),
            'sites' => (string) __('capell-agent-bridge::admin.area_sites'),
            'cache' => (string) __('capell-agent-bridge::admin.area_cache'),
            'packages' => (string) __('capell-agent-bridge::admin.area_packages'),
        ];
    }

    /** @return array<string, string> */
    public static function operationOptions(): array
    {
        return [
            'inspect' => (string) __('capell-agent-bridge::admin.operation_inspect'),
            'create' => (string) __('capell-agent-bridge::admin.operation_create'),
            'clone' => (string) __('capell-agent-bridge::admin.operation_clone'),
            'update' => (string) __('capell-agent-bridge::admin.operation_update'),
            'open' => (string) __('capell-agent-bridge::admin.operation_open'),
            'search' => (string) __('capell-agent-bridge::admin.operation_search'),
            'publish' => (string) __('capell-agent-bridge::admin.operation_publish'),
            'schedule' => (string) __('capell-agent-bridge::admin.operation_schedule'),
            'unpublish' => (string) __('capell-agent-bridge::admin.operation_unpublish'),
            'install' => (string) __('capell-agent-bridge::admin.operation_install'),
            'disable' => (string) __('capell-agent-bridge::admin.operation_disable'),
            'clear' => (string) __('capell-agent-bridge::admin.operation_clear'),
            'recommend' => (string) __('capell-agent-bridge::admin.operation_recommend'),
        ];
    }

    /** @return array<string, string> */
    public static function safetyOptions(): array
    {
        return [
            'preview_first' => (string) __('capell-agent-bridge::admin.safety_preview_first'),
            'read_only' => (string) __('capell-agent-bridge::admin.safety_read_only'),
            'prepare_confirmation' => (string) __('capell-agent-bridge::admin.safety_prepare_confirmation'),
        ];
    }

    #[Override]
    public function getTitle(): string
    {
        return __('capell-agent-bridge::admin.command_centre_title');
    }

    #[Override]
    public function getSubheading(): string
    {
        return __('capell-agent-bridge::admin.command_centre_subheading');
    }

    public function mount(): void
    {
        $this->getSchema('form')?->fill($this->data);
        $this->refreshSuggestions('');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('capell-agent-bridge::admin.intent_section'))
                    ->description(__('capell-agent-bridge::admin.intent_section_description'))
                    ->schema([
                        TextInput::make('goal')
                            ->label(__('capell-agent-bridge::admin.goal'))
                            ->helperText(__('capell-agent-bridge::admin.goal_help'))
                            ->placeholder(__('capell-agent-bridge::admin.goal_placeholder'))
                            ->maxLength(180)
                            ->required(),
                        Grid::make(3)
                            ->schema([
                                Select::make('area')
                                    ->label(__('capell-agent-bridge::admin.area'))
                                    ->options(self::areaOptions())
                                    ->required(),
                                Select::make('operation')
                                    ->label(__('capell-agent-bridge::admin.operation'))
                                    ->options(self::operationOptions())
                                    ->required(),
                                Select::make('safety')
                                    ->label(__('capell-agent-bridge::admin.safety'))
                                    ->options(self::safetyOptions())
                                    ->required(),
                            ]),
                        Textarea::make('target')
                            ->label(__('capell-agent-bridge::admin.target'))
                            ->helperText(__('capell-agent-bridge::admin.target_help'))
                            ->placeholder(__('capell-agent-bridge::admin.target_placeholder'))
                            ->rows(3),
                        Textarea::make('constraints')
                            ->label(__('capell-agent-bridge::admin.constraints'))
                            ->helperText(__('capell-agent-bridge::admin.constraints_help'))
                            ->placeholder(__('capell-agent-bridge::admin.constraints_placeholder'))
                            ->rows(3),
                        Textarea::make('success_criteria')
                            ->label(__('capell-agent-bridge::admin.success_criteria'))
                            ->helperText(__('capell-agent-bridge::admin.success_criteria_help'))
                            ->placeholder(__('capell-agent-bridge::admin.success_criteria_placeholder'))
                            ->rows(3),
                    ]),
            ]);
    }

    public function buildPrompt(): void
    {
        $state = $this->getSchema('form')?->getState() ?? $this->data;
        $this->preparedPrompt = BuildAgentBridgePromptAction::run(AgentBridgePromptData::fromArray($state));

        Notification::make('capell_agent-bridge_prompt_ready')
            ->success()
            ->title(__('capell-agent-bridge::admin.prompt_ready'))
            ->send();
    }

    public function updatedCommand(string $command): void
    {
        $this->discardPendingConfirmation();
        $this->commandPlan = [];
        $this->commandOutcome = '';
        $this->commandResponse = [];
        $this->commandError = '';
        $this->refreshSuggestions(mb_strlen(trim($command)) >= 2 ? $command : '');
    }

    public function useCommandExample(string $command): void
    {
        $this->command = $command;
        $this->planCommand();
    }

    public function planCommand(): void
    {
        $this->discardPendingConfirmation();
        $this->commandResponse = [];
        $this->commandError = '';

        $plan = InterpretAgentBridgeCommandAction::run($this->command);
        $this->commandPlan = $plan->toPayload();
        $this->commandOutcome = $plan->outcome->value;

        if ($plan->outcome->isExact() && $plan->capabilityKey !== null) {
            $this->selectedCapabilityKey = $plan->capabilityKey;
            $this->payloadJson = $this->encodePayload($plan->payload);
            $this->refreshSuggestions($this->command);

            return;
        }

        // Nothing is pre-selected outside an exact match: a ranked suggestion is
        // evidence for the administrator, never a decision made on their behalf.
        $this->selectedCapabilityKey = '';
        $this->commandSuggestions = $plan->suggestions;

        if ($plan->outcome === CommandInterpretationOutcomeEnum::Unmatched) {
            $this->refreshSuggestions('');
        }

        $this->commandError = $plan->summary;
    }

    public function executeCommand(): void
    {
        $this->planCommand();

        $capabilityKey = $this->commandPlan['capabilityKey'] ?? null;
        $payload = $this->commandPlan['payload'] ?? null;

        if (! is_string($capabilityKey) || ! is_array($payload)) {
            return;
        }

        $this->previewCapability(
            $capabilityKey,
            AgentBridgeValue::associativeArray($payload, 'Command payload'),
        );
    }

    public function chooseCapability(string $capabilityKey): void
    {
        $this->discardPendingConfirmation();
        $this->commandResponse = [];
        $this->commandError = '';

        $capability = resolve(CapellAgentBridgeCapabilityRegistry::class)->get($capabilityKey);
        $visible = collect($this->capabilityCatalog())
            ->contains(static fn (array $item): bool => ($item['key'] ?? null) === $capability->key);

        if (! $visible) {
            $this->commandError = (string) __('capell-agent-bridge::admin.command_capability_unavailable');

            return;
        }

        $this->selectedCapabilityKey = $capability->key;
        $this->payloadJson = $this->encodePayload($this->payloadTemplate($capability));
        $this->commandOutcome = CommandInterpretationOutcomeEnum::Exact->value;
        $this->commandPlan = [
            'command' => $this->command,
            'outcome' => CommandInterpretationOutcomeEnum::Exact->value,
            'matched' => true,
            'capabilityKey' => $capability->key,
            'payload' => $this->payloadTemplate($capability),
            'summary' => (string) __('capell-agent-bridge::admin.command_capability_selected', [
                'capability' => $capability->name,
            ]),
            'confidence' => 1.0,
            'missingFields' => [],
            'suggestions' => [],
            'matchedTerms' => [],
        ];
    }

    public function previewSelectedCapability(): void
    {
        $this->commandError = '';

        if ($this->selectedCapabilityKey === '') {
            $this->commandError = (string) __('capell-agent-bridge::admin.command_select_capability');

            return;
        }

        try {
            $payload = $this->decodePayload($this->payloadJson);
        } catch (JsonException|ValidationException $exception) {
            $this->setSafeCommandError($exception);

            return;
        }

        $this->previewCapability($this->selectedCapabilityKey, $payload);
    }

    public function confirmPendingCapability(): void
    {
        if ($this->pendingConfirmationToken === null) {
            $this->commandError = (string) __('capell-agent-bridge::admin.command_nothing_to_confirm');

            return;
        }

        try {
            $this->commandResponse = ConfirmAgentBridgeAdminCapabilityAction::run(
                $this->pendingConfirmationToken,
                $this->authenticatedUser(),
            );
            $this->pendingConfirmationToken = null;
            $this->commandError = '';

            Notification::make('capell_agent-bridge_command_confirmed')
                ->success()
                ->title(__('capell-agent-bridge::admin.command_confirmed'))
                ->send();
        } catch (Throwable $exception) {
            $this->pendingConfirmationToken = null;
            $this->setSafeCommandError($exception);
        }
    }

    public function cancelPendingCapability(): void
    {
        $this->discardPendingConfirmation();
        $this->commandResponse = [];
        $this->commandError = '';

        Notification::make('capell_agent-bridge_command_cancelled')
            ->title(__('capell-agent-bridge::admin.command_cancelled'))
            ->send();
    }

    /**
     * The heading above the ranked list, which must never imply that Capell has
     * already decided: suggestions and ambiguity are presented as choices.
     */
    public function commandSuggestionsHeading(): string
    {
        return (string) match ($this->commandOutcome) {
            CommandInterpretationOutcomeEnum::Suggested->value => __('capell-agent-bridge::admin.command_plan_suggested_heading'),
            CommandInterpretationOutcomeEnum::Ambiguous->value => __('capell-agent-bridge::admin.command_plan_ambiguous_heading'),
            default => __('capell-agent-bridge::admin.command_suggestions_heading'),
        };
    }

    /** @return list<string> */
    public function commandMatchedTerms(): array
    {
        $terms = $this->commandPlan['matchedTerms'] ?? [];

        return is_array($terms) ? array_values(array_filter($terms, 'is_string')) : [];
    }

    /** @return list<array{label:string, command:string}> */
    public function commandExamples(): array
    {
        return [
            ['label' => (string) __('capell-agent-bridge::admin.command_example_open_label'), 'command' => (string) __('capell-agent-bridge::admin.command_example_open')],
            ['label' => (string) __('capell-agent-bridge::admin.command_example_create_label'), 'command' => (string) __('capell-agent-bridge::admin.command_example_create')],
            ['label' => (string) __('capell-agent-bridge::admin.command_example_clone_label'), 'command' => (string) __('capell-agent-bridge::admin.command_example_clone')],
            ['label' => (string) __('capell-agent-bridge::admin.command_example_publish_label'), 'command' => (string) __('capell-agent-bridge::admin.command_example_publish')],
            ['label' => (string) __('capell-agent-bridge::admin.command_example_package_label'), 'command' => (string) __('capell-agent-bridge::admin.command_example_package')],
        ];
    }

    /** @return list<array<string, mixed>> */
    public function capabilityCatalog(): array
    {
        $user = $this->authenticatedUser();
        $authorizer = resolve(AgentBridgePolicyAuthorizer::class);

        $capabilities = resolve(CapellAgentBridgeCapabilityRegistry::class)
            ->visibleFor(CapabilityServerEnum::Site)
            ->filter(static fn (CapabilityData $capability): bool => $capability->policyAbility === null
                || $authorizer->allows($user, $capability->policyAbility))
            ->values()
            ->all();

        usort($capabilities, static fn (CapabilityData $left, CapabilityData $right): int => strcmp(
            $left->category,
            $right->category,
        ) ?: $right->discoveryPriority <=> $left->discoveryPriority);

        return array_map(static fn (CapabilityData $capability): array => [
            'key' => $capability->key,
            'name' => $capability->name,
            'description' => $capability->description,
            'category' => $capability->category,
            'risk' => $capability->risk->value,
            'requiresConfirmation' => $capability->needsConfirmation(),
            'inputSchema' => $capability->inputSchema,
            'examples' => $capability->examples,
            'commandPatterns' => array_map(
                static fn (CapabilityCommandPatternData $pattern): array => $pattern->toPayload(),
                $capability->commandPatterns,
            ),
        ], $capabilities);
    }

    /** @return array<string, list<array<string, mixed>>> */
    public function groupedCapabilityCatalog(): array
    {
        /** @var array<string, list<array<string, mixed>>> $grouped */
        $grouped = collect($this->capabilityCatalog())->groupBy('category')->all();

        return $grouped;
    }

    /** @return array<string, mixed>|null */
    public function selectedCapability(): ?array
    {
        foreach ($this->capabilityCatalog() as $capability) {
            if (($capability['key'] ?? null) === $this->selectedCapabilityKey) {
                return $capability;
            }
        }

        return null;
    }

    public function responseMessage(): string
    {
        $message = data_get($this->commandResponse, 'result.message')
            ?? data_get($this->commandResponse, 'preview.message')
            ?? '';

        return is_string($message) ? $message : '';
    }

    /** @return list<string> */
    public function responseWarnings(): array
    {
        $warnings = data_get($this->commandResponse, 'result.warnings')
            ?? data_get($this->commandResponse, 'preview.warnings')
            ?? [];

        return is_array($warnings)
            ? array_values(array_filter($warnings, 'is_string'))
            : [];
    }

    public function responseDataJson(): string
    {
        $data = data_get($this->commandResponse, 'result.data')
            ?? data_get($this->commandResponse, 'preview.data')
            ?? [];

        return $this->encodePayload(is_array($data) ? $data : []);
    }

    public function responseActionUrl(): ?string
    {
        $url = data_get($this->commandResponse, 'result.data.url');

        return is_string($url) && $url !== '' ? $url : null;
    }

    /** @return array<int, Action> */
    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            Action::make('focusCommand')
                ->label(__('capell-agent-bridge::admin.command_focus'))
                ->icon(Heroicon::OutlinedMicrophone)
                ->dispatch('capell-agent-bridge-focus-command'),
            Action::make('buildPrompt')
                ->label(__('capell-agent-bridge::admin.build_prompt'))
                ->icon(Heroicon::OutlinedSparkles)
                ->action('buildPrompt'),
        ];
    }

    /** @param array<string, mixed> $payload */
    private function previewCapability(string $capabilityKey, array $payload): void
    {
        $this->discardPendingConfirmation();
        $this->commandError = '';

        try {
            $this->commandResponse = PreviewAgentBridgeAdminCapabilityAction::run(
                $capabilityKey,
                $payload,
                $this->authenticatedUser(),
            );
            $token = $this->commandResponse['confirmationToken'] ?? null;
            $this->pendingConfirmationToken = is_string($token) ? $token : null;

            Notification::make('capell_agent-bridge_command_previewed')
                ->status($this->pendingConfirmationToken === null ? 'success' : 'warning')
                ->title(__($this->pendingConfirmationToken === null
                    ? 'capell-agent-bridge::admin.command_executed'
                    : 'capell-agent-bridge::admin.command_confirmation_required'))
                ->send();
        } catch (Throwable $exception) {
            $this->commandResponse = [];
            $this->pendingConfirmationToken = null;
            $this->setSafeCommandError($exception);
        }
    }

    private function refreshSuggestions(string $query): void
    {
        $allowedKeys = array_column($this->capabilityCatalog(), 'key');
        $suggestions = SuggestAgentBridgeCapabilitiesAction::run(
            query: $query,
            server: CapabilityServerEnum::Site,
            limit: 12,
        )['suggestions'];

        $this->commandSuggestions = array_values(array_slice(array_filter(
            $suggestions,
            static fn (array $suggestion): bool => in_array($suggestion['key'] ?? null, $allowedKeys, true),
        ), 0, 6));
    }

    private function discardPendingConfirmation(): void
    {
        if ($this->pendingConfirmationToken === null) {
            return;
        }

        try {
            resolve(AgentBridgeAdminConfirmationStore::class)->forget(
                $this->authenticatedUser(),
                $this->pendingConfirmationToken,
            );
        } catch (Throwable) {
        }

        $this->pendingConfirmationToken = null;
    }

    private function authenticatedUser(): Authenticatable
    {
        $user = Filament::auth()->user() ?? auth()->user();

        throw_unless($user instanceof Authenticatable, AuthenticationException::class);

        return $user;
    }

    /** @return array<string, mixed> */
    private function decodePayload(string $json): array
    {
        $json = trim($json);

        if (! str_starts_with($json, '{')) {
            throw ValidationException::withMessages([
                'payload' => __('capell-agent-bridge::admin.command_payload_object'),
            ]);
        }

        $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($payload)) {
            throw ValidationException::withMessages([
                'payload' => __('capell-agent-bridge::admin.command_payload_object'),
            ]);
        }

        return AgentBridgeValue::associativeArray($payload, 'Command payload');
    }

    /** @param array<string, mixed> $payload */
    private function encodePayload(array $payload): string
    {
        return json_encode(
            $payload === [] ? (object) [] : $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    /** @return array<string, mixed> */
    private function payloadTemplate(CapabilityData $capability): array
    {
        $schema = $capability->inputSchema ?? [];
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $required = is_array($schema['required'] ?? null) ? $schema['required'] : [];
        $payload = [];

        foreach ($properties as $key => $property) {
            if (! is_string($key) || ! is_array($property)) {
                continue;
            }

            if (! in_array($key, $required, true) && ! array_key_exists('default', $property)) {
                continue;
            }

            $payload[$key] = $property['default'] ?? match ($property['type'] ?? 'string') {
                'array' => [],
                'boolean' => false,
                'integer', 'number' => 0,
                'object' => (object) [],
                default => '',
            };
        }

        return $payload;
    }

    private function setSafeCommandError(Throwable $exception): void
    {
        if ($exception instanceof ValidationException) {
            $message = collect($exception->errors())->flatten()->first();
            $this->commandError = is_string($message) ? $message : (string) __('capell-agent-bridge::admin.command_failed');

            return;
        }

        if ($exception instanceof JsonException) {
            $this->commandError = (string) __('capell-agent-bridge::admin.command_payload_invalid_json');

            return;
        }

        if ($exception instanceof AuthorizationException || $exception instanceof AuthenticationException) {
            $this->commandError = $exception->getMessage() !== ''
                ? $exception->getMessage()
                : (string) __('capell-agent-bridge::admin.command_forbidden');

            return;
        }

        report($exception);
        $this->commandError = (string) __('capell-agent-bridge::admin.command_failed');
    }
}

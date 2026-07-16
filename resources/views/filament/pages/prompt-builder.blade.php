<x-filament-panels::page>
    <div
        class="space-y-6"
        x-data="{
            listening: false,
            voiceAvailable: false,
            voiceError: '',
            recognition: null,
            init() {
                this.voiceAvailable = Boolean(window.SpeechRecognition || window.webkitSpeechRecognition)
            },
            startVoice() {
                const Recognition = window.SpeechRecognition || window.webkitSpeechRecognition

                if (! Recognition) {
                    this.voiceError = @js(__('capell-agent-bridge::admin.command_voice_unavailable'))
                    return
                }

                this.voiceError = ''
                this.recognition = new Recognition()
                this.recognition.lang = document.documentElement.lang || 'en-GB'
                this.recognition.interimResults = false
                this.recognition.continuous = false
                this.recognition.onstart = () => { this.listening = true }
                this.recognition.onend = () => { this.listening = false }
                this.recognition.onerror = () => {
                    this.voiceError = @js(__('capell-agent-bridge::admin.command_voice_error'))
                    this.listening = false
                }
                this.recognition.onresult = (event) => {
                    const transcript = event.results?.[0]?.[0]?.transcript || ''

                    if (transcript !== '') {
                        $wire.set('command', transcript)
                        $wire.planCommand()
                    }
                }
                this.recognition.start()
            },
            stopVoice() {
                this.recognition?.stop()
            },
        }"
        x-on:capell-agent-bridge-focus-command.window="
            $nextTick(() =>
                document.getElementById('capell-ai-command')?.focus(),
            )
        "
    >
        <section
            class="relative isolate overflow-hidden rounded-2xl bg-gray-950 px-6 py-7 text-white shadow-sm ring-1 ring-white/10 sm:px-8 sm:py-9"
        >
            <div
                class="pointer-events-none absolute inset-0 -z-10 bg-[radial-gradient(circle_at_top_left,rgba(99,102,241,0.38),transparent_42%),radial-gradient(circle_at_bottom_right,rgba(14,165,233,0.24),transparent_36%)]"
            ></div>

            <div
                class="flex flex-col gap-6 xl:flex-row xl:items-end xl:justify-between"
            >
                <div class="max-w-3xl">
                    <div
                        class="mb-4 flex flex-wrap gap-2 text-xs font-medium text-indigo-100"
                    >
                        <span
                            class="rounded-full bg-white/10 px-3 py-1 ring-1 ring-white/15 ring-inset"
                        >
                            {{ __('capell-agent-bridge::admin.command_preview_first') }}
                        </span>
                        <span
                            class="rounded-full bg-white/10 px-3 py-1 ring-1 ring-white/15 ring-inset"
                        >
                            {{ __('capell-agent-bridge::admin.command_permission_aware') }}
                        </span>
                        <span
                            class="rounded-full bg-white/10 px-3 py-1 ring-1 ring-white/15 ring-inset"
                        >
                            {{ __('capell-agent-bridge::admin.command_audited') }}
                        </span>
                    </div>

                    <p class="text-sm font-semibold tracking-[0.18em] text-indigo-200 uppercase">
                        {{ __('capell-agent-bridge::admin.command_eyebrow') }}
                    </p>
                    <h2
                        class="mt-2 text-2xl font-semibold tracking-tight sm:text-3xl"
                    >
                        {{ __('capell-agent-bridge::admin.command_heading') }}
                    </h2>
                    <p class="mt-3 max-w-2xl text-sm leading-6 text-gray-300 sm:text-base">
                        {{ __('capell-agent-bridge::admin.command_description') }}
                    </p>
                </div>

                <div
                    class="grid grid-cols-3 gap-3 text-center text-xs text-gray-300 sm:min-w-80"
                >
                    <div
                        class="rounded-xl bg-white/5 px-3 py-3 ring-1 ring-white/10 ring-inset"
                    >
                        <strong
                            class="block text-lg text-white"
                            >{{ count($this->capabilityCatalog()) }}</strong
                        >
                        {{ __('capell-agent-bridge::admin.command_capabilities') }}
                    </div>
                    <div
                        class="rounded-xl bg-white/5 px-3 py-3 ring-1 ring-white/10 ring-inset"
                    >
                        <x-filament::icon
                            icon="heroicon-o-microphone"
                            class="mx-auto mb-1 h-5 w-5 text-indigo-300"
                        />
                        {{ __('capell-agent-bridge::admin.command_voice_ready') }}
                    </div>
                    <div
                        class="rounded-xl bg-white/5 px-3 py-3 ring-1 ring-white/10 ring-inset"
                    >
                        <x-filament::icon
                            icon="heroicon-o-shield-check"
                            class="mx-auto mb-1 h-5 w-5 text-emerald-300"
                        />
                        {{ __('capell-agent-bridge::admin.command_confirmed_writes') }}
                    </div>
                </div>
            </div>
        </section>

        <div
            class="grid gap-6 xl:grid-cols-[minmax(0,1.4fr)_minmax(22rem,0.8fr)]"
        >
            <x-filament::section>
                <x-slot name="heading">
                    {{ __('capell-agent-bridge::admin.command_request_heading') }}
                </x-slot>

                <x-slot name="description">
                    {{ __('capell-agent-bridge::admin.command_request_description') }}
                </x-slot>

                <div class="space-y-4">
                    <div class="relative">
                        <label
                            class="sr-only"
                            for="capell-ai-command"
                        >
                            {{ __('capell-agent-bridge::admin.command_request_label') }}
                        </label>
                        <textarea
                            id="capell-ai-command"
                            class="focus:border-primary-500 focus:ring-primary-500/20 dark:focus:border-primary-400 block min-h-32 w-full resize-y rounded-xl border border-gray-300 bg-white px-4 py-3 pr-14 text-base text-gray-950 shadow-sm transition outline-none focus:ring-2 dark:border-white/10 dark:bg-white/5 dark:text-white"
                            placeholder="{{ __('capell-agent-bridge::admin.command_request_placeholder') }}"
                            wire:model.live.debounce.250ms="command"
                        ></textarea>

                        <button
                            type="button"
                            class="hover:bg-primary-50 hover:text-primary-600 focus:ring-primary-500 dark:hover:bg-primary-500/20 dark:hover:text-primary-300 absolute top-3 right-3 inline-flex h-10 w-10 items-center justify-center rounded-lg bg-gray-100 text-gray-600 transition focus:ring-2 focus:outline-none dark:bg-white/10 dark:text-gray-300"
                            x-show="voiceAvailable && !listening"
                            x-on:click="startVoice"
                            title="{{ __('capell-agent-bridge::admin.command_start_voice') }}"
                            aria-label="{{ __('capell-agent-bridge::admin.command_start_voice') }}"
                        >
                            <x-filament::icon
                                icon="heroicon-o-microphone"
                                class="h-5 w-5"
                            />
                        </button>

                        <button
                            type="button"
                            class="bg-danger-50 text-danger-600 focus:ring-danger-500 dark:bg-danger-500/20 dark:text-danger-300 absolute top-3 right-3 inline-flex h-10 w-10 animate-pulse items-center justify-center rounded-lg focus:ring-2 focus:outline-none"
                            x-cloak
                            x-show="listening"
                            x-on:click="stopVoice"
                            title="{{ __('capell-agent-bridge::admin.command_stop_voice') }}"
                            aria-label="{{ __('capell-agent-bridge::admin.command_stop_voice') }}"
                        >
                            <x-filament::icon
                                icon="heroicon-o-stop"
                                class="h-5 w-5"
                            />
                        </button>
                    </div>

                    <div
                        class="flex flex-wrap items-center gap-2 text-xs text-gray-500 dark:text-gray-400"
                        aria-live="polite"
                    >
                        <span
                            x-show="listening"
                            x-cloak
                            class="text-danger-600 dark:text-danger-400 font-medium"
                        >
                            {{ __('capell-agent-bridge::admin.command_listening') }}
                        </span>
                        <span x-show="!voiceAvailable">
                            {{ __('capell-agent-bridge::admin.command_voice_optional') }}
                        </span>
                        <span
                            x-text="voiceError"
                            class="text-danger-600 dark:text-danger-400"
                        ></span>
                        <span>
                            {{ __('capell-agent-bridge::admin.command_voice_privacy') }}
                        </span>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        @foreach ($this->commandExamples() as $example)
                            <button
                                type="button"
                                class="hover:border-primary-300 hover:text-primary-700 focus:ring-primary-500 dark:hover:border-primary-500/50 dark:hover:text-primary-300 rounded-full border border-gray-200 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 shadow-sm transition focus:ring-2 focus:outline-none dark:border-white/10 dark:bg-white/5 dark:text-gray-300"
                                wire:click="useCommandExample(@js($example['command']))"
                            >
                                {{ $example['label'] }}
                            </button>
                        @endforeach
                    </div>

                    <div class="flex flex-wrap gap-3">
                        <x-filament::button
                            icon="heroicon-o-sparkles"
                            wire:click="executeCommand"
                            wire:loading.attr="disabled"
                            wire:target="executeCommand,planCommand,previewSelectedCapability"
                        >
                            {{ __('capell-agent-bridge::admin.command_preview_action') }}
                        </x-filament::button>

                        <x-filament::button
                            color="gray"
                            icon="heroicon-o-list-bullet"
                            wire:click="planCommand"
                            wire:loading.attr="disabled"
                        >
                            {{ __('capell-agent-bridge::admin.command_plan_action') }}
                        </x-filament::button>
                    </div>
                </div>
            </x-filament::section>

            <x-filament::section>
                <x-slot name="heading">
                    {{ __('capell-agent-bridge::admin.command_suggestions_heading') }}
                </x-slot>

                <x-slot name="description">
                    {{ __('capell-agent-bridge::admin.command_suggestions_description') }}
                </x-slot>

                <div class="space-y-2">
                    @forelse ($commandSuggestions as $suggestion)
                        <button
                            type="button"
                            class="group hover:border-primary-300 hover:bg-primary-50/50 focus:ring-primary-500 dark:hover:border-primary-500/50 dark:hover:bg-primary-500/10 w-full rounded-xl border border-gray-200 p-3 text-left transition focus:ring-2 focus:outline-none dark:border-white/10"
                            wire:click="chooseCapability(@js($suggestion['key']))"
                        >
                            <span
                                class="flex items-start justify-between gap-3"
                            >
                                <span>
                                    <span
                                        class="group-hover:text-primary-700 dark:group-hover:text-primary-300 block text-sm font-semibold text-gray-950 dark:text-white"
                                    >
                                        {{ $suggestion['name'] }}
                                    </span>
                                    <span
                                        class="mt-1 block text-xs leading-5 text-gray-500 dark:text-gray-400"
                                    >
                                        {{ $suggestion['description'] }}
                                    </span>
                                </span>
                                <span
                                    @class ([
                                    'shrink-0 rounded-full px-2 py-0.5 text-[0.6875rem] font-semibold uppercase tracking-wide',
                                    'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' => ($suggestion['risk'] ?? '') === 'read',
                                    'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300' => ($suggestion['risk'] ?? '') !== 'read',
                                ])
                                >
                                    {{ __('capell-agent-bridge::admin.command_risks.' . ($suggestion['risk'] ?? 'read')) }}
                                </span>
                            </span>
                        </button>
                    @empty
                        <p class="rounded-xl bg-gray-50 px-4 py-5 text-center text-sm text-gray-500 dark:bg-white/5 dark:text-gray-400">
                            {{ __('capell-agent-bridge::admin.command_no_suggestions') }}
                        </p>
                    @endforelse
                </div>
            </x-filament::section>
        </div>

        @if ($commandError !== '')
            <div
                class="border-danger-200 bg-danger-50 text-danger-800 dark:border-danger-500/30 dark:bg-danger-500/10 dark:text-danger-200 flex items-start gap-3 rounded-xl border p-4 text-sm"
                role="alert"
            >
                <x-filament::icon
                    icon="heroicon-o-exclamation-triangle"
                    class="mt-0.5 h-5 w-5 shrink-0"
                />
                <p>{{ $commandError }}</p>
            </div>
        @endif

        @php ($selectedCapability = $this->selectedCapability())

        @if ($selectedCapability !== null || $commandResponse !== [])
            <div class="grid gap-6 xl:grid-cols-2">
                @if ($selectedCapability !== null)
                    <x-filament::section>
                        <x-slot name="heading">
                            {{ __('capell-agent-bridge::admin.command_plan_heading') }}
                        </x-slot>

                        <x-slot name="description">
                            {{ $selectedCapability['name'] }} · {{ $selectedCapability['key'] }}
                        </x-slot>

                        <div class="space-y-4">
                            @if (($commandPlan['summary'] ?? '') !== '')
                                <p class="rounded-lg bg-gray-50 px-3 py-2 text-sm text-gray-700 dark:bg-white/5 dark:text-gray-300">
                                    {{ $commandPlan['summary'] }}
                                </p>
                            @endif

                            <div class="flex flex-wrap gap-2">
                                <span
                                    class="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-700 dark:bg-white/10 dark:text-gray-300"
                                >
                                    {{ __('capell-agent-bridge::admin.command_categories.' . $selectedCapability['category']) }}
                                </span>
                                <span
                                    class="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-700 dark:bg-white/10 dark:text-gray-300"
                                >
                                    {{ __('capell-agent-bridge::admin.command_risks.' . $selectedCapability['risk']) }}
                                </span>
                                @if ($selectedCapability['requiresConfirmation'])
                                    <span
                                        class="rounded-full bg-amber-50 px-2.5 py-1 text-xs font-medium text-amber-700 dark:bg-amber-500/10 dark:text-amber-300"
                                    >
                                        {{ __('capell-agent-bridge::admin.command_confirmation_badge') }}
                                    </span>
                                @endif
                            </div>

                            @if (data_get($selectedCapability, 'inputSchema.required', []) !== [])
                                <div>
                                    <p class="text-xs font-semibold tracking-wide text-gray-500 uppercase dark:text-gray-400">
                                        {{ __('capell-agent-bridge::admin.command_required_fields') }}
                                    </p>
                                    <div class="mt-2 flex flex-wrap gap-2">
                                        @foreach (data_get($selectedCapability, 'inputSchema.required', []) as $field)
                                            <code
                                                class="rounded bg-gray-100 px-2 py-1 text-xs text-gray-700 dark:bg-white/10 dark:text-gray-300"
                                                >{{ $field }}</code
                                            >
                                        @endforeach
                                    </div>
                                </div>
                            @endif

                            <div>
                                <label
                                    for="capell-ai-payload"
                                    class="mb-2 block text-sm font-medium text-gray-950 dark:text-white"
                                >
                                    {{ __('capell-agent-bridge::admin.command_payload_label') }}
                                </label>
                                <textarea
                                    id="capell-ai-payload"
                                    class="focus:border-primary-500 focus:ring-primary-500/20 block min-h-52 w-full resize-y rounded-xl border border-gray-300 bg-gray-950 px-4 py-3 font-mono text-sm text-gray-100 shadow-sm outline-none focus:ring-2 dark:border-white/10"
                                    spellcheck="false"
                                    wire:model="payloadJson"
                                ></textarea>
                                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                                    {{ __('capell-agent-bridge::admin.command_payload_help') }}
                                </p>
                            </div>

                            <x-filament::button
                                icon="heroicon-o-eye"
                                wire:click="previewSelectedCapability"
                                wire:loading.attr="disabled"
                            >
                                {{ __('capell-agent-bridge::admin.command_preview_exact') }}
                            </x-filament::button>
                        </div>
                    </x-filament::section>
                @endif

                @if ($commandResponse !== [])
                    <x-filament::section>
                        <x-slot name="heading">
                            {{ $pendingConfirmationToken !== null
                                ? __('capell-agent-bridge::admin.command_confirmation_heading')
                                : __('capell-agent-bridge::admin.command_result_heading') }}
                        </x-slot>

                        <x-slot name="description">
                            {{ __('capell-agent-bridge::admin.command_result_description') }}
                        </x-slot>

                        <div
                            class="space-y-4"
                            aria-live="polite"
                        >
                            <div class="flex items-center gap-2">
                                <span
                                    @class ([
                                    'rounded-full px-2.5 py-1 text-xs font-semibold uppercase tracking-wide',
                                    'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300' => ($commandResponse['mode'] ?? '') === 'preview',
                                    'bg-danger-50 text-danger-700 dark:bg-danger-500/10 dark:text-danger-300' => ($commandResponse['mode'] ?? '') === 'rejected',
                                    'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' => in_array(($commandResponse['mode'] ?? ''), ['executed', 'confirmed'], true),
                                ])
                                >
                                    {{ __('capell-agent-bridge::admin.command_modes.' . ($commandResponse['mode'] ?? 'preview')) }}
                                </span>
                                <code
                                    class="text-xs text-gray-500 dark:text-gray-400"
                                    >{{ $commandResponse['capability'] ?? '' }}</code
                                >
                            </div>

                            <p class="text-sm leading-6 font-medium text-gray-950 dark:text-white">
                                {{ $this->responseMessage() }}
                            </p>

                            @foreach ($this->responseWarnings() as $warning)
                                <div
                                    class="flex gap-2 rounded-lg bg-amber-50 p-3 text-sm text-amber-800 dark:bg-amber-500/10 dark:text-amber-200"
                                >
                                    <x-filament::icon
                                        icon="heroicon-o-exclamation-circle"
                                        class="mt-0.5 h-5 w-5 shrink-0"
                                    />
                                    <span>{{ $warning }}</span>
                                </div>
                            @endforeach

                            <details
                                class="group rounded-xl border border-gray-200 dark:border-white/10"
                            >
                                <summary
                                    class="cursor-pointer list-none px-4 py-3 text-sm font-medium text-gray-700 dark:text-gray-300"
                                >
                                    {{ __('capell-agent-bridge::admin.command_exact_data') }}
                                </summary>
                                <pre
                                    class="max-h-96 overflow-auto border-t border-gray-200 bg-gray-950 p-4 text-xs leading-5 text-gray-100 dark:border-white/10"
                                    >{{ $this->responseDataJson() }}</pre
                                >
                            </details>

                            @if ($pendingConfirmationToken !== null)
                                <div
                                    class="rounded-xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-500/30 dark:bg-amber-500/10"
                                >
                                    <p class="text-sm font-semibold text-amber-900 dark:text-amber-100">
                                        {{ __('capell-agent-bridge::admin.command_confirm_question') }}
                                    </p>
                                    <p class="mt-1 text-xs leading-5 text-amber-800 dark:text-amber-200">
                                        {{ __('capell-agent-bridge::admin.command_confirm_help') }}
                                    </p>
                                    <div class="mt-4 flex flex-wrap gap-3">
                                        <x-filament::button
                                            color="warning"
                                            icon="heroicon-o-check"
                                            wire:click="confirmPendingCapability"
                                            wire:loading.attr="disabled"
                                        >
                                            {{ __('capell-agent-bridge::admin.command_confirm_action') }}
                                        </x-filament::button>
                                        <x-filament::button
                                            color="gray"
                                            wire:click="cancelPendingCapability"
                                        >
                                            {{ __('capell-agent-bridge::admin.command_cancel_action') }}
                                        </x-filament::button>
                                    </div>
                                </div>
                            @endif

                            @if ($this->responseActionUrl() !== null)
                                <x-filament::button
                                    tag="a"
                                    :href="$this->responseActionUrl()"
                                    icon="heroicon-o-arrow-top-right-on-square"
                                >
                                    {{ __('capell-agent-bridge::admin.command_open_result') }}
                                </x-filament::button>
                            @endif
                        </div>
                    </x-filament::section>
                @endif
            </div>
        @endif

        <x-filament::section
            collapsible
            collapsed
        >
            <x-slot name="heading">
                {{ __('capell-agent-bridge::admin.command_catalog_heading') }}
            </x-slot>

            <x-slot name="description">
                {{ __('capell-agent-bridge::admin.command_catalog_description') }}
            </x-slot>

            <div class="space-y-6">
                @foreach ($this->groupedCapabilityCatalog() as $category => $capabilities)
                    <div>
                        <h3
                            class="mb-3 text-sm font-semibold text-gray-950 capitalize dark:text-white"
                        >
                            {{ __('capell-agent-bridge::admin.command_categories.' . $category) }}
                        </h3>
                        <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                            @foreach ($capabilities as $capability)
                                <button
                                    type="button"
                                    class="hover:border-primary-300 hover:bg-primary-50/50 focus:ring-primary-500 dark:hover:border-primary-500/50 dark:hover:bg-primary-500/10 rounded-xl border border-gray-200 p-4 text-left transition focus:ring-2 focus:outline-none dark:border-white/10"
                                    wire:click="chooseCapability(@js($capability['key']))"
                                >
                                    <span
                                        class="block text-sm font-semibold text-gray-950 dark:text-white"
                                    >
                                        {{ $capability['name'] }}
                                    </span>
                                    <span
                                        class="mt-1 block text-xs leading-5 text-gray-500 dark:text-gray-400"
                                    >
                                        {{ $capability['description'] }}
                                    </span>
                                    <span
                                        class="mt-3 flex items-center justify-between gap-3 text-[0.6875rem] font-medium tracking-wide text-gray-500 uppercase dark:text-gray-400"
                                    >
                                        <span
                                            >{{ __('capell-agent-bridge::admin.command_risks.' . $capability['risk']) }}</span
                                        >
                                        <code
                                            class="tracking-normal normal-case"
                                            >{{ $capability['key'] }}</code
                                        >
                                    </span>
                                </button>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </x-filament::section>

        <x-filament::section
            collapsible
            collapsed
        >
            <x-slot name="heading">
                {{ __('capell-agent-bridge::admin.prompt_builder_title') }}
            </x-slot>

            <x-slot name="description">
                {{ __('capell-agent-bridge::admin.prompt_builder_legacy_description') }}
            </x-slot>

            <div
                class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(20rem,28rem)]"
            >
                <div>
                    {{ $this->form }}

                    <div class="mt-6">
                        <x-filament::button
                            icon="heroicon-o-sparkles"
                            wire:click="buildPrompt"
                        >
                            {{ __('capell-agent-bridge::admin.build_prompt') }}
                        </x-filament::button>
                    </div>
                </div>

                <div>
                    <label
                        class="mb-2 block text-sm font-medium text-gray-950 dark:text-white"
                        for="capell-prepared-prompt"
                    >
                        {{ __('capell-agent-bridge::admin.prepared_prompt') }}
                    </label>
                    <textarea
                        id="capell-prepared-prompt"
                        class="block min-h-96 w-full resize-y rounded-xl border border-gray-300 bg-white p-4 font-mono text-sm text-gray-950 shadow-sm dark:border-white/10 dark:bg-white/5 dark:text-white"
                        readonly
                        >{{ $preparedPrompt }}</textarea
                    >
                </div>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>

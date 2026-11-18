<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Filament\Resources\Users\RelationManagers;

use BackedEnum;
use Capell\AgentBridge\Actions\CreateAgentBridgeTokenAction;
use Capell\AgentBridge\Actions\RevokeAgentBridgeTokenAction;
use Capell\AgentBridge\Actions\RotateAgentBridgeTokenAction;
use Capell\AgentBridge\Data\CapabilityData;
use Capell\AgentBridge\Enums\AgentBridgeTokenLifecycleStatus;
use Capell\AgentBridge\Enums\CapabilityServerEnum;
use Capell\AgentBridge\Http\Middleware\RequireAgentBridgeScope;
use Capell\AgentBridge\Models\CapellAgentBridgeToken;
use Capell\AgentBridge\Support\AgentBridgeMcpV1Policy;
use Capell\AgentBridge\Support\AgentBridgePageAccess;
use Capell\AgentBridge\Support\AgentBridgeTokenManagementAccess;
use Capell\AgentBridge\Support\AgentBridgeValue;
use Capell\AgentBridge\Support\CapellAgentBridgeCapabilityRegistry;
use Capell\Core\Models\Site;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Override;

final class AgentBridgeTokensRelationManager extends RelationManager
{
    protected static string|BackedEnum|null $icon = Heroicon::OutlinedKey;

    protected static string $relationship = 'agentBridgeTokens';

    protected static bool $shouldSkipAuthorization = true;

    #[Override]
    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('capell-agent-bridge::admin.tokens');
    }

    /**
     * @param  Builder<CapellAgentBridgeToken>  $query
     * @return Builder<CapellAgentBridgeToken>
     */
    public static function scopedQueryForUser(Builder $query, Model $user): Builder
    {
        return $query
            ->where('user_type', $user->getMorphClass())
            ->where('user_id', $user->getKey());
    }

    /** @return array<string, string> */
    public static function scopeOptions(): array
    {
        return [
            '*' => __('capell-agent-bridge::admin.scope_all'),
            'capell.cache.run' => __('capell-agent-bridge::admin.scope_cache_run'),
            RequireAgentBridgeScope::KNOWLEDGE_READ => __('capell-agent-bridge::admin.scope_knowledge_read'),
            'capell.pages.read' => __('capell-agent-bridge::admin.scope_pages_read'),
            'capell.pages.write' => __('capell-agent-bridge::admin.scope_pages_write'),
            'capell.pages.publish' => __('capell-agent-bridge::admin.scope_pages_publish'),
            'capell.media.write' => __('capell-agent-bridge::admin.scope_media_write'),
            'capell.packages.read' => __('capell-agent-bridge::admin.scope_packages_read'),
            'capell.packages.install' => __('capell-agent-bridge::admin.scope_packages_install'),
            'capell.sites.read' => __('capell-agent-bridge::admin.scope_sites_read'),
            'capell.sites.configure' => __('capell-agent-bridge::admin.scope_sites_configure'),
        ];
    }

    /**
     * Sites the owner can reach, which a new token may be limited to.
     *
     * @return array<int, string>
     */
    public static function siteOptions(Model $owner): array
    {
        if (! $owner instanceof Authenticatable) {
            return [];
        }

        $query = Site::query()->orderBy('name');

        try {
            AgentBridgePageAccess::constrainSiteQuery($query, $owner);
        } catch (ValidationException) {
            return [];
        }

        $options = [];

        foreach ($query->get() as $site) {
            $siteId = AgentBridgeValue::integer($site->getKey(), 'Site key');
            $name = $site->getAttribute('name');
            $options[$siteId] = is_string($name) && $name !== '' ? $name : '#' . $siteId;
        }

        return $options;
    }

    /**
     * Only capabilities a token can run, which are those in the MCP v1 contract. Offering
     * others would let a token look limited to work it can never do.
     *
     * @return array<string, string>
     */
    public static function capabilityOptions(): array
    {
        return resolve(AgentBridgeMcpV1Policy::class)
            ->allowedCapabilities(resolve(CapellAgentBridgeCapabilityRegistry::class)->visibleFor(CapabilityServerEnum::Site))
            ->mapWithKeys(fn (CapabilityData $capability): array => [$capability->key => $capability->name])
            ->sort()
            ->all();
    }

    /**
     * @param  Builder<CapellAgentBridgeToken>  $query
     * @return Builder<CapellAgentBridgeToken>
     */
    public static function applyLifecycleStatusFilter(Builder $query, mixed $status): Builder
    {
        if (! is_string($status) || $status === '') {
            return $query;
        }

        $lifecycleStatus = AgentBridgeTokenLifecycleStatus::tryFrom($status);

        if (! $lifecycleStatus instanceof AgentBridgeTokenLifecycleStatus) {
            return $query;
        }

        return $query->withLifecycleStatus($lifecycleStatus);
    }

    /**
     * @return Builder<CapellAgentBridgeToken>
     */
    #[Override]
    public function getRelationship(): Builder
    {
        return self::scopedQueryForUser(CapellAgentBridgeToken::query(), $this->ownerRecord);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => self::scopedQueryForUser(CapellAgentBridgeToken::query(), $this->ownerRecord))
            ->columns([
                TextColumn::make('name')
                    ->label(__('capell-agent-bridge::admin.token_name'))
                    ->searchable(),
                TextColumn::make('scopes')
                    ->label(__('capell-agent-bridge::admin.scopes'))
                    ->formatStateUsing(fn (CapellAgentBridgeToken $record): string => implode(', ', $record->scopes))
                    ->wrap(),
                TextColumn::make('limits')
                    ->label(__('capell-agent-bridge::admin.token_limits'))
                    ->state(fn (CapellAgentBridgeToken $record): string => $this->limitsSummary($record))
                    ->wrap(),
                TextColumn::make('status')
                    ->label(__('capell-agent-bridge::admin.token_status'))
                    ->state(fn (CapellAgentBridgeToken $record): string => $record->lifecycleStatus()->getLabel())
                    ->badge()
                    ->color(fn (CapellAgentBridgeToken $record): string => $record->lifecycleStatus()->getColor()),
                TextColumn::make('created_from_ip')
                    ->label(__('capell-agent-bridge::admin.created_from_ip'))
                    ->limit(12)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('last_used_at')
                    ->label(__('capell-agent-bridge::admin.last_used_at'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('rotated_at')
                    ->label(__('capell-agent-bridge::admin.rotated_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('revoked_at')
                    ->label(__('capell-agent-bridge::admin.revoked_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('expires_at')
                    ->label(__('capell-agent-bridge::admin.expires_at'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('lifecycle_status')
                    ->label(__('capell-agent-bridge::admin.token_status'))
                    ->options(AgentBridgeTokenLifecycleStatus::class)
                    ->query(fn (Builder $query, array $data): Builder => self::applyLifecycleStatusFilter($query, $data['value'] ?? null)),
            ])
            ->recordActions([
                Action::make('rotate')
                    ->label(__('capell-agent-bridge::admin.rotate_token'))
                    ->requiresConfirmation()
                    ->visible(fn (CapellAgentBridgeToken $record): bool => $record->isUsable() && $this->actorCanManageTokens())
                    ->action(function (CapellAgentBridgeToken $record): void {
                        $this->authorizeTokenManagement();
                        $result = RotateAgentBridgeTokenAction::run($record);

                        Notification::make('capell_agent_bridge_token_rotated')
                            ->success()
                            ->title(__('capell-agent-bridge::admin.token_rotated'))
                            ->body(__('capell-agent-bridge::admin.token_plaintext_once', [
                                'token' => $result['plainTextToken'],
                            ]))
                            ->send();
                    }),
                Action::make('revoke')
                    ->label(__('capell-agent-bridge::admin.revoke_token'))
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (CapellAgentBridgeToken $record): bool => $record->isUsable() && $this->actorCanManageTokens())
                    ->action(function (CapellAgentBridgeToken $record): CapellAgentBridgeToken {
                        $this->authorizeTokenManagement();

                        return RevokeAgentBridgeTokenAction::run($record);
                    }),
            ])
            ->headerActions([
                Action::make('createToken')
                    ->label(__('capell-agent-bridge::admin.create_token'))
                    ->visible(fn (): bool => $this->actorCanManageTokens())
                    ->schema([
                        TextInput::make('name')
                            ->label(__('capell-agent-bridge::admin.token_name'))
                            ->required()
                            ->maxLength(255),
                        CheckboxList::make('scopes')
                            ->label(__('capell-agent-bridge::admin.scopes'))
                            ->options(self::scopeOptions())
                            ->columns(2)
                            ->required(),
                        Select::make('site_ids')
                            ->label(__('capell-agent-bridge::admin.token_sites'))
                            ->helperText(__('capell-agent-bridge::admin.token_sites_help'))
                            ->multiple()
                            ->options(fn (): array => self::siteOptions($this->ownerRecord)),
                        Select::make('capability_keys')
                            ->label(__('capell-agent-bridge::admin.token_capabilities'))
                            ->helperText(__('capell-agent-bridge::admin.token_capabilities_help'))
                            ->multiple()
                            ->options(fn (): array => self::capabilityOptions()),
                        TextInput::make('requests_per_minute')
                            ->label(__('capell-agent-bridge::admin.token_requests_per_minute'))
                            ->helperText(__('capell-agent-bridge::admin.token_requests_per_minute_help'))
                            ->integer()
                            ->minValue(1)
                            ->maxValue(10000),
                        DateTimePicker::make('expires_at')
                            ->label(__('capell-agent-bridge::admin.expires_at'))
                            ->required()
                            ->default(fn (): CarbonImmutable => CarbonImmutable::now()->addDays(CreateAgentBridgeTokenAction::defaultLifetimeDays()))
                            ->minDate(fn (): CarbonImmutable => CarbonImmutable::now())
                            ->maxDate(fn (): CarbonImmutable => CarbonImmutable::now()->addDays(CreateAgentBridgeTokenAction::maximumLifetimeDays()))
                            ->helperText(fn (): string => (string) __('capell-agent-bridge::admin.token_expiry_help', ['days' => CreateAgentBridgeTokenAction::maximumLifetimeDays()])),
                    ])
                    ->action(function (array $data): void {
                        $this->authorizeTokenManagement();
                        $user = $this->ownerRecord;

                        if (! $user instanceof Authenticatable) {
                            return;
                        }

                        $expiresAt = $data['expires_at'] ?? null;

                        if (is_string($expiresAt) && $expiresAt !== '') {
                            $expiresAt = CarbonImmutable::parse($expiresAt);
                        }

                        if (! $expiresAt instanceof DateTimeInterface) {
                            $expiresAt = null;
                        }

                        // The action refuses a site or capability the owner cannot use.
                        $siteIds = array_values(array_map(intval(...), (array) ($data['site_ids'] ?? [])));
                        $capabilityKeys = array_values(array_map(
                            static fn (mixed $key): string => is_string($key) ? $key : '',
                            (array) ($data['capability_keys'] ?? []),
                        ));
                        $quota = $data['requests_per_minute'] ?? null;

                        $result = CreateAgentBridgeTokenAction::run(
                            $user,
                            (string) $data['name'],
                            array_values($data['scopes'] ?? []),
                            $expiresAt,
                            request()->ip(),
                            $siteIds,
                            $capabilityKeys,
                            is_numeric($quota) ? (int) $quota : null,
                        );

                        Notification::make('capell_agent_bridge_token_created')
                            ->success()
                            ->title(__('capell-agent-bridge::admin.token_created'))
                            ->body(__('capell-agent-bridge::admin.token_plaintext_once', [
                                'token' => $result['plainTextToken'],
                            ]))
                            ->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }

    #[Override]
    protected function canCreate(): bool
    {
        return false;
    }

    private function limitsSummary(CapellAgentBridgeToken $record): string
    {
        $limits = [];

        if ($record->restrictedSiteIds() !== null) {
            $limits[] = (string) __('capell-agent-bridge::admin.token_limit_sites', ['count' => count($record->restrictedSiteIds())]);
        }

        if ($record->capability_keys !== null) {
            $limits[] = (string) __('capell-agent-bridge::admin.token_limit_capabilities', ['count' => count($record->capability_keys)]);
        }

        if ($record->requests_per_minute !== null) {
            $limits[] = (string) __('capell-agent-bridge::admin.token_limit_quota', ['count' => $record->requests_per_minute]);
        }

        return $limits === [] ? (string) __('capell-agent-bridge::admin.token_limit_none') : implode(', ', $limits);
    }

    private function actorCanManageTokens(): bool
    {
        return AgentBridgeTokenManagementAccess::allows(auth()->user(), $this->ownerRecord);
    }

    private function authorizeTokenManagement(): void
    {
        AgentBridgeTokenManagementAccess::authorize(auth()->user(), $this->ownerRecord);
    }
}

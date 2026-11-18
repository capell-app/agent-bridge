<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Filament\Resources\Users\RelationManagers;

use BackedEnum;
use Capell\AgentBridge\Actions\ApproveAgentBridgeConfirmationAction;
use Capell\AgentBridge\Actions\DeclineAgentBridgeConfirmationAction;
use Capell\AgentBridge\Models\CapellAgentBridgeConfirmation;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Override;
use Throwable;

final class AgentBridgeConfirmationsRelationManager extends RelationManager
{
    private const int DISPLAY_STRING_LIMIT = 2000;

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedShieldCheck;

    protected static string $relationship = 'agentBridgeConfirmations';

    protected static bool $shouldSkipAuthorization = true;

    #[Override]
    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('capell-agent-bridge::admin.confirmations');
    }

    /**
     * @param  Builder<CapellAgentBridgeConfirmation>  $query
     * @return Builder<CapellAgentBridgeConfirmation>
     */
    public static function scopedQueryForUser(Builder $query, Model $user): Builder
    {
        return $query
            ->where('user_type', $user->getMorphClass())
            ->where('user_id', $user->getKey());
    }

    public static function approvalStatusLabel(CapellAgentBridgeConfirmation $record): string
    {
        return (string) __('capell-agent-bridge::admin.approval_statuses.' . self::approvalStatus($record));
    }

    public static function approvalStatusColor(CapellAgentBridgeConfirmation $record): string
    {
        return match (self::approvalStatus($record)) {
            'awaiting' => 'warning',
            'approved', 'used' => 'success',
            'declined' => 'danger',
            default => 'gray',
        };
    }

    /**
     * @return Builder<CapellAgentBridgeConfirmation>
     */
    #[Override]
    public function getRelationship(): Builder
    {
        return self::scopedQueryForUser(CapellAgentBridgeConfirmation::query(), $this->ownerRecord);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => self::scopedQueryForUser(CapellAgentBridgeConfirmation::query(), $this->ownerRecord))
            ->columns([
                TextColumn::make('capability_key')
                    ->label(__('capell-agent-bridge::admin.capability'))
                    ->searchable(),
                TextColumn::make('scope')
                    ->label(__('capell-agent-bridge::admin.scope')),
                TextColumn::make('approval_status')
                    ->label(__('capell-agent-bridge::admin.approval_status'))
                    ->state(fn (CapellAgentBridgeConfirmation $record): string => self::approvalStatusLabel($record))
                    ->badge()
                    ->color(fn (CapellAgentBridgeConfirmation $record): string => self::approvalStatusColor($record)),
                TextColumn::make('used_at')
                    ->label(__('capell-agent-bridge::admin.used_at'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('expires_at')
                    ->label(__('capell-agent-bridge::admin.expires_at'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Filter::make('awaiting_approval')
                    ->label(__('capell-agent-bridge::admin.awaiting_approval'))
                    ->query(fn (Builder $query): Builder => $query
                        ->where('requires_approval', true)
                        ->whereNull('approved_at')
                        ->whereNull('declined_at')
                        ->whereNull('used_at')
                        ->where('expires_at', '>', now())),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label(__('capell-agent-bridge::admin.approve_confirmation'))
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->modalHeading(__('capell-agent-bridge::admin.approve_confirmation_heading'))
                    ->modalDescription(__('capell-agent-bridge::admin.approve_confirmation_description'))
                    ->modalSubmitActionLabel(__('capell-agent-bridge::admin.approve_confirmation'))
                    ->schema([
                        TextEntry::make('capability_key')
                            ->label(__('capell-agent-bridge::admin.capability')),
                        TextEntry::make('preview_summary')
                            ->label(__('capell-agent-bridge::admin.confirmation_preview'))
                            ->state(fn (CapellAgentBridgeConfirmation $record): string => self::json($record->preview))
                            ->fontFamily(FontFamily::Mono),
                        TextEntry::make('payload_summary')
                            ->label(__('capell-agent-bridge::admin.confirmation_payload'))
                            ->state(fn (CapellAgentBridgeConfirmation $record): string => self::json($record->payload))
                            ->fontFamily(FontFamily::Mono),
                    ])
                    ->visible(fn (CapellAgentBridgeConfirmation $record): bool => $this->actorCanApprove($record))
                    ->action(function (CapellAgentBridgeConfirmation $record): void {
                        $actor = auth()->user();

                        throw_unless($actor instanceof Authenticatable, AuthorizationException::class);

                        ApproveAgentBridgeConfirmationAction::run($record, $actor);

                        Notification::make('capell_agent_bridge_confirmation_approved')
                            ->success()
                            ->title(__('capell-agent-bridge::admin.confirmation_approved'))
                            ->body(__('capell-agent-bridge::admin.confirmation_approved_body'))
                            ->send();
                    }),
                Action::make('decline')
                    ->label(__('capell-agent-bridge::admin.decline_confirmation'))
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription(__('capell-agent-bridge::admin.decline_confirmation_description'))
                    ->visible(fn (CapellAgentBridgeConfirmation $record): bool => DeclineAgentBridgeConfirmationAction::allows($record, auth()->user()))
                    ->action(function (CapellAgentBridgeConfirmation $record): void {
                        $actor = auth()->user();

                        throw_unless($actor instanceof Authenticatable, AuthorizationException::class);

                        DeclineAgentBridgeConfirmationAction::run($record, $actor);

                        Notification::make('capell_agent_bridge_confirmation_declined')
                            ->success()
                            ->title(__('capell-agent-bridge::admin.confirmation_declined'))
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

    private static function approvalStatus(CapellAgentBridgeConfirmation $record): string
    {
        return match (true) {
            $record->used_at !== null => 'used',
            $record->declined_at !== null => 'declined',
            $record->expires_at->isPast() => 'expired',
            ! $record->requires_approval => 'not_required',
            $record->approved_at !== null => 'approved',
            default => 'awaiting',
        };
    }

    /** @param array<array-key, mixed> $value */
    private static function json(array $value): string
    {
        return (string) json_encode(self::shortenLongStrings($value), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    /**
     * Uploaded file contents and similar long values are shown by their length, so the
     * modal stays readable; the approval itself is bound to the full payload hash.
     *
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private static function shortenLongStrings(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::shortenLongStrings($item);
            } elseif (is_string($item) && mb_strlen($item) > self::DISPLAY_STRING_LIMIT) {
                $value[$key] = mb_substr($item, 0, self::DISPLAY_STRING_LIMIT) . (string) __('capell-agent-bridge::admin.confirmation_value_shortened', ['characters' => mb_strlen($item)]);
            }
        }

        return $value;
    }

    private function actorCanApprove(CapellAgentBridgeConfirmation $record): bool
    {
        $actor = auth()->user();

        if (! $actor instanceof Authenticatable) {
            return false;
        }

        try {
            ApproveAgentBridgeConfirmationAction::assertCanApprove($record, $actor);
        } catch (Throwable) {
            return false;
        }

        return true;
    }
}

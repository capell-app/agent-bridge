<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Filament\Resources\Users\RelationManagers;

use BackedEnum;
use Capell\AgentBridge\Actions\RevertAgentBridgeAuditEntryAction;
use Capell\AgentBridge\Exceptions\AgentBridgeRevertRefusedException;
use Capell\AgentBridge\Models\CapellAgentBridgeAuditEntry;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Override;

final class AgentBridgeAuditEntriesRelationManager extends RelationManager
{
    protected static string|BackedEnum|null $icon = Heroicon::OutlinedClipboardDocumentList;

    protected static string $relationship = 'agentBridgeAuditEntries';

    protected static bool $shouldSkipAuthorization = true;

    /** @var array<int, bool> answered per request; not kept between requests */
    private array $revertableEntries = [];

    #[Override]
    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('capell-agent-bridge::admin.audit_entries');
    }

    /**
     * @param  Builder<CapellAgentBridgeAuditEntry>  $query
     * @return Builder<CapellAgentBridgeAuditEntry>
     */
    public static function scopedQueryForUser(Builder $query, Model $user): Builder
    {
        return $query
            ->where(function (Builder $scopedQuery) use ($user): void {
                $scopedQuery
                    ->where(function (Builder $directUserQuery) use ($user): void {
                        $directUserQuery
                            ->where('user_type', $user->getMorphClass())
                            ->where('user_id', $user->getKey());
                    })
                    ->orWhereHas('agentBridgeToken', function (Builder $tokenQuery) use ($user): void {
                        $tokenQuery
                            ->where('user_type', $user->getMorphClass())
                            ->where('user_id', $user->getKey());
                    });
            });
    }

    /**
     * @return Builder<CapellAgentBridgeAuditEntry>
     */
    #[Override]
    public function getRelationship(): Builder
    {
        return self::scopedQueryForUser(CapellAgentBridgeAuditEntry::query(), $this->ownerRecord);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => self::scopedQueryForUser(CapellAgentBridgeAuditEntry::query(), $this->ownerRecord))
            ->columns([
                TextColumn::make('event')
                    ->label(__('capell-agent-bridge::admin.event'))
                    ->searchable(),
                TextColumn::make('capability_key')
                    ->label(__('capell-agent-bridge::admin.capability'))
                    ->searchable(),
                TextColumn::make('scope')
                    ->label(__('capell-agent-bridge::admin.scope')),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('reverted_at')
                    ->label(__('capell-agent-bridge::admin.reverted_at'))
                    ->dateTime()
                    ->placeholder('-'),
            ])
            ->filters([
                SelectFilter::make('event')
                    ->label(__('capell-agent-bridge::admin.event'))
                    ->options(fn (): array => self::scopedQueryForUser(CapellAgentBridgeAuditEntry::query(), $this->ownerRecord)
                        ->whereNotNull('event')
                        ->distinct()
                        ->orderBy('event')
                        ->pluck('event', 'event')
                        ->all()),
                SelectFilter::make('capability_key')
                    ->label(__('capell-agent-bridge::admin.capability'))
                    ->options(fn (): array => self::scopedQueryForUser(CapellAgentBridgeAuditEntry::query(), $this->ownerRecord)
                        ->whereNotNull('capability_key')
                        ->distinct()
                        ->orderBy('capability_key')
                        ->pluck('capability_key', 'capability_key')
                        ->all()),
            ])
            ->recordActions([
                Action::make('revert')
                    ->label(__('capell-agent-bridge::admin.revert_change'))
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading(__('capell-agent-bridge::admin.revert_change_heading'))
                    ->modalDescription(__('capell-agent-bridge::admin.revert_change_description'))
                    ->modalSubmitActionLabel(__('capell-agent-bridge::admin.revert_change'))
                    ->visible(fn (CapellAgentBridgeAuditEntry $record): bool => $this->canRevert($record))
                    ->action(function (CapellAgentBridgeAuditEntry $record): void {
                        $actor = auth()->user();

                        try {
                            throw_unless($actor instanceof Authenticatable, AgentBridgeRevertRefusedException::class, __('capell-agent-bridge::admin.revert_forbidden'));

                            RevertAgentBridgeAuditEntryAction::run($record, $actor);
                        } catch (AgentBridgeRevertRefusedException $exception) {
                            Notification::make('capell_agent_bridge_revert_refused')
                                ->danger()
                                ->title($exception->getMessage())
                                ->send();

                            return;
                        }

                        // The answer for this entry changed; ask again when the table renders.
                        unset($this->revertableEntries[$record->id]);

                        Notification::make('capell_agent_bridge_reverted')
                            ->success()
                            ->title(__('capell-agent-bridge::admin.revert_done'))
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

    /**
     * Whether Undo change is offered for an entry, worked out once per entry and request:
     * the table asks several times per row, and each answer loads the page and checks the
     * person's access. Entries without a revert point are answered without a query.
     */
    private function canRevert(CapellAgentBridgeAuditEntry $record): bool
    {
        return $this->revertableEntries[$record->id] ??= $record->pendingRevertPoint() !== null
            && RevertAgentBridgeAuditEntryAction::allows($record, auth()->user());
    }
}

<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Actions;

use Capell\Admin\Enums\CapellPermission;
use Capell\AgentBridge\Data\AgentBridgeRevertPointData;
use Capell\AgentBridge\Enums\AgentBridgeRevertKindEnum;
use Capell\AgentBridge\Exceptions\AgentBridgeRevertRefusedException;
use Capell\AgentBridge\Models\CapellAgentBridgeAuditEntry;
use Capell\AgentBridge\Support\AgentBridgePageAccess;
use Capell\AgentBridge\Support\AgentBridgePageRevertPoints;
use Capell\AgentBridge\Support\AgentBridgePolicyAuthorizer;
use Capell\Core\Actions\PageDeletedAction;
use Capell\Core\EventSourcing\Exceptions\EventSourcingException;
use Capell\Core\EventSourcing\Exceptions\RollbackBlocked;
use Capell\Core\EventSourcing\Rollback\Actions\ApplyRollbackAction;
use Capell\Core\EventSourcing\Rollback\RollbackService;
use Capell\Core\Models\Page;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Undoes a change a connected assistant made, from its entry in the audit log. Only a
 * person in the admin can do this, never the assistant. They need the same access the
 * undo itself needs: the page's site, and permission to roll the page back or delete it.
 * The undo is refused once anyone has changed the page since, because it would also undo
 * their work; the page history in the editor is the place for that.
 *
 * @method static CapellAgentBridgeAuditEntry run(CapellAgentBridgeAuditEntry $entry, Authenticatable $actor)
 */
final class RevertAgentBridgeAuditEntryAction
{
    use AsFake;
    use AsObject;

    /**
     * Whether the actor could revert this entry now. Used to offer the action; the
     * revert itself checks everything again under a lock.
     */
    public static function allows(CapellAgentBridgeAuditEntry $entry, ?Authenticatable $actor): bool
    {
        $point = $entry->pendingRevertPoint();

        if (! $point instanceof AgentBridgeRevertPointData || ! $actor instanceof Model) {
            return false;
        }

        try {
            self::authorizedPage($point, $actor);
        } catch (AgentBridgeRevertRefusedException) {
            return false;
        }

        return true;
    }

    public function handle(CapellAgentBridgeAuditEntry $entry, Authenticatable $actor): CapellAgentBridgeAuditEntry
    {
        throw_unless($actor instanceof Model, AgentBridgeRevertRefusedException::class, __('capell-agent-bridge::admin.revert_forbidden'));

        DB::transaction(function () use ($entry, $actor): void {
            $locked = CapellAgentBridgeAuditEntry::query()->whereKey($entry->getKey())->lockForUpdate()->first();
            $point = $locked?->pendingRevertPoint();

            throw_unless($locked instanceof CapellAgentBridgeAuditEntry && $point instanceof AgentBridgeRevertPointData, AgentBridgeRevertRefusedException::class, __('capell-agent-bridge::admin.revert_unavailable'));

            // Lock the page before reading its version, so an edit saved between this check
            // and the undo cannot be undone with it.
            $page = Page::query()->whereKey(self::authorizedPage($point, $actor)->getKey())->lockForUpdate()->first();

            throw_unless($page instanceof Page, AgentBridgeRevertRefusedException::class, __('capell-agent-bridge::admin.revert_unavailable'));

            throw_if(
                AgentBridgePageRevertPoints::currentVersion($page) !== $point->versionAfter,
                AgentBridgeRevertRefusedException::class,
                __('capell-agent-bridge::admin.revert_page_changed_since'),
            );

            match ($point->kind) {
                AgentBridgeRevertKindEnum::RollbackPage => $this->rollBack($page, $point, $actor),
                AgentBridgeRevertKindEnum::DeletePage => $this->deleteDraft($page),
            };

            $locked->forceFill([
                'reverted_by_type' => $actor->getMorphClass(),
                'reverted_by_id' => $actor->getKey(),
                'reverted_at' => now(),
            ])->save();

            AuditAgentBridgeCapabilityAction::run(
                event: 'capell_agent-bridge.capability.reverted',
                capabilityKey: $locked->capability_key,
                scope: $locked->scope,
                payload: ['audit_entry_id' => $locked->getKey()],
                token: $locked->agentBridgeToken()->first(),
                user: $actor,
            );
        });

        return $entry->refresh();
    }

    private static function authorizedPage(AgentBridgeRevertPointData $point, Authenticatable $actor): Page
    {
        try {
            $page = AgentBridgePageAccess::authorizedPage($actor, $point->pageId);
        } catch (ModelNotFoundException|ValidationException) {
            throw new AgentBridgeRevertRefusedException(__('capell-agent-bridge::admin.revert_forbidden'));
        }

        // The id is only trusted together with the page it was recorded for.
        throw_if($page->uuid !== $point->pageUuid, AgentBridgeRevertRefusedException::class, __('capell-agent-bridge::admin.revert_unavailable'));

        $authorizer = resolve(AgentBridgePolicyAuthorizer::class);
        $allowed = match ($point->kind) {
            AgentBridgeRevertKindEnum::RollbackPage => $authorizer->allows($actor, 'update', $page)
                && $authorizer->allows($actor, CapellPermission::RollbackPage->name()),
            AgentBridgeRevertKindEnum::DeletePage => $authorizer->allows($actor, 'delete', $page),
        };

        throw_unless($allowed, AgentBridgeRevertRefusedException::class, __('capell-agent-bridge::admin.revert_forbidden'));

        return $page;
    }

    private function rollBack(Page $page, AgentBridgeRevertPointData $point, Authenticatable $actor): void
    {
        $versionBefore = $point->versionBefore ?? 0;

        try {
            $targetSiteId = data_get(resolve(RollbackService::class)->targetStateAt($point->pageUuid, $versionBefore), 'attributes.site_id');
        } catch (EventSourcingException) {
            // The earlier version is no longer stored, so there is nothing to return to.
            throw new AgentBridgeRevertRefusedException(__('capell-agent-bridge::admin.revert_unavailable'));
        }

        // The earlier version must not put the page on a site the actor cannot reach.
        try {
            if (is_int($targetSiteId)) {
                AgentBridgePageAccess::authorizeSite($actor, $targetSiteId, 'page_id');
            }
        } catch (ValidationException) {
            throw new AgentBridgeRevertRefusedException(__('capell-agent-bridge::admin.revert_forbidden'));
        }

        try {
            ApplyRollbackAction::run($page, $versionBefore);
        } catch (RollbackBlocked) {
            throw new AgentBridgeRevertRefusedException(__('capell-agent-bridge::admin.revert_blocked'));
        } catch (EventSourcingException) {
            throw new AgentBridgeRevertRefusedException(__('capell-agent-bridge::admin.revert_unavailable'));
        }
    }

    private function deleteDraft(Page $page): void
    {
        throw_unless(
            $page->publishVisibilityState()->value === 'draft',
            AgentBridgeRevertRefusedException::class,
            __('capell-agent-bridge::admin.revert_page_changed_since'),
        );

        // Deleting a page deletes the pages under it. A page added under the draft since
        // leaves the draft's own version unchanged, so it must refuse the undo here, or
        // the undo would take that page offline too.
        throw_if(
            $page->children()->withTrashed()->exists(),
            AgentBridgeRevertRefusedException::class,
            __('capell-agent-bridge::admin.revert_page_changed_since'),
        );

        PageDeletedAction::run($page);
    }
}

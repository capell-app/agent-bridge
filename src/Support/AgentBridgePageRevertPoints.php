<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Support;

use Capell\AgentBridge\Data\AgentBridgeRevertPointData;
use Capell\AgentBridge\Enums\AgentBridgeRevertKindEnum;
use Capell\Core\EventSourcing\Rollback\RollbackService;
use Capell\Core\Models\Page;

/**
 * Builds the revert point a page capability records with its change, from the page's
 * event-sourced versions. A change that recorded no new version cannot be told apart
 * from later work, so it gets no revert point rather than a misleading one.
 */
final class AgentBridgePageRevertPoints
{
    public static function currentVersion(Page $page): int
    {
        $uuid = $page->uuid;

        return is_string($uuid) && $uuid !== '' ? resolve(RollbackService::class)->currentVersion($uuid) : 0;
    }

    public static function afterUpdate(Page $page, int $versionBefore): ?AgentBridgeRevertPointData
    {
        $versionAfter = self::currentVersion($page);

        if ($versionBefore < 1 || $versionAfter <= $versionBefore || ! is_string($page->uuid)) {
            return null;
        }

        return new AgentBridgeRevertPointData(
            kind: AgentBridgeRevertKindEnum::RollbackPage,
            pageId: AgentBridgeValue::integer($page->getKey(), 'Page key'),
            pageUuid: $page->uuid,
            versionAfter: $versionAfter,
            versionBefore: $versionBefore,
        );
    }

    /**
     * A new page may have no version yet: Core records the first one when the page is next
     * saved with its translations. Any later save therefore moves it past this point.
     */
    public static function afterCreate(Page $page): ?AgentBridgeRevertPointData
    {
        $versionAfter = self::currentVersion($page);

        if (! is_string($page->uuid) || $page->uuid === '') {
            return null;
        }

        return new AgentBridgeRevertPointData(
            kind: AgentBridgeRevertKindEnum::DeletePage,
            pageId: AgentBridgeValue::integer($page->getKey(), 'Page key'),
            pageUuid: $page->uuid,
            versionAfter: $versionAfter,
        );
    }
}

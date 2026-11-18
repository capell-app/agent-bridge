<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Enums;

enum AgentBridgeRevertKindEnum: string
{
    /** Roll an edited page back to the version it had before the change. */
    case RollbackPage = 'page_rollback';

    /** Delete a draft page the change created. */
    case DeletePage = 'page_delete';
}

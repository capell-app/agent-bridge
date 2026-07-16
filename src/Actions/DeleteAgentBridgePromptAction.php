<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Actions;

use Capell\AgentBridge\Models\CapellAgentBridgeSavedPrompt;
use Illuminate\Contracts\Auth\Authenticatable;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class DeleteAgentBridgePromptAction
{
    use AsFake;
    use AsObject;

    public function handle(Authenticatable $user, CapellAgentBridgeSavedPrompt $savedPrompt): bool
    {
        if (! $savedPrompt->belongsToUser($user)) {
            return false;
        }

        return (bool) $savedPrompt->delete();
    }
}

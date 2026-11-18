<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;

/**
 * The previewed change needs the person behind the token to approve it in the admin
 * before the agent can confirm it. Nothing has run, and the confirmation stays open.
 */
final class AgentBridgeConfirmationAwaitingApprovalException extends AuthorizationException {}

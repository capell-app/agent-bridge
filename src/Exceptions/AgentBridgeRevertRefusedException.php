<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Exceptions;

use RuntimeException;

/**
 * A change recorded in the Agent Bridge audit log could not be undone. The message is
 * translated and safe to show to the person who asked for the revert.
 */
final class AgentBridgeRevertRefusedException extends RuntimeException {}

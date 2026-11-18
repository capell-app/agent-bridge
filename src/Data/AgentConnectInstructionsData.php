<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Data;

/**
 * What a person needs to connect one coding agent to this site. The snippet holds the
 * plain token, so it is shown once and never stored or logged.
 */
final readonly class AgentConnectInstructionsData
{
    public function __construct(
        public string $client,
        public string $transport,
        public string $serverUrl,
        public string $tokenPreview,
        public string $snippet,
        public string $revokeHint,
    ) {}
}

<?php

declare(strict_types=1);

return [
    'mcp_v1' => [
        'denied' => 'The Agent Bridge MCP v1 request was denied.',
        'invalid_payload' => 'The Agent Bridge MCP v1 payload is invalid.',
        'invalid_output' => 'The Agent Bridge MCP v1 response could not be produced safely.',
        'errors' => [
            'confirmation_invalid' => 'The confirmation is invalid, expired, used, or no longer matches the request.',
            'conflict' => 'The resource changed or is no longer an editable draft. Refresh it and retry.',
            'forbidden' => 'The authenticated user is not allowed to perform this operation.',
            'internal_error' => 'The Agent Bridge could not complete the request safely.',
            'invalid_request' => 'The request does not match the frozen MCP v1 contract.',
            'not_found' => 'The requested resource was not found.',
        ],
    ],
    'auth' => [
        'invalid_bearer_token' => 'Invalid Agent Bridge bearer token.',
        'missing_bearer_token' => 'Missing Agent Bridge bearer token.',
        'unlinked_user' => 'Agent Bridge token is not linked to a user.',
    ],
    'rate_limited' => 'Agent Bridge rate limit exceeded. Try again in :seconds seconds.',
];

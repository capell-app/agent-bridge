<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Support;

use Capell\AgentBridge\Exceptions\AgentBridgeConfirmationAwaitingApprovalException;
use Capell\AgentBridge\Exceptions\AgentBridgeMcpV1ConfirmationException;
use Capell\AgentBridge\Exceptions\AgentBridgeMcpV1ConflictException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

final class AgentBridgeMcpV1ErrorMapper
{
    /** @return array{ok: false, error: array{code: string, message: string}} */
    public function map(Throwable $exception): array
    {
        [$code, $messageKey] = match (true) {
            $exception instanceof AgentBridgeConfirmationAwaitingApprovalException => ['approval_required', 'approval_required'],
            $exception instanceof AgentBridgeMcpV1ConfirmationException => ['confirmation_invalid', 'confirmation_invalid'],
            $exception instanceof AgentBridgeMcpV1ConflictException => ['conflict', 'conflict'],
            $exception instanceof AuthorizationException => ['forbidden', 'forbidden'],
            $exception instanceof ModelNotFoundException => ['not_found', 'not_found'],
            $exception instanceof ValidationException,
            $exception instanceof InvalidArgumentException => ['invalid_request', 'invalid_request'],
            default => ['internal_error', 'internal_error'],
        };

        Log::warning('Agent Bridge MCP v1 request failed.', [
            'error_code' => $code,
            'exception' => $exception::class,
            'detail' => $exception->getMessage(),
        ]);

        return [
            'ok' => false,
            'error' => [
                'code' => $code,
                'message' => AgentBridgeValue::string(
                    __('capell-agent-bridge::messages.mcp_v1.errors.' . $messageKey),
                    'MCP v1 error message',
                ),
            ],
        ];
    }
}

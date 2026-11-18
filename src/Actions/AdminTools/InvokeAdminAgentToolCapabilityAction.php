<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Actions\AdminTools;

use Capell\Admin\Data\Agent\AgentAdminToolInvocationData;
use Capell\Admin\Data\Agent\AgentAdminToolResultData;
use Capell\Admin\Support\Agent\AgentAdminToolInvocationService;
use Capell\Admin\Support\Agent\AgentAdminToolRegistry;
use Capell\AgentBridge\Contracts\CapellAgentBridgeCapabilityAction;
use Capell\AgentBridge\Data\CapabilityInvocationData;
use Capell\AgentBridge\Data\CapabilityResultData;
use Capell\AgentBridge\Support\AdminTools\AdminAgentToolCapabilities;
use Capell\AgentBridge\Support\AgentBridgeRecordResolver;
use Capell\AgentBridge\Support\AgentBridgeTokenSiteRestriction;
use Capell\AgentBridge\Support\AgentBridgeValue;
use Capell\Core\Enums\Agent\AgentToolEffect;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Override;

/**
 * Runs one Core Admin agent tool for an Agent Bridge capability.
 *
 * The site is resolved through Agent Bridge, so a token's site limit and the owner's
 * site access both apply before Admin sees the request. Admin then repeats its own
 * availability, permission and preview checks. A write is executed through Admin's
 * invocation service, which re-previews under row locks and refuses the write if the
 * preview has changed since it was confirmed.
 */
final readonly class InvokeAdminAgentToolCapabilityAction implements CapellAgentBridgeCapabilityAction
{
    public function __construct(
        private AgentAdminToolRegistry $tools,
        private AgentAdminToolInvocationService $service,
        private AgentBridgeRecordResolver $records,
    ) {}

    #[Override]
    public function preview(CapabilityInvocationData $invocation): CapabilityResultData
    {
        [$toolName, $user, $siteId, $payload] = $this->context($invocation);
        $tool = $this->tools->tool($toolName);
        $adminInvocation = new AgentAdminToolInvocationData($toolName, $payload, $siteId, $user);

        throw_unless($tool->isAvailable($adminInvocation), AuthorizationException::class, (string) __('capell-agent-bridge::admin.admin_tool_unavailable'));

        $tool->authorize($adminInvocation);

        if ($tool->definition()->effect === AgentToolEffect::Read) {
            return new CapabilityResultData(
                ok: true,
                message: (string) __('capell-agent-bridge::admin.admin_tool_read_preview'),
                data: ['tool' => $toolName, 'site_id' => $siteId],
            );
        }

        return $this->result($tool->preview($adminInvocation), 'admin_tool_write_preview');
    }

    #[Override]
    public function execute(CapabilityInvocationData $invocation): CapabilityResultData
    {
        [$toolName, $user, $siteId, $payload] = $this->context($invocation);
        $tool = $this->tools->tool($toolName);

        throw_unless(
            $tool->isAvailable(new AgentAdminToolInvocationData($toolName, $payload, $siteId, $user)),
            AuthorizationException::class,
            (string) __('capell-agent-bridge::admin.admin_tool_unavailable'),
        );

        $result = $this->service->invoke($toolName, $payload, $user, $siteId);

        // Agent Bridge has already shown the preview and taken the confirmation, so
        // the Admin confirmation is redeemed at once. Admin still compares the fresh
        // preview with the one it issued and refuses the write if they differ.
        if ($result->mode === 'confirmation_required' && $result->confirmationToken !== null) {
            $result = $this->service->invoke($toolName, [], $user, $siteId, $result->confirmationToken);
        }

        return $this->result($result, 'admin_tool_executed');
    }

    /**
     * @return array{0: string, 1: Authenticatable, 2: int, 3: array<string, mixed>}
     */
    private function context(CapabilityInvocationData $invocation): array
    {
        $toolName = AdminAgentToolCapabilities::toolFor($invocation->capability->key);

        throw_if($toolName === null, AuthorizationException::class, (string) __('capell-agent-bridge::admin.admin_tool_unavailable'));
        throw_unless($invocation->user instanceof Authenticatable, AuthorizationException::class, (string) __('capell-agent-bridge::admin.admin_tool_user_required'));
        throw_if(
            AdminAgentToolCapabilities::isInstallationWide($toolName) && AgentBridgeTokenSiteRestriction::current() !== null,
            AuthorizationException::class,
            (string) __('capell-agent-bridge::admin.admin_tool_site_limited_token'),
        );

        $payload = $invocation->payload;
        $reference = $payload['site'] ?? $payload['site_id'] ?? null;
        unset($payload['site'], $payload['site_id']);

        $site = $this->records->site(
            $invocation->user,
            is_int($reference) || is_string($reference) ? $reference : null,
        );

        return [$toolName, $invocation->user, AgentBridgeValue::integer($site->getKey(), 'Site key'), $payload];
    }

    private function result(AgentAdminToolResultData $result, string $fallbackMessage): CapabilityResultData
    {
        return new CapabilityResultData(
            ok: $result->ok,
            message: $result->message ?? (string) __('capell-agent-bridge::admin.' . $fallbackMessage),
            data: $result->data,
        );
    }
}

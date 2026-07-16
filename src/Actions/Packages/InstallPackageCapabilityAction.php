<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Actions\Packages;

use Capell\Admin\Filament\Pages\ExtensionsPage;
use Capell\AgentBridge\Contracts\CapellAgentBridgeCapabilityAction;
use Capell\AgentBridge\Data\CapabilityInvocationData;
use Capell\AgentBridge\Data\CapabilityResultData;
use Capell\AgentBridge\Support\AgentBridgePackagePresenter;
use Capell\AgentBridge\Support\AgentBridgePackageResolver;
use Capell\AgentBridge\Support\AgentBridgePolicyAuthorizer;
use Capell\Core\Actions\InstallPackageAction;
use Capell\Core\Data\PackageData;
use Capell\Core\Support\CapellCoreManager;
use Illuminate\Validation\ValidationException;

final class InstallPackageCapabilityAction implements CapellAgentBridgeCapabilityAction
{
    public function preview(CapabilityInvocationData $invocation): CapabilityResultData
    {
        [$package, $arguments] = $this->context($invocation);

        return new CapabilityResultData(
            ok: true,
            message: (string) __('capell-agent-bridge::admin.result_package_install_preview'),
            data: [
                'package' => resolve(AgentBridgePackagePresenter::class)->present($package, includeInstallImpact: true),
                'arguments' => $arguments,
                'source_policy' => 'composer-present-only',
            ],
            warnings: [(string) __('capell-agent-bridge::admin.warning_package_install_boundary')],
        );
    }

    public function execute(CapabilityInvocationData $invocation): CapabilityResultData
    {
        [$package, $arguments] = $this->context($invocation);

        InstallPackageAction::run($package, arguments: $arguments);

        return new CapabilityResultData(
            ok: true,
            message: (string) __('capell-agent-bridge::admin.result_package_installed', [
                'package' => $package->getLabel(),
            ]),
            data: [
                'package' => resolve(AgentBridgePackagePresenter::class)->present($package, includeInstallImpact: true),
            ],
        );
    }

    /** @return array{PackageData, array<string, mixed>} */
    private function context(CapabilityInvocationData $invocation): array
    {
        resolve(AgentBridgePolicyAuthorizer::class)->authorize(
            $invocation->user,
            ExtensionsPage::MANAGE_PERMISSION,
        );
        $payload = validator($invocation->payload, [
            'package' => ['required', 'string', 'max:255'],
            'arguments' => ['sometimes', 'array'],
        ])->validate();
        $package = resolve(AgentBridgePackageResolver::class)->package((string) $payload['package']);
        $arguments = is_array($payload['arguments'] ?? null) ? $payload['arguments'] : [];
        $allowedArguments = $package->getInstallParams();
        $unexpectedArguments = array_values(array_diff(array_keys($arguments), $allowedArguments));

        if ($unexpectedArguments !== []) {
            throw ValidationException::withMessages([
                'arguments' => __('capell-agent-bridge::admin.validation_package_arguments', [
                    'arguments' => implode(', ', $unexpectedArguments),
                ]),
            ]);
        }

        $manager = resolve(CapellCoreManager::class);
        $impact = resolve(AgentBridgePackagePresenter::class)->present($package, includeInstallImpact: true);

        if ($manager->isPackageInstalled($package->name)) {
            throw ValidationException::withMessages([
                'package' => __('capell-agent-bridge::admin.validation_package_already_installed'),
            ]);
        }

        if (($impact['composer_available'] ?? false) !== true) {
            throw ValidationException::withMessages([
                'package' => __('capell-agent-bridge::admin.validation_package_not_composer_available'),
            ]);
        }

        if (($impact['installable'] ?? false) !== true) {
            throw ValidationException::withMessages([
                'package' => __('capell-agent-bridge::admin.validation_package_not_installable'),
            ]);
        }

        return [$package, $arguments];
    }
}

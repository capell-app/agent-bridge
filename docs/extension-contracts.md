# Extension and action examples

<!-- Maintained by scripts/generate-package-readmes.php -->

Use the action functions with records and Data objects supplied by your application.
They pass each argument to the package operation and return its result.

These adapters show container registration. Use the owning package registry when
a contract requires contributor discovery.

Contract adapters wrap an existing implementation. Call their registration function
from your service provider with that implementation; tagged contracts keep their declared tag.
Resolve the backend by its concrete class before registration so the replacement contract
does not resolve itself. Static contract metadata uses one backend class per adapter.

## Contract `Capell\AgentBridge\Contracts\CapellAgentBridgeCapabilityAction`

<!-- example: contract Capell\AgentBridge\Contracts\CapellAgentBridgeCapabilityAction -->

```php
<?php
declare(strict_types=1);

namespace App\CapellExamples\AgentBridge;

final class CapellAgentBridgeCapabilityActionAdapter implements \Capell\AgentBridge\Contracts\CapellAgentBridgeCapabilityAction
{
    public function __construct(private readonly \Capell\AgentBridge\Contracts\CapellAgentBridgeCapabilityAction $backend) {}

    #[\Override]
    public function execute(\Capell\AgentBridge\Data\CapabilityInvocationData $invocation): \Capell\AgentBridge\Data\CapabilityResultData
    {
        return $this->backend->execute($invocation);
    }

    #[\Override]
    public function preview(\Capell\AgentBridge\Data\CapabilityInvocationData $invocation): \Capell\AgentBridge\Data\CapabilityResultData
    {
        return $this->backend->preview($invocation);
    }
}

function registerCapellAgentBridgeCapabilityActionAdapter(\Capell\AgentBridge\Contracts\CapellAgentBridgeCapabilityAction $backend): void
{
    app()->bind(CapellAgentBridgeCapabilityActionAdapter::class, static fn (): CapellAgentBridgeCapabilityActionAdapter => new CapellAgentBridgeCapabilityActionAdapter($backend));
    app()->bind(\Capell\AgentBridge\Contracts\CapellAgentBridgeCapabilityAction::class, CapellAgentBridgeCapabilityActionAdapter::class);
}
```

## Contract `Capell\AgentBridge\Contracts\CapellAgentBridgeCapabilityProvider`

<!-- example: contract Capell\AgentBridge\Contracts\CapellAgentBridgeCapabilityProvider -->

```php
<?php
declare(strict_types=1);

namespace App\CapellExamples\AgentBridge;

final class CapellAgentBridgeCapabilityProviderAdapter implements \Capell\AgentBridge\Contracts\CapellAgentBridgeCapabilityProvider
{
    public function __construct(private readonly \Capell\AgentBridge\Contracts\CapellAgentBridgeCapabilityProvider $backend) {}

    #[\Override]
    public function registerCapabilities(\Capell\AgentBridge\Support\CapellAgentBridgeCapabilityRegistry $registry): void
    {
        $this->backend->registerCapabilities($registry);
    }
}

function registerCapellAgentBridgeCapabilityProviderAdapter(\Capell\AgentBridge\Contracts\CapellAgentBridgeCapabilityProvider $backend): void
{
    app()->bind(CapellAgentBridgeCapabilityProviderAdapter::class, static fn (): CapellAgentBridgeCapabilityProviderAdapter => new CapellAgentBridgeCapabilityProviderAdapter($backend));
    app()->bind(\Capell\AgentBridge\Contracts\CapellAgentBridgeCapabilityProvider::class, CapellAgentBridgeCapabilityProviderAdapter::class);
    app()->tag([CapellAgentBridgeCapabilityProviderAdapter::class], \Capell\AgentBridge\Contracts\CapellAgentBridgeCapabilityProvider::class);
}
```

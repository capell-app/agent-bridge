# Agent Bridge reference

<!-- prettier-ignore-start -->

Developer reference for `capell-app/agent-bridge`. Start with the [README](../README.md) for what the extension does and how to install it.

## Package Details

Agent Bridge is an **Available** Capell package in the **Capell Essentials** product group. It ships as `capell-app/agent-bridge` and extends these surfaces: admin, frontend.

- Status: Available
- Tier: free
- Bundle: foundation
- Composer package: `capell-app/agent-bridge`
- Namespace: `Capell\AgentBridge`
- Theme key: not applicable

## Technical Shape

### Service providers

- `Capell\AgentBridge\Providers\MigrationMetadataServiceProvider`
- `Capell\AgentBridge\Providers\AgentBridgeServiceProvider`

### Config files

- `packages/agent-bridge/config/capell-agent-bridge.php`

### Migrations

- `packages/agent-bridge/database/migrations/2026_05_10_190840_01_create_capell_agent-bridge_tokens_table.php`
- `packages/agent-bridge/database/migrations/2026_05_10_190840_02_create_capell_agent-bridge_confirmations_table.php`
- `packages/agent-bridge/database/migrations/2026_05_10_190840_03_create_capell_agent-bridge_audit_entries_table.php`
- `packages/agent-bridge/database/migrations/2026_05_27_000001_create_capell_agent-bridge_saved_prompts_table.php`
- `packages/agent-bridge/database/migrations/2026_07_09_000001_add_ip_address_hash_to_agent_bridge_audit_entries_table.php`
- `packages/agent-bridge/database/migrations/2026_07_10_000001_drop_plaintext_ip_from_agent_bridge_audit_entries_table.php`
- `packages/agent-bridge/database/migrations/2026_07_20_000001_create_capell_agent_bridge_media_uploads_table.php`
- `packages/agent-bridge/database/migrations/2026_09_29_120000_remove_implicit_timestamp_updates_from_agent_bridge.php`
- `packages/agent-bridge/database/migrations/2026_11_12_000001_hash_created_from_ip_on_agent_bridge_tokens_table.php`
- `packages/agent-bridge/database/migrations/2026_11_12_000002_bound_agent_bridge_tokens_without_expiry.php`
- `packages/agent-bridge/database/migrations/2026_11_13_000001_add_human_approval_to_agent_bridge_confirmations_table.php`
- `packages/agent-bridge/database/migrations/2026_11_13_000002_add_limits_to_agent_bridge_tokens_table.php`
- `packages/agent-bridge/database/migrations/2026_11_13_000003_add_revert_points_to_agent_bridge_audit_entries_table.php`

### Settings migrations

- `packages/agent-bridge/database/settings/2026_05_10_190841_01_add_agent_bridge_settings.php`

### Settings classes

- `AgentBridgeSettings`

### Models

- `CapellAgentBridgeAuditEntry`
- `CapellAgentBridgeConfirmation`
- `CapellAgentBridgeMediaUpload`
- `CapellAgentBridgeSavedPrompt`
- `CapellAgentBridgeToken`

### Filament classes

- `CapellAgentBridgePromptBuilderPage`
- `AgentBridgeAuditEntriesRelationManager`
- `AgentBridgeConfirmationsRelationManager`
- `AgentBridgeTokensRelationManager`
- `AgentBridgeSettingsSchema`

### Livewire components

- `PromptBuilderToolbarAction`

### Route files

- `packages/agent-bridge/routes/agent-bridge.php`

### Extension contracts

- `CapellAgentBridgeCapabilityAction`
- `CapellAgentBridgeCapabilityProvider`
- `ResolvesCapabilityPolicySite`

### Actions

- `InvokeAdminAgentToolCapabilityAction`
- `ApproveAgentBridgeConfirmationAction`
- `AuditAgentBridgeCapabilityAction`
- `AuthenticateLocalAgentBridgeClientAction`
- `BuildAgentBridgeCapabilityCatalogAction`
- `BuildAgentBridgePrivacyExportAction`
- `BuildAgentBridgePromptAction`
- `BuildAgentConnectInstructionsAction`
- `ClearCapellCacheCapabilityAction`
- `CapellAgentBridgeAuditEntryQuery`
- `ConfirmAgentBridgeAdminCapabilityAction`
- `ConfirmAgentBridgeCapabilityAction`
- `ConfirmAgentBridgeMcpV1CapabilityAction`
- `CreateAgentBridgeTokenAction`
- `DeclineAgentBridgeConfirmationAction`
- `DeleteAgentBridgePromptAction`
- `EraseAgentBridgePrivacyDataAction`
- `FindSavedPromptForUserAction`
- `PrepareAgentBridgeScreenshotAction`
- `HashAgentBridgeIpAddressAction`
- `InterpretAgentBridgeCommandAction`
- `InvokeAgentBridgeCapabilityPreviewAction`
- `InvokeAgentBridgeMcpV1CapabilityAction`
- `ListSavedPromptOptionsAction`
- `UploadMediaCapabilityAction`
- `InspectPackageCapabilityAction`
- `InstallPackageCapabilityAction`
- `SearchPackagesCapabilityAction`
- `ClonePageCapabilityAction`
- `CreateDraftPageCapabilityAction`
- `DisablePageCapabilityAction`
- `InspectPageCapabilityAction`
- `InspectPagePublishingReadinessCapabilityAction`
- `ListPageAuthoringOptionsCapabilityAction`
- `OpenPageEditorCapabilityAction`
- `SearchPagesCapabilityAction`
- `TransitionPagePublicationCapabilityAction`
- `UpdateDraftPageCapabilityAction`
- `PreviewAgentBridgeAdminCapabilityAction`
- `PruneAgentBridgeAuditEntriesAction`
- `QueryAgentBridgeAuditEntriesAction`
- `RecordAgentBridgeTokenUseAction`
- `RevertAgentBridgeAuditEntryAction`
- `RevokeAgentBridgeTokenAction`
- `RotateAgentBridgeTokenAction`
- `SaveAgentBridgePromptAction`
- `InspectSiteCapabilityAction`
- `OpenSiteEditorCapabilityAction`
- `SearchSitesCapabilityAction`
- `SuggestAgentBridgeCapabilitiesAction`

### Data objects

- `AgentBridgeCommandPlanData`
- `AgentBridgePromptData`
- `AgentBridgePruneResultData`
- `AgentBridgeRevertPointData`
- `AgentConnectInstructionsData`
- `AuthenticatedAgentBridgeClientData`
- `ClearCacheCapabilityInputData`
- `ClonePageCapabilityInputData`
- `CreateDraftPageCapabilityInputData`
- `InstallPackageCapabilityInputData`
- `PackageReferenceCapabilityInputData`
- `PageAuthoringOptionsCapabilityInputData`
- `PageIdCapabilityInputData`
- `PageReferenceCapabilityInputData`
- `SearchPackagesCapabilityInputData`
- `SearchPagesCapabilityInputData`
- `SearchSitesCapabilityInputData`
- `SiteReferenceCapabilityInputData`
- `TransitionPagePublicationCapabilityInputData`
- `UpdateDraftPageCapabilityInputData`
- `UploadMediaCapabilityInputData`
- `CapabilityCommandPatternData`
- `CapabilityData`
- `CapabilityInvocationData`
- `CapabilityResultData`
- `CapabilitySuggestionData`

### Command signatures

- `capell:agent-bridge-prune-audit`
- `capell:agent:connect`

### Scheduled commands

- `capell:agent-bridge-prune-audit (daily; manifest declared)`

### Console command classes

- `AgentConnectCommand`
- `PruneAgentBridgeAuditEntriesCommand`

### Manifest contributions

- `admin-page: Capell\AgentBridge\Manifest\AgentBridgeAdminPageContribution`
- `agent-capability: Capell\AgentBridge\Manifest\AgentBridgeBuiltInCapabilitiesContribution`
- `console-command: Capell\AgentBridge\Manifest\AgentBridgeConsoleCommandsContribution`
- `health-check: Capell\AgentBridge\Health\AgentBridgeHealthCheck`
- `migration: Capell\AgentBridge\Manifest\AgentBridgeMigrationsContribution`
- `model: Capell\AgentBridge\Manifest\AgentBridgeModelsContribution`
- `route: Capell\AgentBridge\Manifest\AgentBridgeRoutesContribution`
- `scheduled-job: Capell\AgentBridge\Manifest\AgentBridgeAuditPruneScheduleContribution`
- `schema-extender: Capell\AgentBridge\Manifest\AgentBridgeUserSchemaExtenderContribution`
- `setting: Capell\AgentBridge\Manifest\AgentBridgeSettingsContribution`

### Health checks

- `Capell\AgentBridge\Health\AgentBridgeHealthCheck`

### Blade views

- `packages/agent-bridge/resources/views/filament/pages/prompt-builder.blade.php`
- `packages/agent-bridge/resources/views/livewire/prompt-builder-toolbar-action.blade.php`


## Data Model

- Required tables: `capell_agent_bridge_tokens`, `capell_agent_bridge_confirmations`, `capell_agent_bridge_audit_entries`, `capell_agent_bridge_saved_prompts`, `capell_agent_bridge_media_uploads`.
- Models: `CapellAgentBridgeAuditEntry`, `CapellAgentBridgeConfirmation`, `CapellAgentBridgeMediaUpload`, `CapellAgentBridgeSavedPrompt`, `CapellAgentBridgeToken`.
- Core record references in migrations: `sites via site_id`, `users via user_id`, `media via media_id`.
- Migration files: `2026_05_10_190840_01_create_capell_agent-bridge_tokens_table.php`, `2026_05_10_190840_02_create_capell_agent-bridge_confirmations_table.php`, `2026_05_10_190840_03_create_capell_agent-bridge_audit_entries_table.php`, `2026_05_27_000001_create_capell_agent-bridge_saved_prompts_table.php`, `2026_07_09_000001_add_ip_address_hash_to_agent_bridge_audit_entries_table.php`, `2026_07_10_000001_drop_plaintext_ip_from_agent_bridge_audit_entries_table.php`, `2026_07_20_000001_create_capell_agent_bridge_media_uploads_table.php`, `2026_09_29_120000_remove_implicit_timestamp_updates_from_agent_bridge.php`, `2026_11_12_000001_hash_created_from_ip_on_agent_bridge_tokens_table.php`, `2026_11_12_000002_bound_agent_bridge_tokens_without_expiry.php`, `2026_11_13_000001_add_human_approval_to_agent_bridge_confirmations_table.php`, `2026_11_13_000002_add_limits_to_agent_bridge_tokens_table.php`, `2026_11_13_000003_add_revert_points_to_agent_bridge_audit_entries_table.php`.
- Migration impact: run host migrations through the package install flow before opening package surfaces.
- Deletion/retention behaviour: migrations declare cascade-on-delete relationships and null-on-delete relationships; retention is scheduled through `capell:agent-bridge-prune-audit` (daily; declared in the manifest).

<!-- prettier-ignore-end -->

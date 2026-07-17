## What it does

Agent Bridge connects approved AI agents and external tools to selected Capell capabilities. It can inspect site state and, when the user has the required permission, prepare actions such as creating or updating drafts, changing publication state, clearing cache, or installing a package.

## Use the Capell AI Command Centre

Open the **Capell AI Command Centre** from the Agent Bridge extension page or the **Ask Capell AI** admin control. You can type or speak a request, choose a suggested capability, and review the exact capability and payload Capell resolved before anything runs.

- Read-only capabilities run after their preview.
- Write and other high-risk capabilities stay in preview until you select **Confirm and run**.
- Cancel the pending confirmation if the preview is not what you intended.

The same screen can build and save a reusable, structured prompt for an external Agent Bridge client. Saved prompts belong to the current admin user.

## Manage an agent token

When the user bridge is enabled, edit the user who owns the integration and use the **Tokens** relation. Create a token with only the scopes it needs and, if appropriate, an expiry date. Copy the token when it is shown: Capell displays the plain token only once at creation or rotation.

You can filter tokens by lifecycle status, rotate a usable token, or revoke it. Rotating and revoking require confirmation. The same user record also shows their Agent Bridge confirmations and audit entries, including the capability, scope, event, and dates.

## Settings and safeguards

- The **Show Agent Bridge details on users** setting controls the user-record integration; it must be enabled alongside the corresponding Admin setting before those panels appear.
- Capabilities are limited to the current user's permissions. Do not create broad-scope tokens for routine work.
- Treat the command centre and token management as integration-administrator work. Review previews carefully, and revoke a token immediately if you no longer trust the connected client.
- Audit entries are retained for 90 days by default, then pruned daily. Your developer can change the retention setting; the `capell:agent-bridge-prune-audit` command also accepts a manual `--days` override.
- Migration impact: run host migrations through the package install flow before opening package surfaces.

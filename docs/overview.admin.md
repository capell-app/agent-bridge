## What it does

Agent Bridge connects approved AI agents and external tools to selected Capell capabilities. It can inspect site state and, when the user has the required permission, prepare actions such as creating or updating drafts, changing publication state, clearing cache, or installing a package.

The external MCP v1 server is narrower than the admin command centre: it can read accessible site/page authoring context, create or edit drafts, and upload bounded media. It always refuses publishing, visibility changes, package/cache operations, and AI Creator build/apply/export; publishing remains human-only in admin.

## Use the Capell AI Command Centre

Open the **Capell AI Command Centre** from the Agent Bridge extension page or the **Ask Capell AI** admin control. You can type or speak a request, choose a suggested capability, and review the exact capability and payload Capell resolved before anything runs.

- Read-only capabilities run after their preview.
- Write and other high-risk capabilities stay in preview until you select **Confirm and run**.
- Cancel the pending confirmation if the preview is not what you intended.

The same screen can build and save a reusable, structured prompt for an external Agent Bridge client. Saved prompts belong to the current admin user.

## Manage an agent token

When the user bridge is enabled, edit the user who owns the integration and use the **Tokens** relation. Create a token with only the scopes it needs and an expiry date. Every token expires: the date defaults to 90 days ahead and cannot be more than 365 days ahead. Your developer can change both limits with the `token_default_lifetime_days` and `token_maximum_lifetime_days` settings. Copy the token when it is shown: Capell displays the plain token only once at creation or rotation.

You can also limit a token further when you create it:

- **Sites** keeps the token to the sites you pick. Capabilities run with that limit, so the token cannot read or change pages on any other site, or pages that belong to no site, even when its owner can. Leave it empty to allow every site the owner can reach.
- **Capabilities** keeps the token to the capabilities you pick, within its scopes. The list offers only the capabilities a token can run. The assistant is only shown those capabilities, and any other capability is refused. Leave it empty to allow every capability its scopes cover.
- **Requests per minute** gives the token its own quota. The stricter of this quota and the site-wide limit applies, and the quota still applies when your developer turns the site-wide limit off. Leave it empty to use the site-wide limit.

The **Limits** column on the tokens table shows which of these a token has. Capell checks the token as stored on every request, so narrowing the limits of a token also applies to previews it has already made.

You can filter tokens by lifecycle status, rotate a usable token, or revoke it. Rotating and revoking require confirmation. The same user record also shows their Agent Bridge confirmations and audit entries, including the capability, scope, event, and dates.

## Approve a change from a connected assistant

A token alone cannot apply a high-risk change, which includes every draft edit and upload from a connected assistant. When the assistant previews one, the change waits under **Confirmations** on the user page of the person the token acts as, marked **Waiting for your approval**, and the assistant is told to ask that person.

- Only that person can approve, from their own admin session, and only if they are still allowed to make the change themselves. An administrator cannot approve on their behalf.
- **Approve change** shows the preview and the request the assistant sent. Approving does not change anything yet: the assistant then confirms with the same request, and anything different is refused.
- **Decline** closes the change so the assistant can never apply it. The person, or anyone who manages their Agent Bridge tokens, can decline.
- A change waiting for approval expires after an hour by default (`approval_ttl_minutes`). Your developer chooses which risk levels need approval with `human_approval_risks`; it defaults to high and destructive.

## Undo a change from a connected assistant

The audit entries on a user page list what their tokens did. When an entry records a page edit or a new draft page from an assistant, **Undo change** puts it back: an edited page returns to how it was before the change, and a draft page the assistant created is deleted.

- Undo needs access to the page's site. Undoing an edit also needs permission to roll pages back, and removing a new draft needs permission to delete it. Assistants cannot undo anything themselves.
- Undo is refused once anyone has changed the page since, or added a page under a new draft, so it never removes later work. Use the page history in the editor for those.
- Each change can be undone once. The entry then shows when it was undone, and a new entry records who did it.
- Uploads and other changes cannot be undone from here.

## Settings and safeguards

- The **Show Agent Bridge details on users** setting controls the user-record integration; it must be enabled alongside the corresponding Admin setting before those panels appear.
- Capabilities are limited to the current user's permissions. Do not create broad-scope tokens for routine work.
- Treat the command centre and token management as integration-administrator work. Review previews carefully, and revoke a token immediately if you no longer trust the connected client.
- Audit entries are retained for 90 days by default, then pruned daily. Your developer can change the retention setting; the `capell:agent-bridge-prune-audit` command also accepts a manual `--days` override.
- Pending confirmations are deleted by the same daily command one day after they expire, whatever the audit retention setting. The command reports the two counts separately.
- Migration impact: run host migrations through the package install flow before opening package surfaces.

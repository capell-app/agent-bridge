# Connect Claude Code

Connect Claude Code to a Capell site so it can search pages, read a page's structure and draft changes. Every change is shown as a preview first and only happens when it is confirmed. The agent cannot publish; publishing stays in the Capell admin.

## Before you start

- Agent Bridge is installed and migrated.
- `php artisan capell:doctor` passes the "Agent Bridge: agents can connect" check. The connect command refuses to create a key until it does.
- You know the email address of the admin user the agent should act as. The agent gets that user's permissions and no more.

## Connect over HTTP

Run this on the site, as an operator with shell access:

```bash
php artisan capell:agent:connect --user=editor@example.com
```

The command creates a draft-only agent key and prints one line to paste into your terminal:

```bash
claude mcp add --transport http capell https://your-site.example/agent-bridge/capell --header "Authorization: Bearer cagent-bridge_..."
```

The key is shown once. Copy the line straight away; it cannot be printed again. If you lose it, revoke the key and run the command again.

In production the site address must use `https`, otherwise the command refuses to send the key. Pass `--url=https://your-site.example` when `APP_URL` is not the public address.

## Connect a local site over stdio

For a site running on your own machine, print the local variant instead:

```bash
php artisan capell:agent:connect --user=editor@example.com --local
```

It prints a `claude mcp add capell --env CAPELL_AGENT_BRIDGE_LOCAL_TOKEN=... -- php artisan mcp:start capell-site` line. Run it from the site's project folder so `php artisan` resolves.

## What the agent can do

The key carries the scopes `capell.sites.read`, `capell.pages.read`, `capell.pages.write` and `capell.media.write`. Claude Code sees three tools:

| Tool                             | Use                                                           |
| -------------------------------- | ------------------------------------------------------------- |
| `capell-site-list-capabilities`  | Lists what this key may do on this site.                      |
| `capell-site-run-capability`     | Runs a search or inspection, or previews a draft change.      |
| `capell-site-confirm-capability` | Applies a previewed draft change with its confirmation token. |

A typical session: search for a page, inspect it, preview a draft update, review the preview, then confirm it. The page is saved as a draft for an editor to publish from the admin.

The public, read-only `/agent/v1` Agent Schema is separate. It needs no key and describes public content only.

## Options

| Option       | Effect                                                                                           |
| ------------ | ------------------------------------------------------------------------------------------------ |
| `--user=`    | Email address or ID of the user the agent acts as. Required when the command is not interactive. |
| `--client=`  | `claude-code` (default), `cursor` or `json`.                                                     |
| `--name=`    | Name shown for the key in the admin.                                                             |
| `--expires=` | Days until the key expires. Defaults to the configured lifetime.                                 |
| `--url=`     | Public site address, when it differs from `APP_URL`.                                             |
| `--local`    | Print the local stdio connection.                                                                |

## Revoke access

Open your user profile in the Capell admin and revoke the key in the Agent Bridge Tokens section. The agent loses access on its next request.

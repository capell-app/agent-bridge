# Connect Cursor

Connect Cursor to a Capell site so it can search pages, read a page's structure and draft changes. Every change is shown as a preview first and only happens when it is confirmed. The agent cannot publish.

The steps, scopes and tools are the same as [Connect Claude Code](connect-claude-code.md); only the configuration you paste differs.

## Create a key and print the configuration

```bash
php artisan capell:agent:connect --user=editor@example.com --client=cursor
```

The command prints a block for `.cursor/mcp.json` in your project, or `~/.cursor/mcp.json` for every project:

```json
{
    "mcpServers": {
        "capell": {
            "url": "https://your-site.example/agent-bridge/capell",
            "headers": {
                "Authorization": "Bearer cagent-bridge_..."
            }
        }
    }
}
```

The key is shown once. Merge the `capell` entry into any existing `mcpServers` block, then reload Cursor's MCP servers.

`.cursor/mcp.json` now holds a live key. Keep it out of version control, or use the user-level file.

## Local site

Add `--local` to print a stdio entry that runs `php artisan mcp:start capell-site` with the key in `CAPELL_AGENT_BRIDGE_LOCAL_TOKEN`. Open the site's project folder in Cursor so `php artisan` resolves.

## Other clients

`--client=json` prints the same `mcpServers` block for any client that reads that format.

## Revoke access

Open your user profile in the Capell admin and revoke the key in the Agent Bridge Tokens section.

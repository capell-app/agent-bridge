## What it does

Agent Bridge lets approved AI agents and external tools read or trigger site actions in a controlled, permissioned way.

## Do I need to do anything?

Usually no. It works behind the scenes once installed and configured. You will see its effect when a connected agent reads content or performs an allowed action.

## Where it shows up

It does not add a day-to-day screen. Its effect appears when connected agents interact with your site.

## Good to know

- This connects AI tools to your site, so leave its setup to someone who manages integrations.
- Only approved capabilities are exposed; ask your developer before connecting a new agent.
- Migration impact: run host migrations through the package install flow before opening package surfaces.
- Audit retention: `capell:agent-bridge-prune-audit` deletes audit entries older than `capell-agent-bridge.audit_retention_days` (90 days by default), and the package manifest schedules the command daily. The `--days` option overrides the configured window for a manual run.

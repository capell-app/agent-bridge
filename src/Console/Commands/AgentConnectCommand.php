<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Console\Commands;

use Capell\AgentBridge\Actions\BuildAgentConnectInstructionsAction;
use Capell\AgentBridge\Actions\CreateAgentBridgeTokenAction;
use Capell\AgentBridge\Health\AgentBridgeHealthCheck;
use Capell\AgentBridge\Support\AgentBridgeValue;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class AgentConnectCommand extends Command
{
    /**
     * Draft-only scopes: search and read sites and pages, draft pages and upload media.
     * Every write still goes through preview-then-confirm.
     */
    public const array DEFAULT_SCOPES = [
        'capell.sites.read',
        'capell.pages.read',
        'capell.pages.write',
        'capell.media.write',
    ];

    protected $signature = 'capell:agent:connect
        {--user= : Email address or ID of the admin user the agent acts as (required when not interactive)}
        {--client=claude-code : claude-code, cursor or json}
        {--name= : Name for the agent key}
        {--expires= : Days until the key expires}
        {--url= : Public site URL to connect to (defaults to APP_URL)}
        {--local : Print a local stdio connection instead of HTTP}';

    protected $description = 'Create a draft-only agent key and print the Claude Code or Cursor setup that connects it to this site.';

    public function handle(): int
    {
        $client = $this->option('client');
        $client = is_string($client) ? $client : '';

        if (! in_array($client, BuildAgentConnectInstructionsAction::clients(), true)) {
            $this->components->error(sprintf('Unknown client "%s". Use one of: %s.', $client, implode(', ', BuildAgentConnectInstructionsAction::clients())));

            return self::INVALID;
        }

        $connectivity = AgentBridgeHealthCheck::checkConnectivity();

        if (! $connectivity->passed) {
            $this->components->error($connectivity->message);
            $this->line((string) $connectivity->remediation);

            return self::FAILURE;
        }

        $local = (bool) $this->option('local');
        $serverUrl = $local ? '' : $this->serverUrl();

        if ($serverUrl === null) {
            return self::FAILURE;
        }

        $user = $this->resolveUser();

        if (! $user instanceof Authenticatable) {
            return self::FAILURE;
        }

        try {
            $created = CreateAgentBridgeTokenAction::run(
                $user,
                $this->tokenName($client),
                self::DEFAULT_SCOPES,
                $this->expiresAt(),
            );
        } catch (ValidationException $exception) {
            $this->components->error(implode(' ', $exception->validator->errors()->all()));

            return self::FAILURE;
        } catch (InvalidArgumentException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $instructions = BuildAgentConnectInstructionsAction::run(
            $client,
            $created['plainTextToken'],
            $serverUrl,
            $local,
            AgentBridgeValue::string(config('capell-agent-bridge.local.handle', 'capell-site'), 'Local Agent Bridge handle'),
        );

        $this->components->info(sprintf('Created agent key "%s" (%s), expiring %s.', $created['token']->name, $instructions->tokenPreview, $created['token']->expires_at?->toDateString()));
        $this->line('Scopes: ' . implode(', ', self::DEFAULT_SCOPES) . '. The agent can read and draft; every change needs a preview and your confirmation, and nothing is published.');
        $this->newLine();
        $this->line('Copy this now. The key is shown once and cannot be shown again:');
        $this->newLine();
        $this->line($instructions->snippet);
        $this->newLine();
        $this->line($instructions->revokeHint);

        return self::SUCCESS;
    }

    private function serverUrl(): ?string
    {
        $base = $this->option('url');
        $base = is_string($base) && $base !== '' ? $base : config('app.url');

        if (! is_string($base) || filter_var($base, FILTER_VALIDATE_URL) === false) {
            $this->components->error('Set APP_URL or pass --url with the public address of this site.');

            return null;
        }

        $url = rtrim($base, '/') . '/' . trim(AgentBridgeValue::string(config('capell-agent-bridge.routes.site'), 'Agent Bridge site route'), '/');

        if (app()->isProduction() && parse_url($url, PHP_URL_SCHEME) !== 'https') {
            $this->components->error(sprintf('Refusing to send an agent key to %s: production connections must use https.', $url));

            return null;
        }

        return $url;
    }

    private function resolveUser(): ?Authenticatable
    {
        $identifier = $this->option('user');

        if (! is_string($identifier) || $identifier === '') {
            if (! $this->input->isInteractive()) {
                $this->components->error('Pass --user with the email address or ID of the admin user the agent acts as.');

                return null;
            }

            $answer = $this->ask('Email address of the admin user the agent acts as');
            $identifier = is_string($answer) ? trim($answer) : '';
        }

        $modelClass = $this->userModel();

        if ($modelClass === null) {
            $this->components->error('The site auth guard has no Eloquent user model, so an agent key cannot be linked to a user.');

            return null;
        }

        $query = $modelClass::query();
        $user = str_contains($identifier, '@')
            ? $query->where('email', $identifier)->first()
            : (ctype_digit($identifier) ? $query->whereKey((int) $identifier)->first() : null);

        if (! $user instanceof Authenticatable) {
            $this->components->error(sprintf('No user matches "%s".', $identifier));

            return null;
        }

        return $user;
    }

    /**
     * @return class-string<Model>|null
     */
    private function userModel(): ?string
    {
        $guard = AgentBridgeValue::string(config('capell-agent-bridge.site_auth_guard', 'web'), 'Site auth guard');
        $provider = config(sprintf('auth.guards.%s.provider', $guard));
        $model = is_string($provider) ? config(sprintf('auth.providers.%s.model', $provider)) : null;

        return is_string($model) && is_subclass_of($model, Model::class) ? $model : null;
    }

    private function tokenName(string $client): string
    {
        $name = $this->option('name');

        return is_string($name) && trim($name) !== ''
            ? trim($name)
            : sprintf('%s (%s)', $client, CarbonImmutable::now()->toDateString());
    }

    private function expiresAt(): ?CarbonImmutable
    {
        $days = $this->option('expires');

        return is_string($days) && ctype_digit($days) ? CarbonImmutable::now()->addDays((int) $days) : null;
    }
}

<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Support;

use Capell\AgentBridge\Actions\Media\UploadMediaCapabilityAction;
use Capell\AgentBridge\Actions\Pages\CreateDraftPageCapabilityAction;
use Capell\AgentBridge\Actions\Pages\InspectPageCapabilityAction;
use Capell\AgentBridge\Actions\Pages\ListPageAuthoringOptionsCapabilityAction;
use Capell\AgentBridge\Actions\Pages\SearchPagesCapabilityAction;
use Capell\AgentBridge\Actions\Pages\UpdateDraftPageCapabilityAction;
use Capell\AgentBridge\Actions\Sites\InspectSiteCapabilityAction;
use Capell\AgentBridge\Actions\Sites\SearchSitesCapabilityAction;
use Capell\AgentBridge\Data\AuthenticatedAgentBridgeClientData;
use Capell\AgentBridge\Data\Capabilities\CreateDraftPageCapabilityInputData;
use Capell\AgentBridge\Data\Capabilities\PageAuthoringOptionsCapabilityInputData;
use Capell\AgentBridge\Data\Capabilities\PageReferenceCapabilityInputData;
use Capell\AgentBridge\Data\Capabilities\SearchPagesCapabilityInputData;
use Capell\AgentBridge\Data\Capabilities\SearchSitesCapabilityInputData;
use Capell\AgentBridge\Data\Capabilities\SiteReferenceCapabilityInputData;
use Capell\AgentBridge\Data\Capabilities\UpdateDraftPageCapabilityInputData;
use Capell\AgentBridge\Data\Capabilities\UploadMediaCapabilityInputData;
use Capell\AgentBridge\Data\CapabilityData;
use Capell\AgentBridge\Data\CapabilityResultData;
use Capell\AgentBridge\Enums\CapabilityRiskEnum;
use Capell\AgentBridge\Enums\CapabilityServerEnum;
use Capell\AgentBridge\Models\CapellAgentBridgeToken;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use JsonException;

final class AgentBridgeMcpV1Policy
{
    public const int MAX_PAYLOAD_BYTES = 2_900_000;

    public const int MAX_PAYLOAD_DEPTH = 12;

    public const int MAX_PAYLOAD_NODES = 2_000;

    public const int MAX_STRING_BYTES = 2_800_000;

    public const int MAX_KEY_BYTES = 128;

    public const int MAX_OUTPUT_BYTES = 262_144;

    public const int MAX_OUTPUT_STRING_BYTES = 16_384;

    /** @var list<string> */
    private const array PROHIBITED_INPUT_KEYS = [
        'admin',
        'adminurl',
        'editurl',
        'editorurl',
        'publish',
        'published',
        'publishingstate',
        'signedurl',
        'visiblefrom',
        'visibleuntil',
    ];

    public function allows(string $capabilityKey): bool
    {
        return array_key_exists($capabilityKey, $this->definitions());
    }

    /**
     * @param  Collection<int, CapabilityData>  $capabilities
     * @return Collection<int, CapabilityData>
     */
    public function allowedCapabilities(Collection $capabilities): Collection
    {
        return $capabilities
            ->filter(fn (CapabilityData $capability): bool => $this->isExactCapability($capability))
            ->values();
    }

    public function resolveCapability(
        CapellAgentBridgeCapabilityRegistry $registry,
        string $capabilityKey,
    ): CapabilityData {
        if (! $this->allows($capabilityKey)) {
            throw new AuthorizationException($this->deniedMessage());
        }

        try {
            $capability = $registry->get($capabilityKey);
        } catch (InvalidArgumentException) {
            throw new AuthorizationException($this->deniedMessage());
        }

        throw_unless($this->isExactCapability($capability), AuthorizationException::class, $this->deniedMessage());

        return $capability;
    }

    /** @return array<string, mixed> */
    public function capabilityPayload(CapabilityData $capability): array
    {
        throw_unless($this->isExactCapability($capability), AuthorizationException::class, $this->deniedMessage());

        return [
            'key' => $capability->key,
            'name' => $capability->name,
            'description' => $capability->description,
            'scope' => $capability->scope,
            'risk' => $capability->risk->value,
            'inputSchema' => $capability->inputSchema,
            'outputSchema' => $capability->outputSchema,
            'requiresConfirmation' => $capability->needsConfirmation(),
        ];
    }

    /** @param array<string, mixed> $payload */
    public function authorizeExecution(
        CapabilityData $capability,
        array $payload,
        AuthenticatedAgentBridgeClientData $client,
        CapellAgentBridgeToken $token,
        ?Authenticatable $actor,
    ): void {
        throw_unless($this->isExactCapability($capability), AuthorizationException::class, $this->deniedMessage());

        $currentToken = CapellAgentBridgeToken::query()->find($token->getKey());
        $requiredScope = $this->definitions()[$capability->key]['scope'];

        throw_unless(
            $currentToken instanceof CapellAgentBridgeToken
                && $currentToken->isUsable()
                && $currentToken->getKey() === $client->tokenId
                && $currentToken->canUseScope($requiredScope)
                && $client->can($requiredScope)
                && $actor instanceof Model
                && $this->tokenBelongsToActor($currentToken, $actor),
            AuthorizationException::class,
            $this->deniedMessage(),
        );

        $this->validatePayload($payload);
    }

    /** @param array<string, mixed> $payload */
    public function validatePayload(array $payload): void
    {
        try {
            $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException) {
            throw new InvalidArgumentException($this->invalidPayloadMessage());
        }

        throw_if(strlen($encoded) > self::MAX_PAYLOAD_BYTES, InvalidArgumentException::class, $this->invalidPayloadMessage());

        $nodes = 0;
        $this->validateValue($payload, 1, $nodes);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function projectOutput(CapabilityData $capability, array $payload): array
    {
        throw_unless($this->isExactCapability($capability), AuthorizationException::class, $this->deniedMessage());

        $mode = $this->string($payload['mode'] ?? null);
        $resultKey = $mode === 'preview' ? 'preview' : 'result';
        $result = is_array($payload[$resultKey] ?? null) ? $payload[$resultKey] : [];
        $projected = [
            'ok' => true,
            'mode' => $mode,
            'capability' => $capability->key,
            $resultKey => $this->projectCapabilityResult($capability->key, $result),
        ];

        if ($mode === 'preview') {
            $projected['confirmationToken'] = $this->string($payload['confirmationToken'] ?? null);
            $projected['expiresAt'] = $this->string($payload['expiresAt'] ?? null);
        }

        $this->validateOutput($projected);

        return $projected;
    }

    /** @param array<string, mixed> $payload */
    public function validateOutput(array $payload): void
    {
        try {
            $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException) {
            throw new InvalidArgumentException($this->invalidOutputMessage());
        }

        throw_if(strlen($encoded) > self::MAX_OUTPUT_BYTES, InvalidArgumentException::class, $this->invalidOutputMessage());
        $nodes = 0;
        $this->validateOutputValue($payload, 1, $nodes);
    }

    private function isExactCapability(CapabilityData $capability): bool
    {
        $definition = $this->definitions()[$capability->key] ?? null;

        return $definition !== null
            && $capability->server === CapabilityServerEnum::Site
            && $capability->scope === $definition['scope']
            && $capability->actionClass === $definition['action']
            && $capability->requiredPackage === $definition['required_package']
            && $capability->policyAbility === $definition['policy_ability']
            && $capability->inputDataClass === $definition['input_data_class']
            && $capability->outputDataClass === $definition['output_data_class']
            && $capability->risk === $definition['risk']
            && $capability->supportsPreview === $definition['supports_preview']
            && $capability->requiresConfirmation === $definition['requires_confirmation']
            && $capability->inputSchema === $definition['input_schema']
            && $capability->outputSchema === $definition['output_schema']
            && $capability->auditEvent === $definition['audit_event']
            && $capability->public === $definition['public'];
    }

    /**
     * @return array<string, array{
     *     scope: string,
     *     action: class-string,
     *     risk: CapabilityRiskEnum,
     *     supports_preview: bool,
     *     requires_confirmation: bool,
     *     input_schema: array<string, mixed>,
     *     output_schema: array<string, mixed>
     * }>
     */
    private function definitions(): array
    {
        $definition = static fn (string $scope, string $action, string $inputDataClass, array $inputSchema, string $auditEvent, CapabilityRiskEnum $risk, bool $requiresConfirmation): array => [
            'scope' => $scope,
            'action' => $action,
            'required_package' => 'capell-app/core',
            'policy_ability' => null,
            'input_data_class' => $inputDataClass,
            'output_data_class' => CapabilityResultData::class,
            'risk' => $risk,
            'supports_preview' => true,
            'requires_confirmation' => $requiresConfirmation,
            'input_schema' => $inputSchema,
            'output_schema' => CapabilitySchemas::capabilityResultOutput(),
            'audit_event' => $auditEvent,
            'public' => false,
        ];
        $read = static fn (string $scope, string $action, string $inputDataClass, array $inputSchema, string $auditEvent): array => $definition($scope, $action, $inputDataClass, $inputSchema, $auditEvent, CapabilityRiskEnum::Read, false);
        $write = static fn (string $scope, string $action, string $inputDataClass, array $inputSchema, string $auditEvent): array => $definition($scope, $action, $inputDataClass, $inputSchema, $auditEvent, CapabilityRiskEnum::High, true);

        return [
            'capell.sites.search' => $read('capell.sites.read', SearchSitesCapabilityAction::class, SearchSitesCapabilityInputData::class, CapabilitySchemas::searchSitesInput(), 'capell_agent-bridge.sites.search'),
            'capell.sites.inspect' => $read('capell.sites.read', InspectSiteCapabilityAction::class, SiteReferenceCapabilityInputData::class, CapabilitySchemas::siteReferenceInput(), 'capell_agent-bridge.sites.inspect'),
            'capell.pages.search' => $read('capell.pages.read', SearchPagesCapabilityAction::class, SearchPagesCapabilityInputData::class, CapabilitySchemas::searchPagesInput(), 'capell_agent-bridge.pages.search'),
            'capell.pages.inspect' => $read('capell.pages.read', InspectPageCapabilityAction::class, PageReferenceCapabilityInputData::class, CapabilitySchemas::pageReferenceInput(includeContent: true), 'capell_agent-bridge.pages.inspect'),
            'capell.pages.authoring_options' => $read('capell.pages.read', ListPageAuthoringOptionsCapabilityAction::class, PageAuthoringOptionsCapabilityInputData::class, CapabilitySchemas::pageAuthoringOptionsInput(), 'capell_agent-bridge.pages.authoring_options'),
            'capell.pages.create_draft' => $write('capell.pages.write', CreateDraftPageCapabilityAction::class, CreateDraftPageCapabilityInputData::class, CapabilitySchemas::createDraftPageInput(), 'capell_agent-bridge.pages.create_draft'),
            'capell.pages.update_draft' => $write('capell.pages.write', UpdateDraftPageCapabilityAction::class, UpdateDraftPageCapabilityInputData::class, CapabilitySchemas::updateDraftPageInput(), 'capell_agent-bridge.pages.update_draft'),
            'capell.media.upload' => $write('capell.media.write', UploadMediaCapabilityAction::class, UploadMediaCapabilityInputData::class, CapabilitySchemas::uploadMediaInput(), 'capell_agent-bridge.media.upload'),
        ];
    }

    private function tokenBelongsToActor(CapellAgentBridgeToken $token, Model $actor): bool
    {
        $actorIdentifier = $actor->getAuthIdentifier();

        return $token->user_type === $actor->getMorphClass()
            && (is_int($actorIdentifier) || is_string($actorIdentifier))
            && (string) $token->user_id === (string) $actorIdentifier;
    }

    private function validateValue(mixed $value, int $depth, int &$nodes): void
    {
        $nodes++;

        throw_if(
            $depth > self::MAX_PAYLOAD_DEPTH || $nodes > self::MAX_PAYLOAD_NODES,
            InvalidArgumentException::class,
            $this->invalidPayloadMessage(),
        );

        if (is_string($value)) {
            throw_if(strlen($value) > self::MAX_STRING_BYTES, InvalidArgumentException::class, $this->invalidPayloadMessage());

            return;
        }

        if (! is_array($value)) {
            throw_unless(
                $value === null || is_int($value) || is_float($value) || is_bool($value),
                InvalidArgumentException::class,
                $this->invalidPayloadMessage(),
            );

            return;
        }

        foreach ($value as $key => $child) {
            if (is_string($key)) {
                throw_if(
                    strlen($key) > self::MAX_KEY_BYTES || $this->isProhibitedInputKey($key),
                    InvalidArgumentException::class,
                    $this->invalidPayloadMessage(),
                );
            }

            $this->validateValue($child, $depth + 1, $nodes);
        }
    }

    private function isProhibitedInputKey(string $key): bool
    {
        $normalizedKey = strtolower(str_replace(['-', '_'], '', $key));

        return in_array($normalizedKey, self::PROHIBITED_INPUT_KEYS, true);
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function projectCapabilityResult(string $capabilityKey, array $result): array
    {
        $data = is_array($result['data'] ?? null) ? $result['data'] : [];

        return [
            'ok' => (bool) ($result['ok'] ?? false),
            'message' => $this->string($result['message'] ?? null),
            'data' => match ($capabilityKey) {
                'capell.sites.search' => [
                    'count' => (int) ($data['count'] ?? 0),
                    'sites' => $this->arrayItems($data['sites'] ?? null, fn (array $site): array => $this->site($site)),
                    'has_more' => (bool) ($data['has_more'] ?? false),
                    'next_cursor' => is_numeric($data['next_cursor'] ?? null) ? (int) $data['next_cursor'] : null,
                ],
                'capell.sites.inspect' => ['site' => $this->site($this->array($data['site'] ?? null))],
                'capell.pages.search' => [
                    'query' => $this->string($data['query'] ?? null),
                    'count' => (int) ($data['count'] ?? 0),
                    'matches' => $this->arrayItems($data['matches'] ?? null, fn (array $page): array => $this->page($page)),
                    'has_more' => (bool) ($data['has_more'] ?? false),
                    'next_cursor' => is_numeric($data['next_cursor'] ?? null) ? (int) $data['next_cursor'] : null,
                ],
                'capell.pages.inspect' => ['page' => $this->page($this->array($data['page'] ?? null))],
                'capell.pages.authoring_options' => $this->authoringOptions($data),
                'capell.pages.create_draft' => $this->createDraftResult($data),
                'capell.pages.update_draft' => $this->updateDraftResult($data),
                'capell.media.upload' => $this->mediaUploadResult($data),
                default => throw new AuthorizationException($this->deniedMessage()),
            },
            'warnings' => $this->stringItems($result['warnings'] ?? null),
        ];
    }

    /** @param array<string, mixed> $site @return array<string, mixed> */
    private function site(array $site): array
    {
        return [
            'id' => (int) ($site['id'] ?? 0),
            'name' => $this->string($site['name'] ?? null),
            'default' => (bool) ($site['default'] ?? false),
            'language' => $this->namedKey($site['language'] ?? null, includeCode: true),
            'theme' => $this->namedKey($site['theme'] ?? null),
            'domains' => $this->arrayItems($site['domains'] ?? null, fn (array $domain): array => [
                'domain' => $this->string($domain['domain'] ?? null),
                'url' => $this->publicUrl($domain['url'] ?? null),
                'language' => $this->string($domain['language'] ?? null),
            ]),
            'updated_at' => $this->string($site['updated_at'] ?? null),
        ];
    }

    /** @param array<string, mixed> $page @return array<string, mixed> */
    private function page(array $page): array
    {
        return [
            'id' => (int) ($page['id'] ?? 0),
            'name' => $this->string($page['name'] ?? null),
            'site' => $this->namedKey($page['site'] ?? null),
            'page_type' => $this->namedKey($page['page_type'] ?? null),
            'layout' => $this->namedKey($page['layout'] ?? null),
            'parent_id' => is_numeric($page['parent_id'] ?? null) ? (int) $page['parent_id'] : null,
            'publishing_state' => $this->string($page['publishing_state'] ?? null),
            'urls' => array_values(array_filter(array_map(fn (mixed $url): ?string => $this->publicUrl($url), $this->list($page['urls'] ?? null)))),
            'translations' => $this->arrayItems($page['translations'] ?? null, fn (array $translation): array => [
                'language' => $this->namedKey($translation['language'] ?? null, includeCode: true),
                'title' => $this->string($translation['title'] ?? null),
                'slug' => $this->string($translation['slug'] ?? null),
                'content' => $this->portableContent($translation['content'] ?? null),
            ]),
            'updated_at' => $this->string($page['updated_at'] ?? null),
        ];
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function authoringOptions(array $data): array
    {
        $option = fn (array $item): array => [
            'id' => (int) ($item['id'] ?? 0),
            'name' => $this->string($item['name'] ?? null),
            'key' => $this->string($item['key'] ?? null),
            'code' => $this->string($item['code'] ?? null),
            'default' => (bool) ($item['default'] ?? false),
        ];

        return [
            'site' => $this->namedKey($data['site'] ?? null),
            'languages' => $this->arrayItems($data['languages'] ?? null, $option),
            'page_types' => $this->arrayItems($data['page_types'] ?? null, $option),
            'layouts' => $this->arrayItems($data['layouts'] ?? null, $option),
        ];
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function createDraftResult(array $data): array
    {
        return [
            'page_id' => is_numeric($data['page_id'] ?? null) ? (int) $data['page_id'] : null,
            'name' => $this->string($data['name'] ?? null),
            'title' => $this->string($data['title'] ?? null),
            'slug' => $this->string($data['slug'] ?? null),
            'publishing_state' => $this->string($data['publishing_state'] ?? null),
            'urls' => array_values(array_filter(array_map(fn (mixed $url): ?string => $this->publicUrl($url), $this->list($data['urls'] ?? null)))),
            'resolved' => [
                'site' => $this->namedKey(data_get($data, 'resolved.site')),
                'page_type' => $this->namedKey(data_get($data, 'resolved.page_type')),
                'layout' => $this->namedKey(data_get($data, 'resolved.layout')),
                'language' => $this->namedKey(data_get($data, 'resolved.language'), includeCode: true),
                'publishing_state' => $this->string(data_get($data, 'resolved.publishing_state')),
            ],
        ];
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function updateDraftResult(array $data): array
    {
        return [
            'page_id' => is_numeric($data['page_id'] ?? null) ? (int) $data['page_id'] : null,
            'name' => $this->string($data['name'] ?? null),
            'revision' => is_numeric($data['revision'] ?? null) ? (int) $data['revision'] : null,
            'changed_fields' => $this->stringItems($data['changed_fields'] ?? $data['updated'] ?? null),
        ];
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function mediaUploadResult(array $data): array
    {
        return [
            'media_id' => is_numeric($data['media_id'] ?? null) ? (int) $data['media_id'] : null,
            'site_id' => is_numeric($data['site_id'] ?? null) ? (int) $data['site_id'] : null,
            'file_name' => $this->string($data['file_name'] ?? null),
            'mime_type' => $this->string($data['mime_type'] ?? null),
            'size' => (int) ($data['size'] ?? 0),
            'sha256' => $this->string($data['sha256'] ?? null),
            'replayed' => (bool) ($data['replayed'] ?? false),
        ];
    }

    /** @return array<string, mixed>|null */
    private function namedKey(mixed $value, bool $includeCode = false): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $projected = [
            'id' => is_numeric($value['id'] ?? null) ? (int) $value['id'] : null,
            'name' => $this->string($value['name'] ?? null),
            'key' => $this->string($value['key'] ?? null),
        ];

        if ($includeCode) {
            $projected['code'] = $this->string($value['code'] ?? null);
        }

        return $projected;
    }

    /** @param callable(array<string, mixed>): array<string, mixed> $projector @return list<array<string, mixed>> */
    private function arrayItems(mixed $value, callable $projector): array
    {
        return array_values(array_map($projector, array_slice(array_filter($this->list($value), 'is_array'), 0, 50)));
    }

    /** @return list<mixed> */
    private function list(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }

    /** @return array<string, mixed> */
    private function array(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /** @return list<string> */
    private function stringItems(mixed $value): array
    {
        return array_values(array_slice(array_filter($this->list($value), 'is_string'), 0, 50));
    }

    private function string(mixed $value): string
    {
        return is_string($value) ? mb_strcut($value, 0, self::MAX_OUTPUT_STRING_BYTES) : '';
    }

    private function publicUrl(mixed $value): ?string
    {
        if (! is_string($value) || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $parts = parse_url($value);

        if (! is_array($parts) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }

        $path = strtolower((string) ($parts['path'] ?? '/'));

        return str_starts_with($path, '/admin')
            || str_starts_with($path, '/filament')
            || str_starts_with($path, '/editor')
            || str_contains($path, '/edit') ? null : $value;
    }

    /** @return array{format: 'plain_text', text: string}|null */
    private function portableContent(mixed $content): ?array
    {
        $fragments = [];
        $this->collectPortableText($content, $fragments, 0);
        $text = trim(implode("\n", array_filter($fragments)));

        return $text === '' ? null : [
            'format' => 'plain_text',
            'text' => mb_strcut($text, 0, self::MAX_OUTPUT_STRING_BYTES),
        ];
    }

    /** @param list<string> $fragments */
    private function collectPortableText(mixed $value, array &$fragments, int $depth): void
    {
        if ($depth > self::MAX_PAYLOAD_DEPTH) {
            return;
        }

        if (is_string($value)) {
            $withoutExecutableMarkup = preg_replace('~<(script|style)\b[^>]*>.*?</\1>~is', '', $value);
            $text = html_entity_decode(strip_tags(is_string($withoutExecutableMarkup) ? $withoutExecutableMarkup : ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = preg_replace([
                '~(?:https?://[^\s]+|/[^\s]+)\?(?:[^\s]*(?:signature|expires|token|signed)[^\s]*)~i',
                '~(?:^|\s)/(?:admin|filament|editor)(?:/[^\s]*)?~i',
                '~\b(?:data-[\w:-]+|wire:[\w.-]+|x-[\w:-]+|contenteditable|data-capell-[\w:-]+)\s*=\s*[^\s>]+~i',
                '~\b(?:[A-Z][A-Za-z0-9_]+\\\\){2,}[A-Za-z0-9_]+\b~',
                '~\b(?:capell-app|vendor|packages?)/[a-z0-9_.-]+\b~i',
            ], '', $text);
            $text = preg_replace('/\s+/u', ' ', is_string($text) ? $text : '');

            if (is_string($text) && trim($text) !== '') {
                $fragments[] = trim($text);
            }

            return;
        }

        if (! is_array($value)) {
            return;
        }

        foreach ($value as $key => $child) {
            if (is_string($key) && preg_match('/(?:admin|editor|selector|attribute|permission|package|class|implementation|signed|signature|token|field[_-]?path)/i', $key) === 1) {
                continue;
            }

            $this->collectPortableText($child, $fragments, $depth + 1);
        }
    }

    private function validateOutputValue(mixed $value, int $depth, int &$nodes): void
    {
        $nodes++;
        throw_if($depth > self::MAX_PAYLOAD_DEPTH || $nodes > self::MAX_PAYLOAD_NODES, InvalidArgumentException::class, $this->invalidOutputMessage());

        if (is_string($value)) {
            throw_if(strlen($value) > self::MAX_OUTPUT_STRING_BYTES, InvalidArgumentException::class, $this->invalidOutputMessage());
        }

        if (is_array($value)) {
            foreach ($value as $child) {
                $this->validateOutputValue($child, $depth + 1, $nodes);
            }
        }
    }

    private function deniedMessage(): string
    {
        return AgentBridgeValue::string(
            __('capell-agent-bridge::messages.mcp_v1.denied'),
            'MCP v1 denial message',
        );
    }

    private function invalidPayloadMessage(): string
    {
        return AgentBridgeValue::string(
            __('capell-agent-bridge::messages.mcp_v1.invalid_payload'),
            'MCP v1 invalid payload message',
        );
    }

    private function invalidOutputMessage(): string
    {
        return AgentBridgeValue::string(
            __('capell-agent-bridge::messages.mcp_v1.invalid_output'),
            'MCP v1 invalid output message',
        );
    }
}
